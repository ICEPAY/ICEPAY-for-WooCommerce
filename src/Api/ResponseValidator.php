<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Api;

/**
 * External JSON remains untrusted until these structural checks pass.
 * Order ownership, amount matching and state policies are checked by payment handlers.
 *
 * @phpstan-type Amount array{value: int, currency: string}
 * @phpstan-type Refund array{key: non-empty-string, status: 'pending'|'completed', amount: Amount, reference: string, description?: string, payment?: array{key: string}}
 * @phpstan-type Payment array{key: non-empty-string, status: 'started'|'pending'|'completed'|'expired'|'cancelled', amount: Amount, paymentMethod?: array{type: string}, links?: array{direct?: string, checkout?: string}, meta?: array{woocommerce?: array{order_id?: int|string, creation_token?: string}}, merchantId?: int|string, isTest?: bool, refunds?: list<Refund>}
 */
final class ResponseValidator {
	/**
	 * ICEPAY emits null for documented optional fields. Normalize only those fields;
	 * required fields and non-null malformed values must still fail validation.
	 * @param array<string, mixed> $payment
	 * @return array<string, mixed>
	 */
	public static function normalizePayment( array $payment ): array {
		foreach ( [ 'paymentMethod', 'meta' ] as $field ) {
			if ( array_key_exists( $field, $payment ) && $payment[ $field ] === null ) { unset( $payment[ $field ] ); }
		}
		if ( array_key_exists( 'refunds', $payment ) && $payment['refunds'] === null ) { $payment['refunds'] = []; }
		if ( is_array( $payment['links'] ?? null ) && array_key_exists( 'direct', $payment['links'] ) && $payment['links']['direct'] === null ) {
			unset( $payment['links']['direct'] );
		}
		return $payment;
	}

	/** @param array<string, mixed> $payment
	 * @return list<string> Field names only, never response values or customer data.
	 */
	public static function invalidPaymentFields( array $payment ): array {
		$fields = [];
		foreach ( [ 'key', 'status', 'amount' ] as $field ) {
			$valid = match ( $field ) {
				'key' => is_string( $payment['key'] ?? null ) && $payment['key'] !== '',
				'status' => in_array( $payment['status'] ?? null, [ 'started', 'pending', 'completed', 'expired', 'cancelled' ], true ),
				'amount' => self::amount( $payment['amount'] ?? null ),
			};
			if ( ! $valid ) { $fields[] = $field; }
		}
		// Check each optional field against a minimal valid payment to identify its path.
		$base = [ 'key' => 'validation', 'status' => 'started', 'amount' => [ 'value' => 0, 'currency' => 'eur' ] ];
		foreach ( [ 'paymentMethod', 'links', 'meta', 'merchantId', 'isTest', 'refunds' ] as $field ) {
			if ( array_key_exists( $field, $payment ) && ! self::payment( array_merge( $base, [ $field => $payment[ $field ] ] ) ) ) { $fields[] = $field; }
		}
		if ( ( $payment['status'] ?? '' ) === 'completed' && ! isset( $payment['paymentMethod'] ) ) { $fields[] = 'paymentMethod'; }
		return array_values( array_unique( $fields ) );
	}
	/** @phpstan-assert-if-true Amount $amount */
	public static function amount( mixed $amount ): bool {
		return is_array( $amount ) && is_int( $amount['value'] ?? null ) && $amount['value'] >= 0
			&& is_string( $amount['currency'] ?? null ) && preg_match( '/^[a-z]{3}$/iD', $amount['currency'] ) === 1;
	}

	/** @phpstan-assert-if-true Refund $refund */
	public static function refund( mixed $refund ): bool {
		return is_array( $refund ) && is_string( $refund['key'] ?? null ) && $refund['key'] !== ''
			&& in_array( $refund['status'] ?? null, [ 'pending', 'completed' ], true )
			&& self::amount( $refund['amount'] ?? null ) && is_string( $refund['reference'] ?? null )
			&& ( ! array_key_exists( 'description', $refund ) || is_string( $refund['description'] ) )
			&& ( ! array_key_exists( 'payment', $refund ) || ( is_array( $refund['payment'] ) && is_string( $refund['payment']['key'] ?? null ) ) );
	}

	/** @phpstan-assert-if-true Payment $payment */
	public static function payment( mixed $payment ): bool {
		if ( ! is_array( $payment ) || ! is_string( $payment['key'] ?? null ) || $payment['key'] === ''
			|| ! in_array( $payment['status'] ?? null, [ 'started', 'pending', 'completed', 'expired', 'cancelled' ], true )
			|| ! self::amount( $payment['amount'] ?? null ) ) {
			return false;
		}
		if ( array_key_exists( 'paymentMethod', $payment )
			&& ( ! is_array( $payment['paymentMethod'] ) || ! is_string( $payment['paymentMethod']['type'] ?? null ) ) ) {
			return false;
		}
		if ( $payment['status'] === 'completed' && ! isset( $payment['paymentMethod'] ) ) {
			return false;
		}
		if ( array_key_exists( 'links', $payment ) ) {
			if ( ! is_array( $payment['links'] ) ) { return false; }
			foreach ( [ 'direct', 'checkout' ] as $field ) {
				if ( array_key_exists( $field, $payment['links'] ) && ! is_string( $payment['links'][ $field ] ) ) { return false; }
			}
		}
		if ( array_key_exists( 'meta', $payment ) ) {
			if ( ! is_array( $payment['meta'] ) ) { return false; }
			if ( array_key_exists( 'woocommerce', $payment['meta'] ) ) {
				$meta = $payment['meta']['woocommerce'];
				if ( ! is_array( $meta )
					|| ( array_key_exists( 'order_id', $meta ) && ! is_int( $meta['order_id'] ) && ! is_string( $meta['order_id'] ) )
					|| ( array_key_exists( 'creation_token', $meta ) && ! is_string( $meta['creation_token'] ) ) ) { return false; }
			}
		}
		if ( ( array_key_exists( 'merchantId', $payment ) && ! is_int( $payment['merchantId'] ) && ! is_string( $payment['merchantId'] ) )
			|| ( array_key_exists( 'isTest', $payment ) && ! is_bool( $payment['isTest'] ) ) ) { return false; }
		if ( array_key_exists( 'refunds', $payment ) ) {
			if ( ! is_array( $payment['refunds'] ) || ! array_is_list( $payment['refunds'] ) ) { return false; }
			foreach ( $payment['refunds'] as $refund ) {
				if ( ! self::refund( $refund ) ) { return false; }
			}
		}
		return true;
	}
}
