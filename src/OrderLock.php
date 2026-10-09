<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

/** Connection-scoped locks also release automatically if a request crashes. */
class OrderLock {
	public static function acquire( int $orderId ): bool {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::name( $orderId ) ) ) === '1';
	}

	public static function release( int $orderId ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $orderId ) ) );
	}

	private static function name( int $orderId ): string {
		global $wpdb;

		return 'icepay_' . sha1( $wpdb->prefix . ':' . $orderId );
	}
}
