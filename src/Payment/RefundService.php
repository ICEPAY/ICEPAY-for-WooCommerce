<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Log;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

class RefundService {
	public function __construct( protected IcepayClient $client, protected Log $log = new Log() ) {}

	/** Capture WooCommerce's operation identity and accounting details before its API call. */
	public static function prepare( WC_Order_Refund $refund, array $args ): void {
		$order = wc_get_order( $refund->get_parent_id() );
		if ( empty( $args['refund_payment'] ) || ! $order || ! PaymentAttempts::isIcepay( $order ) ) {
			return;
		}
		if ( ! $refund->get_meta( '_icepay_refund_reference' ) ) {
			$refund->update_meta_data( '_icepay_refund_reference', 'wc-' . wp_generate_uuid4() );
		}
		$refund->update_meta_data( '_icepay_refund_args', [
			'amount' => $args['amount'], 'reason' => $args['reason'],
			'line_items' => $args['line_items'], 'restock_items' => $args['restock_items'],
		] );
	}

	public function process( int $id, mixed $amount, string $reason ): bool|WP_Error {
		if ( ! OrderLock::acquire( $id ) ) {
			return new WP_Error( 'busy', __( 'This order is being updated. Please wait before refunding again.', 'icepay-for-woocommerce' ) );
		}
		try {
			return $this->processLocked( $id, $amount, $reason );
		} catch ( \Throwable $exception ) {
			$this->log->error( 'Refund processing failed.', [ 'order' => $id, 'message' => $exception->getMessage() ] );
			return self::uncertainError();
		} finally {
			OrderLock::release( $id );
		}
	}

	private function processLocked( int $id, mixed $amount, string $reason ): bool|WP_Error {
		$order = wc_get_order( $id );
		if ( ! $order || ! PaymentAttempts::isIcepay( $order ) || ! $order->get_date_paid() ) {
			return new WP_Error( 'invalid_order', __( 'Only paid ICEPAY orders can be refunded through ICEPAY.', 'icepay-for-woocommerce' ) );
		}
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount <= 0
			|| abs( (float) $amount * 100 - round( (float) $amount * 100 ) ) > 0.000001 ) {
			return new WP_Error( 'invalid_amount', __( 'Enter a positive refund amount with at most two decimal places.', 'icepay-for-woocommerce' ) );
		}
		$value = (int) round( (float) $amount * 100 );
		$key = $order->get_transaction_id();
		$attempt = PaymentAttempts::getAttempts( $order )[ $key ] ?? null;
		if ( ! $attempt || ( isset( $attempt['merchant'] ) && $attempt['merchant'] !== Icepay::getMerchantId() ) ) {
			return new WP_Error( 'invalid_payment', __( 'The original ICEPAY payment could not be verified.', 'icepay-for-woocommerce' ) );
		}
		$history = self::history( $order );
		foreach ( $history as $entry ) {
			if ( $entry['state'] === 'uncertain' || ( $entry['state'] === 'accepted' && ! self::accounted( $order, $entry ) ) ) {
				return self::uncertainError();
			}
		}
		// The gateway signature has no refund ID. Require one unambiguous prepared operation.
		$locals = wc_get_orders( [ 'type' => 'shop_order_refund', 'parent' => $id, 'limit' => -1 ] );
		$candidates = array_filter( $locals, static fn( $refund ) =>
			! $refund->get_refunded_payment() && $refund->get_meta( '_icepay_refund_reference' )
			&& (int) round( (float) $refund->get_amount() * 100 ) === $value && $refund->get_reason() === $reason );
		if ( count( $candidates ) !== 1 ) {
			return new WP_Error( 'refund_identity', __( 'Create the refund through WooCommerce so its operation can be tracked safely.', 'icepay-for-woocommerce' ) );
		}
		$local = reset( $candidates );
		$reference = $local->get_meta( '_icepay_refund_reference' );
		if ( isset( $history[ $reference ] ) && $history[ $reference ]['state'] === 'accepted' ) {
			return true;
		}
		[ $success, $payment ] = $this->client->get( $key );
		if ( ! $success || ( $payment['key'] ?? '' ) !== $key || ( $payment['status'] ?? '' ) !== 'completed'
			|| ! is_numeric( $payment['amount']['value'] ?? null )
			|| (float) $payment['amount']['value'] !== (float) ( $attempt['amount'] ?? round( (float) $order->get_total() * 100 ) )
			|| ( isset( $attempt['currency'] ) && strtoupper( $attempt['currency'] ) !== strtoupper( $order->get_currency() ) )
			|| strtoupper( (string) ( $payment['amount']['currency'] ?? '' ) ) !== strtoupper( $order->get_currency() )
			|| ( isset( $payment['merchantId'] ) && (string) $payment['merchantId'] !== Icepay::getMerchantId() )
			|| ( isset( $payment['meta']['woocommerce']['order_id'] ) && (string) $payment['meta']['woocommerce']['order_id'] !== (string) $id )
			|| ( isset( $attempt['isTest'] ) && ( $payment['isTest'] ?? null ) !== $attempt['isTest'] ) ) {
			return new WP_Error( 'invalid_payment', __( 'Unable to verify the original payment before refunding.', 'icepay-for-woocommerce' ) );
		}
		$remoteTotal = 0;
		if ( isset( $payment['refunds'] ) && ! is_array( $payment['refunds'] ) ) {
			return new WP_Error( 'invalid_refunds', __( 'Review the existing refunds in ICEPAY before refunding again.', 'icepay-for-woocommerce' ) );
		}
		foreach ( $payment['refunds'] ?? [] as $refund ) {
			if ( ! is_array( $refund ) || ! is_numeric( $refund['amount']['value'] ?? null )
				|| (float) $refund['amount']['value'] < 0 || (float) $refund['amount']['value'] !== floor( (float) $refund['amount']['value'] )
				|| strtoupper( (string) ( $refund['amount']['currency'] ?? '' ) ) !== strtoupper( $order->get_currency() )
				|| ! in_array( $refund['status'] ?? '', [ 'pending', 'completed' ], true ) ) {
				return new WP_Error( 'invalid_refunds', __( 'Review the existing refunds in ICEPAY before refunding again.', 'icepay-for-woocommerce' ) );
			}
			$remoteTotal += (int) $refund['amount']['value'];
		}
		$localTotal = array_sum( array_map( static fn( $refund ) => (int) round( (float) $refund->get_amount() * 100 ), $locals ) );
		if ( $value > (int) $payment['amount']['value'] - $remoteTotal
			|| $localTotal > (int) round( (float) $order->get_total() * 100 ) ) {
			return new WP_Error( 'invalid_amount', __( 'The refund exceeds the remaining refundable amount.', 'icepay-for-woocommerce' ) );
		}
		$entry = [ 'payment' => $key, 'merchant' => Icepay::getMerchantId(), 'amount' => $value,
			'currency' => $order->get_currency(), 'reference' => $reference, 'refund_id' => $local->get_id(),
			'args' => $local->get_meta( '_icepay_refund_args' ), 'state' => 'uncertain', 'created' => time() ];
		$history[ $reference ] = $entry;
		self::save( $order, $history );
		[ $success, $refund ] = $this->client->refund( $key, [
			'amount' => [ 'value' => $value ], 'reference' => $reference, 'description' => $reason,
		] );
		if ( ! $success && empty( $refund['uncertain'] ) ) {
			$history[ $reference ]['state'] = 'rejected';
			self::save( $order, $history );
			return new WP_Error( 'refund_rejected', __( 'ICEPAY rejected the refund. Please check the payment and try again.', 'icepay-for-woocommerce' ) );
		}
		if ( ! $success || ! self::matches( $refund, $entry ) ) {
			$order->add_order_note( sprintf( /* translators: %s: refund reference. */ __( 'ICEPAY refund %s has an unknown outcome. Further API refunds are blocked until reconciled with ICEPAY.', 'icepay-for-woocommerce' ), $reference ) );
			return self::uncertainError();
		}
		$history[ $reference ] = array_merge( $entry, [ 'state' => 'accepted', 'key' => $refund['key'], 'status' => $refund['status'] ] );
		self::save( $order, $history );
		self::tag( $local, $refund );
		return true;
	}

	/** Called only under the order lock, after the payment itself has been validated. */
	public static function reconcileLocked( WC_Order $order, array $payment ): void {
		if ( ! is_array( $payment['refunds'] ?? [] ) ) {
			return;
		}
		$history = self::history( $order );
		foreach ( $history as $reference => $entry ) {
			if ( $entry['payment'] !== $payment['key'] || $entry['merchant'] !== Icepay::getMerchantId() || $entry['state'] === 'rejected' ) {
				continue;
			}
			$matches = array_filter( $payment['refunds'] ?? [], static fn( $refund ) => is_array( $refund ) && self::matches( $refund, $entry ) );
			if ( count( $matches ) !== 1 ) {
				continue;
			}
			$refund = reset( $matches );
			if ( isset( $entry['key'] ) && $entry['key'] !== $refund['key'] ) {
				continue;
			}
			$entry = array_merge( $entry, [ 'state' => 'accepted', 'key' => $refund['key'],
				'status' => ( $entry['status'] ?? '' ) === 'completed' ? 'completed' : $refund['status'] ] );
			$history[ $reference ] = $entry;
			self::save( $order, $history );
			$local = self::findLocal( $order, $reference );
			if ( $local && ( (int) round( (float) $local->get_amount() * 100 ) !== $entry['amount']
				|| strtoupper( $local->get_currency() ) !== strtoupper( $entry['currency'] ) ) ) {
				continue;
			}
			if ( ! $local ) {
				// Tag before save so a crash cannot cause a second local refund on replay.
				$tag = static function ( $local ) use ( $reference, $refund, $order ): void {
					if ( $local->get_parent_id() !== $order->get_id() ) { return; }
					$local->update_meta_data( '_icepay_refund_reference', $reference );
					$local->update_meta_data( '_icepay_refund_key', $refund['key'] );
					$local->set_refunded_payment( true );
				};
				add_action( 'woocommerce_create_refund', $tag );
				try {
					$local = wc_create_refund( array_merge( $entry['args'], [ 'order_id' => $order->get_id(), 'refund_payment' => false ] ) );
				} finally {
					remove_action( 'woocommerce_create_refund', $tag );
				}
				if ( is_wp_error( $local ) ) {
					continue;
				}
				$order->add_order_note( sprintf( /* translators: %s: refund key. */ __( 'Recovered ICEPAY refund %s without sending another refund request.', 'icepay-for-woocommerce' ), $refund['key'] ) );
			}
			$local->set_refunded_payment( true );
			self::tag( $local, array_merge( $refund, [ 'status' => $entry['status'] ] ) );
			$history[ $reference ]['refund_id'] = $local->get_id();
			self::save( $order, $history );
		}
	}

	private static function matches( array $refund, array $entry ): bool {
		return is_string( $refund['key'] ?? null ) && $refund['key'] !== ''
			&& ( $refund['reference'] ?? '' ) === $entry['reference']
			&& in_array( $refund['status'] ?? '', [ 'pending', 'completed' ], true )
			&& is_numeric( $refund['amount']['value'] ?? null ) && (float) $refund['amount']['value'] === (float) $entry['amount']
			&& strtoupper( (string) ( $refund['amount']['currency'] ?? '' ) ) === strtoupper( $entry['currency'] )
			&& ( ! isset( $refund['payment']['key'] ) || $refund['payment']['key'] === $entry['payment'] );
	}

	private static function findLocal( WC_Order $order, string $reference ): ?WC_Order_Refund {
		foreach ( wc_get_orders( [ 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1 ] ) as $refund ) {
			if ( $refund->get_meta( '_icepay_refund_reference' ) === $reference ) {
				return $refund;
			}
		}
		return null;
	}

	private static function accounted( WC_Order $order, array $entry ): bool {
		$local = self::findLocal( $order, $entry['reference'] );
		return $local && $local->get_refunded_payment()
			&& (int) round( (float) $local->get_amount() * 100 ) === $entry['amount']
			&& strtoupper( $local->get_currency() ) === strtoupper( $entry['currency'] );
	}

	private static function tag( WC_Order_Refund $local, array $refund ): void {
		$local->update_meta_data( '_icepay_refund_key', $refund['key'] );
		$local->update_meta_data( '_icepay_refund_status', $refund['status'] );
		$local->save();
	}

	private static function history( WC_Order $order ): array {
		$history = $order->get_meta( '_icepay_refunds' );
		return is_array( $history ) ? $history : [];
	}

	private static function save( WC_Order $order, array $history ): void {
		$order->update_meta_data( '_icepay_refunds', $history );
		$order->save_meta_data();
	}

	private static function uncertainError(): WP_Error {
		return new WP_Error( 'refund_uncertain', __( 'A refund could not be confirmed. Check the ICEPAY Portal and reconcile it before issuing another refund.', 'icepay-for-woocommerce' ) );
	}
}
