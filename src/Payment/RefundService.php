<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Log;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

/**
 * @phpstan-import-type Payment from \Icepay\WooCommerce\Api\ResponseValidator
 * @phpstan-import-type Refund from \Icepay\WooCommerce\Api\ResponseValidator
 * @phpstan-type RefundArgs array{amount: int|float|string, reason: string|null, line_items: array<int, array{qty: int|float|string, refund_total: int|float|string, refund_tax: array<array-key, int|float|string>}>, restock_items: bool}
 * @phpstan-type Entry array{payment: string, merchant: string, amount: int, currency: string, reference: string, refund_id: int, args: RefundArgs, state: 'uncertain'|'accepted'|'rejected', created: int, key?: string, status?: 'pending'|'completed'}
 */
class RefundService {
	public function __construct( protected IcepayClient $client, protected Log $log = new Log() ) {}

	/** Capture WooCommerce's operation identity and accounting details before its API call. */
	/** @param array<string, mixed> $args */
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

	public static function preventDelete( mixed $check, WC_Order_Refund $refund ): mixed {
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order || ! PaymentAttempts::isIcepay( $order ) || ! $order->get_date_paid() ) {
			return $check;
		}
		// Allow wc_create_refund() to roll back a prepared API refund when the gateway call fails.
		$reference = $refund->get_meta( '_icepay_refund_reference' );
		if ( is_string( $reference ) && $reference !== '' && ! $refund->get_refunded_payment() ) {
			try {
				$history = self::history( $order );
				if ( ( $history[ $reference ]['state'] ?? null ) !== 'accepted' ) {
					return $check;
				}
			} catch ( \Throwable ) {
				return false;
			}
		}
		return false;
	}

	public static function hideDeleteAction( int $orderId ): void {
		$order = wc_get_order( $orderId );
		if ( $order && PaymentAttempts::isIcepay( $order ) && $order->get_date_paid() ) {
			echo '<style>#order_refunds .delete_refund { display: none !important; }</style>';
		}
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
		$locals = self::localRefunds( $id );
		$candidates = array_filter( $locals, static fn( $refund ) =>
			! $refund->get_refunded_payment() && $refund->get_meta( '_icepay_refund_reference' )
			&& (int) round( (float) $refund->get_amount() * 100 ) === $value && $refund->get_reason() === $reason );
		if ( count( $candidates ) !== 1 ) {
			return new WP_Error( 'refund_identity', __( 'Create the refund through WooCommerce so its operation can be tracked safely.', 'icepay-for-woocommerce' ) );
		}
		$local = reset( $candidates );
		$args = $local->get_meta( '_icepay_refund_args' );
		if ( ! self::validArgs( $args ) ) {
			return new WP_Error( 'refund_identity', 'Invalid stored refund accounting details.' );
		}
		$reference = $local->get_meta( '_icepay_refund_reference' );
		if ( isset( $history[ $reference ] ) && $history[ $reference ]['state'] === 'accepted' ) {
			return true;
		}
		[ $success, $payment ] = $this->client->get( $key );
		if ( ! $success || ( $payment['key'] ?? '' ) !== $key || ( $payment['status'] ?? '' ) !== 'completed'
			|| (float) $payment['amount']['value'] !== (float) ( $attempt['amount'] ?? round( (float) $order->get_total() * 100 ) )
			|| ( isset( $attempt['currency'] ) && strtoupper( $attempt['currency'] ) !== strtoupper( $order->get_currency() ) )
			|| strtoupper( $payment['amount']['currency'] ) !== strtoupper( $order->get_currency() )
			|| ( isset( $payment['merchantId'] ) && (string) $payment['merchantId'] !== Icepay::getMerchantId() )
			|| ( isset( $payment['meta']['woocommerce']['order_id'] ) && (string) $payment['meta']['woocommerce']['order_id'] !== (string) $id )
			|| ( isset( $attempt['isTest'] ) && ( $payment['isTest'] ?? null ) !== $attempt['isTest'] ) ) {
			return new WP_Error( 'invalid_payment', __( 'Unable to verify the original payment before refunding.', 'icepay-for-woocommerce' ) );
		}
		$remoteTotal = 0;
		foreach ( $payment['refunds'] ?? [] as $refund ) {
			if ( strtoupper( $refund['amount']['currency'] ) !== strtoupper( $order->get_currency() ) ) {
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
			'args' => $args, 'state' => 'uncertain', 'created' => time() ];
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
	/** @param Payment $payment */
	public static function reconcileLocked( WC_Order $order, array $payment ): void {
		$history = self::history( $order );
		foreach ( $history as $reference => $entry ) {
			if ( $entry['payment'] !== $payment['key'] || $entry['merchant'] !== Icepay::getMerchantId() || $entry['state'] === 'rejected' ) {
				continue;
			}
			$matches = array_filter( $payment['refunds'] ?? [], static fn( $refund ) => self::matches( $refund, $entry ) );
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

	/** @param Refund $refund
	 * @param Entry $entry
	 */
	private static function matches( array $refund, array $entry ): bool {
		return $refund['reference'] === $entry['reference']
			&& $refund['amount']['value'] === $entry['amount']
			&& strtoupper( $refund['amount']['currency'] ) === strtoupper( $entry['currency'] )
			&& ( ! isset( $refund['payment']['key'] ) || $refund['payment']['key'] === $entry['payment'] );
	}

	private static function findLocal( WC_Order $order, string $reference ): ?WC_Order_Refund {
		foreach ( self::localRefunds( $order->get_id() ) as $refund ) {
			if ( $refund->get_meta( '_icepay_refund_reference' ) === $reference ) {
				return $refund;
			}
		}
		return null;
	}

	/** @param Entry $entry */
	private static function accounted( WC_Order $order, array $entry ): bool {
		$local = self::findLocal( $order, $entry['reference'] );
		return $local && $local->get_refunded_payment()
			&& (int) round( (float) $local->get_amount() * 100 ) === $entry['amount']
			&& strtoupper( $local->get_currency() ) === strtoupper( $entry['currency'] );
	}

	/** @param Refund $refund */
	private static function tag( WC_Order_Refund $local, array $refund ): void {
		$local->update_meta_data( '_icepay_refund_key', $refund['key'] );
		$local->update_meta_data( '_icepay_refund_status', $refund['status'] );
		$local->save();
	}

	/** @return array<string, Entry> */
	private static function history( WC_Order $order ): array {
		$history = $order->get_meta( '_icepay_refunds' );
		if ( $history === '' ) { return []; }
		if ( ! is_array( $history ) ) { throw new \UnexpectedValueException( 'Invalid stored refund history.' ); }
		foreach ( $history as $reference => $entry ) {
			if ( ! is_string( $reference ) || ! self::validEntry( $entry ) || $reference !== $entry['reference'] ) {
				throw new \UnexpectedValueException( 'Invalid stored refund attempt.' );
			}
		}
		return $history;
	}

	/** @phpstan-assert-if-true Entry $entry */
	private static function validEntry( mixed $entry ): bool {
		if ( ! is_array( $entry ) ) { return false; }
		foreach ( [ 'payment', 'merchant', 'currency', 'reference' ] as $field ) {
			if ( ! is_string( $entry[ $field ] ?? null ) ) { return false; }
		}
		foreach ( [ 'amount', 'refund_id', 'created' ] as $field ) {
			if ( ! is_int( $entry[ $field ] ?? null ) || $entry[ $field ] < 0 ) { return false; }
		}
		if ( ! in_array( $entry['state'] ?? null, [ 'uncertain', 'accepted', 'rejected' ], true )
			|| ( array_key_exists( 'key', $entry ) && ! is_string( $entry['key'] ) )
			|| ( array_key_exists( 'status', $entry ) && ! in_array( $entry['status'], [ 'pending', 'completed' ], true ) ) ) { return false; }
		return self::validArgs( $entry['args'] ?? null );
	}

	/** @phpstan-assert-if-true RefundArgs $args */
	private static function validArgs( mixed $args ): bool {
		if ( ! is_array( $args ) || ! is_numeric( $args['amount'] ?? null )
			|| ! array_key_exists( 'reason', $args ) || ( $args['reason'] !== null && ! is_string( $args['reason'] ) )
			|| ! is_bool( $args['restock_items'] ?? null ) || ! is_array( $args['line_items'] ?? null ) ) { return false; }
		foreach ( $args['line_items'] as $id => $item ) {
			if ( ! is_int( $id ) || ! is_array( $item ) || ! is_numeric( $item['qty'] ?? null )
				|| ! is_numeric( $item['refund_total'] ?? null ) || ! is_array( $item['refund_tax'] ?? null ) ) { return false; }
			foreach ( $item['refund_tax'] as $tax ) {
				if ( ! is_numeric( $tax ) ) { return false; }
			}
		}
		return true;
	}

	/** @param array<string, Entry> $history */
	private static function save( WC_Order $order, array $history ): void {
		$order->update_meta_data( '_icepay_refunds', $history );
		$order->save_meta_data();
	}

	private static function uncertainError(): WP_Error {
		return new WP_Error( 'refund_uncertain', __( 'A refund could not be confirmed. Check the ICEPAY Portal and reconcile it before issuing another refund.', 'icepay-for-woocommerce' ) );
	}

	/** @return list<WC_Order_Refund> */
	private static function localRefunds( int $id ): array {
		$orders = wc_get_orders( [ 'type' => 'shop_order_refund', 'parent' => $id, 'limit' => -1 ] );
		if ( ! is_array( $orders ) ) { throw new \UnexpectedValueException( 'Unexpected refund query result.' ); }
		return array_values( array_filter( $orders,
			static fn( $order ) => $order instanceof WC_Order_Refund ) );
	}
}
