<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class Order {
	public static function FindOrderByKey( string $key ): \WC_Order|null {
		$orders = wc_get_orders( [
			'limit'      => 1,
			'meta_query' => [
				'key'   => 'icepay-payment-key',
				'value' => $key
			]
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
}
