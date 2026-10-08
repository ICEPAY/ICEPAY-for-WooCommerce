<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class Order {
	public static function FindOrderByKey( string $key ): \WC_Order|null {
		$orders = wc_get_orders( [
			'limit'        => 1,
			'meta_key'     => 'icepay-payment-key',
			'meta_value'   => $key,
			'meta_compare' => '=',
		] );

		$orderId = current( $orders ) ? current( $orders )->get_id() : false;

		if ( ! empty( $orderId ) ) {
			$order = wc_get_order( $orderId );
		}

		if ( ! empty( $order ) && $order->get_status() !== 'trash' ) {
			return $order;
		}

		return null;
	}

	public static function AddPaymentKey( $order, string $key ): void {
		$currentKeys = $order->get_meta( 'icepay-payment-key' );

		if ( str_contains( $currentKeys, $key ) ) {
			return;
		}

		/* translators: 1: ICEPAY Checkout Key */
		$order->add_order_note(
			sprintf(
				__( 'ICEPAY payment created with key: %1$s', 'icepay-for-woocommerce' ),
				$key
			)
		);

		$order->update_meta_data(
			'icepay-payment-key',
			implode( ',', [ $key, ...explode( ',', $currentKeys ) ] )
		);

		$order->save();
	}
}
