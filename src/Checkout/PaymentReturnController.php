<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Checkout;

use Icepay\WooCommerce\Log;
use Icepay\WooCommerce\Payment\PaymentAttempts as Order;
use Icepay\WooCommerce\Payment\PaymentStatusHandler;

class PaymentReturnController {
	public function __construct( protected PaymentStatusHandler $statusHandler = new PaymentStatusHandler() ) {}

	public function handle(): void {
		if ( ! isset( $_GET['via-icepay'] ) ) {
			return;
		}
		$orderKey = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = wc_get_order( wc_get_order_id_by_order_key( $orderKey ) );
		if ( ! $order || $orderKey === '' || ! hash_equals( $order->get_order_key(), $orderKey ) ) {
			wc_add_notice( __( 'This payment return link is invalid. Please check your order confirmation or contact the store.', 'icepay-for-woocommerce' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		if ( ! Order::isIcepay( $order ) ) {
			if ( Order::activeKey( $order ) !== '' || $order->get_meta( '_icepay_creation_uncertain' ) ) {
				$this->redirectPaymentReturn( $order );
			}
			return;
		}
		$key = Order::activeKey( $order );
		if ( ! $order->is_paid() && ! $order->get_date_paid() && $key !== '' ) {
			try {
				$result = $this->statusHandler->refresh( $order, $key );
				if ( is_wp_error( $result ) ) {
					wc_add_notice( __( 'Your payment is being confirmed. Please do not pay again while we check it.', 'icepay-for-woocommerce' ), 'notice' );
				}
			} catch ( \Throwable $exception ) {
				( new Log() )->error( 'Unable to confirm payment on return.', [ 'order' => $order->get_id(), 'message' => $exception->getMessage() ] );
				wc_add_notice( __( 'Your payment is being confirmed. Please do not pay again while we check it.', 'icepay-for-woocommerce' ), 'notice' );
			}
		}
		$this->redirectPaymentReturn( wc_get_order( $order->get_id() ) );
	}

	protected function redirectPaymentReturn( \WC_Order $order ): void {
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order ) );
		exit;
	}
}
