<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Checkout;

use Icepay\WooCommerce\Payment\PaymentAttempts as Order;

class PaymentReceipt {
	public function text( string $text, mixed $order ): string {
		if ( ! $order instanceof \WC_Order || ! Order::isIcepay( $order ) || $order->is_paid()
			|| $order->get_date_paid() || $order->has_status( 'refunded' ) ) {
			return $text;
		}
		if ( $order->get_meta( '_icepay_creation_uncertain' ) ) {
			return esc_html__( 'We could not confirm whether your payment was started. Please contact the store before paying again.', 'icepay-for-woocommerce' );
		}
		if ( Order::activeKey( $order ) === '' ) {
			return $text;
		}
		$attempts = Order::getAttempts( $order );
		$status = $attempts[ Order::activeKey( $order ) ]['status'] ?? 'unknown';
		if ( in_array( $status, [ 'unknown', 'pending', 'completed' ], true ) ) {
			return esc_html__( 'Your order has been received. Your payment is still being confirmed; please do not pay again. We will email you when it is confirmed.', 'icepay-for-woocommerce' );
		}
		$url = wp_nonce_url( add_query_arg( [ 'icepay-retry' => $order->get_id(), 'key' => $order->get_order_key() ], wc_get_checkout_url() ), 'icepay-retry-' . $order->get_id() );
		$message = in_array( $status, [ 'cancelled', 'expired' ], true )
			? __( 'Your payment was cancelled or expired. You can try again or choose another payment method.', 'icepay-for-woocommerce' )
			: __( 'Your payment has not been completed. You can return to checkout to continue.', 'icepay-for-woocommerce' );
		return esc_html( $message ) . ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Try again', 'icepay-for-woocommerce' ) . '</a>';
	}
}
