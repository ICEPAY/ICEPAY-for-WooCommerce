<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Checkout;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Log;
use Icepay\WooCommerce\Payment\OrderLock;
use Icepay\WooCommerce\Payment\PaymentAttempts as Order;
use Icepay\WooCommerce\Payment\PaymentStatusHandler;

class PaymentRetryController {
	protected IcepayClient $client;
	protected PaymentStatusHandler $statusHandler;

	public function __construct( ?IcepayClient $client = null, ?PaymentStatusHandler $statusHandler = null ) {
		$this->client = $client ?? IcepayClient::fromSettings();
		$this->statusHandler = $statusHandler ?? new PaymentStatusHandler( $this->client );
	}

	public function handle(): void {
		if ( ! isset( $_GET['icepay-retry'] ) ) {
			return;
		}
		$id = is_scalar( $_GET['icepay-retry'] ) ? absint( $_GET['icepay-retry'] ) : 0;
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$order = wc_get_order( $id );
		if ( ! $order || $key === '' || ! hash_equals( $order->get_order_key(), $key ) || ! wp_verify_nonce( $nonce, 'icepay-retry-' . $id ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		$url = $order->get_checkout_order_received_url();
		if ( ! Order::isIcepay( $order ) ) {
			return;
		}
		if ( OrderLock::acquire( $id ) ) {
			try {
				$order = wc_get_order( $id );
				if ( ! Order::isIcepay( $order ) ) {
					return;
				}
				$paymentKey = Order::activeKey( $order );
				if ( $paymentKey !== '' && ! $order->is_paid() && ! $order->get_date_paid()
					&& ! $order->has_status( 'refunded' ) && ! $order->get_meta( '_icepay_creation_uncertain' ) ) {
					[ $success, $payment ] = $this->client->get( $paymentKey );
					if ( $success && ( $payment['key'] ?? '' ) === $paymentKey ) {
						$result = $this->statusHandler->applyLocked( $order, $payment );
						$order = wc_get_order( $id );
						if ( ! $order ) { return; }
						if ( ! is_wp_error( $result ) && in_array( $payment['status'], [ 'started', 'cancelled', 'expired' ], true ) && Order::isIcepay( $order ) ) {
							if ( $order->has_status( 'cancelled' ) ) {
								$order->update_status( 'pending', __( 'Customer requested another payment attempt.', 'icepay-for-woocommerce' ) );
							}
							if ( $order->needs_payment() ) {
								$url = $order->get_checkout_payment_url();
							}
						}
					}
				}
			} catch ( \Throwable $exception ) {
				( new Log() )->error( 'Unable to prepare payment retry.', [ 'order' => $id, 'message' => $exception->getMessage() ] );
			} finally {
				OrderLock::release( $id );
			}
		}
		if ( $url === $order->get_checkout_order_received_url() ) {
			wc_add_notice( __( 'Your payment must be confirmed before you can try again. Please wait or contact the store.', 'icepay-for-woocommerce' ), 'notice' );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
