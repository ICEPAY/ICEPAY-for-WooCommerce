<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\PaymentMethod;
use Icepay\WooCommerce\Payment\PaymentAttempts as Order;
use WC_Order;
use WP_Error;

/** @phpstan-import-type Payment from \Icepay\WooCommerce\Api\ResponseValidator */
class PaymentStatusHandler {
	protected IcepayClient $client;

	public function __construct( ?IcepayClient $client = null ) {
		$this->client = $client ?? IcepayClient::fromSettings();
	}

	/** @return Payment|WP_Error */
	public function refresh( WC_Order $order, string $key ): array|WP_Error {
		$id = $order->get_id();
		if ( ! OrderLock::acquire( $id ) ) {
			return new WP_Error( 'busy', 'Payment is being updated.' );
		}
		try {
			$order = wc_get_order( $id );
			if ( ! $order ) { return new WP_Error( 'missing_order', 'Order no longer exists.' ); }
			[ $success, $payment ] = $this->client->get( $key );
			if ( ! $success || ( $payment['key'] ?? '' ) !== $key ) {
				return new WP_Error( 'unavailable', 'Unable to retrieve payment state.' );
			}
			if ( ! isset( Order::getAttempts( $order )[ $key ] ) ) {
				$creation = $order->get_meta( '_icepay_creation_uncertain' );
				// Recover a lost create response only using the persisted, unguessable
				// attempt token echoed by the authenticated API, never order ID alone.
				if ( ! is_array( $creation ) || ! is_string( $payment['meta']['woocommerce']['creation_token'] ?? null )
					|| ! hash_equals( $creation['token'], $payment['meta']['woocommerce']['creation_token'] )
					|| $creation['merchant'] !== Icepay::getMerchantId()
					|| (string) ( $payment['meta']['woocommerce']['order_id'] ?? '' ) !== (string) $id
					|| $payment['amount']['value'] !== $creation['amount']
					|| strtoupper( $payment['amount']['currency'] ) !== strtoupper( $creation['currency'] )
					|| (int) round( (float) $order->get_total() * 100 ) !== $creation['amount']
					|| strtoupper( $order->get_currency() ) !== strtoupper( $creation['currency'] ) ) {
					return new WP_Error( 'unknown_payment', 'Payment does not match the interrupted creation attempt.' );
				}
				Order::addAttempt( $order, $payment, $creation['method'], $creation['token'] );
			}
			$result = $this->applyLocked( $order, $payment );
			return is_wp_error( $result ) ? $result : $payment;
		} finally {
			OrderLock::release( $id );
		}
	}

	/** @param array<string, mixed> $payment */
	public function apply( WC_Order $order, array $payment ): bool|WP_Error {
		$id = $order->get_id();
		if ( ! OrderLock::acquire( $id ) ) {
			return new WP_Error( 'busy', 'Payment is being updated.' );
		}
		try {
			$order = wc_get_order( $id );
			if ( ! $order ) { return new WP_Error( 'missing_order', 'Order no longer exists.' ); }
			return $this->applyLocked( $order, $payment );
		} finally {
			OrderLock::release( $id );
		}
	}


	/** @param array<string, mixed> $payment */
	public function applyLocked( WC_Order $order, array $payment ): bool|WP_Error {
		$payment = \Icepay\WooCommerce\Api\ResponseValidator::normalizePayment( $payment );
		if ( ! \Icepay\WooCommerce\Api\ResponseValidator::payment( $payment ) ) {
			return new WP_Error( 'invalid_payment', 'Invalid payment response.' );
		}
		$attempts = Order::getAttempts( $order );
		$key = $payment['key'];
		$status = $payment['status'];
		if ( ! isset( $attempts[ $key ] ) ) {
			return new WP_Error( 'unknown_payment', 'Payment key is not attached to this order.' );
		}
		$attempt = $attempts[ $key ];
		if ( (float) $payment['amount']['value'] !== (float) ( $attempt['amount'] ?? round( (float) $order->get_total() * 100 ) )
			|| strtoupper( $payment['amount']['currency'] ) !== strtoupper( $attempt['currency'] ?? $order->get_currency() )
			|| ( isset( $payment['meta']['woocommerce']['order_id'] ) && (string) $payment['meta']['woocommerce']['order_id'] !== (string) $order->get_id() )
			|| ( isset( $attempt['merchant'] ) && $attempt['merchant'] !== Icepay::getMerchantId() )
			|| ( isset( $payment['merchantId'] ) && (string) $payment['merchantId'] !== Icepay::getMerchantId() )
			|| ( isset( $attempt['isTest'] ) && ( $payment['isTest'] ?? null ) !== $attempt['isTest'] ) ) {
			return new WP_Error( 'invalid_payment', 'Payment details do not match the stored attempt.' );
		}

		RefundService::reconcileLocked( $order, $payment );
		// Refund recovery may update the order status through WooCommerce.
		if ( $order->get_meta( '_icepay_refunds' ) ) {
			$order->get_data_store()->read( $order );
		}
		// A completed payment must never regress on a delayed notification.
		if ( $attempt['status'] === 'completed' && $status !== 'completed' ) {
			return true;
		}
		$attempt['status'] = $status;
		$attempt['method'] = $payment['paymentMethod']['type'] ?? ( $attempt['method'] ?? '' );
		$attempt['updated'] = time();
		$url = self::redirectUrl( $payment );
		if ( $url !== '' ) {
			$attempt['redirect'] = $url;
		}
		$attempts[ $key ] = $attempt;
		$order->update_meta_data( '_icepay_attempts', $attempts );
		$order->save();

		if ( $order->is_paid() || $order->get_date_paid() || $order->has_status( 'refunded' ) ) {
			if ( $status === 'completed' && $order->get_transaction_id() !== $key && empty( $attempt['duplicate_noted'] ) ) {
				/* translators: 1: ICEPAY key, 2: current gateway, 3: current transaction ID. */
				$order->add_order_note( sprintf( __( 'Additional ICEPAY payment completed (%1$s). Current gateway: %2$s; transaction: %3$s. Review this payment for a possible duplicate charge.', 'icepay-for-woocommerce' ), $key, $order->get_payment_method(), $order->get_transaction_id() ) );
				$attempts[ $key ]['duplicate_gateway'] = $order->get_payment_method();
				$attempts[ $key ]['duplicate_transaction'] = $order->get_transaction_id();
				$attempts[ $key ]['duplicate_noted'] = true;
				$order->update_meta_data( '_icepay_attempts', $attempts );
				$order->save();
			}
			return true;
		}

		if ( $status === 'completed' ) {
			// Never fulfil a changed order using an older, smaller payment.
			if ( (float) $payment['amount']['value'] !== round( (float) $order->get_total() * 100 )
				|| strtoupper( $payment['amount']['currency'] ) !== strtoupper( $order->get_currency() ) ) {
				return new WP_Error( 'changed_order', 'Payment does not match the current order total.' );
			}
			if ( ! Order::isIcepay( $order ) && $order->get_payment_method() !== '' && empty( $attempt['provider_conflict'] ) ) {
				$attempts[ $key ]['provider_conflict'] = [
					'gateway' => $order->get_payment_method(),
					'title' => $order->get_payment_method_title(),
					'transaction' => $order->get_transaction_id(),
					'recorded' => time(),
				];
				/* translators: 1: ICEPAY key, 2: previous gateway, 3: previous transaction ID. */
				$order->add_order_note( sprintf( __( 'ICEPAY payment %1$s completed after the order switched to gateway %2$s (previous transaction: %3$s). Completing the order through ICEPAY. Check both providers for a possible second payment before issuing a refund.', 'icepay-for-woocommerce' ), $key, $order->get_payment_method(), $order->get_transaction_id() ) );
				$order->update_meta_data( '_icepay_attempts', $attempts );
				$order->save();
			}
			foreach ( PaymentMethod::getAll() as $method ) {
				if ( $method->getType() === $attempt['method'] ) {
					$order->set_payment_method( $method->getId() );
					$order->set_payment_method_title( $method->getName() );
					break;
				}
			}
			if ( $order->has_status( 'checkout-draft' ) ) {
				$order->set_status( 'pending' );
			}
			$order->payment_complete( $key );
			$order->save();
			return true;
		}

		if ( $key !== Order::activeKey( $order ) || ! Order::isIcepay( $order ) ) {
			return true;
		}
		if ( $order->has_status( [ 'pending', 'on-hold', 'failed', 'cancelled', 'checkout-draft' ] ) ) {
			$nextStatus = match ( $status ) {
				'pending' => 'on-hold',
				'cancelled', 'expired' => 'cancelled',
				default => 'pending',
			};
			if ( ! $order->has_status( $nextStatus ) ) {
				$order->update_status( $nextStatus );
			}
		}
		return true;
	}

	/** @param array<string, mixed> $payment */
	public static function redirectUrl( array $payment ): string {
		$url = $payment['links']['direct'] ?? $payment['links']['checkout'] ?? '';
		if ( ! is_string( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) !== 'https'
			|| ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}
}
