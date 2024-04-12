<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class Icepay {
	public const MERCHANT_ID = Integration::ID . '_general_merchant_id';
	public const SECRET = Integration::ID . '_general_secret_code';
	public const ENABLE_LOGS = Integration::ID . '_general_enable_logs';
	public const DESCRIPTION = Integration::ID . '_general_description';

	public static function getMerchantId(): string {
		return get_option( self::MERCHANT_ID, null );
	}

	public static function getSecret(): string {
		return get_option( self::SECRET, null );
	}

	public static function areLogsEnabled(): bool {
		return get_option( self::ENABLE_LOGS, 'yes' ) === 'yes';
	}

	public static function getDescription(): string {
		$description = get_option( self::DESCRIPTION, 'Order #{ORDER_ID}' );

		return ! empty( $description ) ? $description : 'Order #{ORDER_ID}';
	}
}
