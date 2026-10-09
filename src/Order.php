<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class Order {
	public static function isIcepay( \WC_Order $order ): bool {
		return str_starts_with( $order->get_payment_method(), 'icepay-' );
	}

	public static function FindOrderByKey( string $key ): \WC_Order|null {
		$orders = wc_get_orders( [
			'limit'        => -1,
			'meta_key'     => 'icepay-payment-key',
			'meta_value'   => $key,
			'meta_compare' => 'LIKE',
		] );

		foreach ( $orders as $order ) {
			if ( $order->get_status() !== 'trash' && isset( self::getAttempts( $order )[ $key ] ) ) {
				return $order;
			}
		}

		return null;
	}

	public static function AddPaymentKey( $order, string $key ): void {
		$currentKeys = $order->get_meta( 'icepay-payment-key' );

		if ( in_array( $key, explode( ',', (string) $currentKeys ), true ) ) {
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
			implode( ',', array_filter( [ $key, ...explode( ',', (string) $currentKeys ) ] ) )
		);

		$order->save();
	}

	public static function getAttempts( \WC_Order $order ): array {
		$attempts = $order->get_meta( '_icepay_attempts' );
		$attempts = is_array( $attempts ) ? $attempts : [];
		foreach ( array_filter( explode( ',', (string) $order->get_meta( 'icepay-payment-key' ) ) ) as $key ) {
			if ( ! isset( $attempts[ $key ] ) ) {
				$attempts[ $key ] = [ 'key' => $key, 'status' => 'unknown' ];
			}
		}

		return $attempts;
	}

	public static function addAttempt( \WC_Order $order, array $payment, string $method ): void {
		$attempts = self::getAttempts( $order );
		$key = $payment['key'];
		$attempts = [ $key => [
			'key' => $key,
			'method' => $method,
			'amount' => (int) round( (float) $order->get_total() * 100 ),
			'currency' => $order->get_currency(),
			'merchant' => Icepay::getMerchantId(),
			'isTest' => $payment['isTest'] ?? null,
			'status' => 'unknown',
			'created' => time(),
			'expires' => time() + Icepay::getExpireAfter() * 60,
		], ...$attempts ];
		$order->update_meta_data( '_icepay_attempts', $attempts );
		$order->delete_meta_data( '_icepay_creation_uncertain' );
		self::AddPaymentKey( $order, $key );
	}

	public static function activeKey( \WC_Order $order ): string {
		return (string) ( array_key_first( self::getAttempts( $order ) ) ?? '' );
	}
}
