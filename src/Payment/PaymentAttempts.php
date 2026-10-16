<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Icepay;

/**
 * @phpstan-type Attempt array{key: string, status: string, creation_token?: string|null, method?: string, amount?: int, currency?: string, merchant?: string, isTest?: bool|null, created?: int, expires?: int, updated?: int, redirect?: string, duplicate_noted?: bool, duplicate_gateway?: string, duplicate_transaction?: string, provider_conflict?: array{gateway: string, title: string, transaction: string, recorded: int}}
 */
class PaymentAttempts {
	public static function isIcepay( \WC_Order $order ): bool {
		return str_starts_with( $order->get_payment_method(), 'icepay-' );
	}

	public static function findOrderByKey( string $key ): \WC_Order|null {
		$orders = wc_get_orders( [
			'limit'        => -1,
			'meta_key'     => 'icepay-payment-key',
			'meta_value'   => $key,
			'meta_compare' => '=',
		] );
		if ( ! is_array( $orders ) ) { return null; }

		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order && $order->get_status() !== 'trash' && isset( self::getAttempts( $order )[ $key ] ) ) {
				return $order;
			}
		}

		return null;
	}

	public static function addPaymentKey( \WC_Order $order, string $key ): void {
		if ( in_array( $key, self::getPaymentKeys( $order ), true ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: ICEPAY Checkout Key. */
				__( 'ICEPAY payment created with key: %1$s', 'icepay-for-woocommerce' ),
				$key
			)
		);

		$order->add_meta_data( 'icepay-payment-key', $key, false );

		$order->save();
	}

	/** @return list<string> */
	private static function getPaymentKeys( \WC_Order $order ): array {
		$keys = [];
		foreach ( (array) $order->get_meta( 'icepay-payment-key', false ) as $meta ) {
			$value = $meta instanceof \WC_Meta_Data ? ( $meta->get_data()['value'] ?? null ) : null;
			if ( is_string( $value ) && $value !== '' ) {
				$keys[] = $value;
			}
		}
		return $keys;
	}

	/** @return array<string, Attempt> */
	public static function getAttempts( \WC_Order $order ): array {
		$attempts = $order->get_meta( '_icepay_attempts' );
		$attempts = is_array( $attempts ) ? $attempts : [];
		foreach ( $attempts as $key => $attempt ) {
			if ( ! is_string( $key ) || ! is_array( $attempt ) || ! self::validAttempt( $attempt ) ) {
				unset( $attempts[ $key ] );
			}
		}
		foreach ( self::getPaymentKeys( $order ) as $key ) {
			if ( ! isset( $attempts[ $key ] ) ) {
				$attempts[ $key ] = [ 'key' => $key, 'status' => 'unknown' ];
			}
		}

		return $attempts;
	}

	/** @param array<string, mixed> $payment */
	public static function addAttempt( \WC_Order $order, array $payment, string $method, ?string $token = null ): void {
		$payment = \Icepay\WooCommerce\Api\ResponseValidator::normalizePayment( $payment );
		if ( ! \Icepay\WooCommerce\Api\ResponseValidator::payment( $payment ) ) {
			throw new \InvalidArgumentException( 'Invalid payment attempt.' );
		}
		$attempts = self::getAttempts( $order );
		$key = $payment['key'];
		$attempts = [ $key => [
			'key' => $key,
			'creation_token' => $token,
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
		self::addPaymentKey( $order, $key );
	}

	public static function activeKey( \WC_Order $order ): string {
		return (string) ( array_key_first( self::getAttempts( $order ) ) ?? '' );
	}

	/** @param array<array-key, mixed> $attempt
	 * @phpstan-assert-if-true Attempt $attempt
	 */
	private static function validAttempt( array $attempt ): bool {
		if ( ! is_string( $attempt['key'] ?? null ) || ! is_string( $attempt['status'] ?? null ) ) { return false; }
		foreach ( [ 'method', 'currency', 'merchant', 'redirect', 'duplicate_gateway', 'duplicate_transaction' ] as $field ) {
			if ( array_key_exists( $field, $attempt ) && ! is_string( $attempt[ $field ] ) ) { return false; }
		}
		foreach ( [ 'amount', 'created', 'expires', 'updated' ] as $field ) {
			if ( array_key_exists( $field, $attempt ) && ! is_int( $attempt[ $field ] ) ) { return false; }
		}
		if ( array_key_exists( 'creation_token', $attempt ) && $attempt['creation_token'] !== null && ! is_string( $attempt['creation_token'] ) ) { return false; }
		if ( array_key_exists( 'isTest', $attempt ) && $attempt['isTest'] !== null && ! is_bool( $attempt['isTest'] ) ) { return false; }
		if ( array_key_exists( 'duplicate_noted', $attempt ) && ! is_bool( $attempt['duplicate_noted'] ) ) { return false; }
		if ( array_key_exists( 'provider_conflict', $attempt ) ) {
			$conflict = $attempt['provider_conflict'];
			if ( ! is_array( $conflict ) || ! is_string( $conflict['gateway'] ?? null ) || ! is_string( $conflict['title'] ?? null )
				|| ! is_string( $conflict['transaction'] ?? null ) || ! is_int( $conflict['recorded'] ?? null ) ) { return false; }
		}
		return true;
	}

	public static function findKeyByToken( \WC_Order $order, string $token ): string {
		foreach ( self::getAttempts( $order ) as $key => $attempt ) {
			if ( is_string( $attempt['creation_token'] ?? null ) && hash_equals( $attempt['creation_token'], $token ) ) {
				return $key;
			}
		}
		return '';
	}
}
