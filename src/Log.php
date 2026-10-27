<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use WC_Logger_Interface;

class Log {
	protected WC_Logger_Interface $logger;

	public function __construct() {
		$this->logger = wc_get_logger();
	}

	/** @param array<array-key, mixed> $data */
	public function info( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'info' );
	}

	/** @param array<array-key, mixed> $data */
	public function debug( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'debug' );
	}

	/** @param array<array-key, mixed> $data */
	public function warning( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'warning' );
	}

	/** @param array<array-key, mixed> $data */
	public function error( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'error' );
	}

	/** @param array<array-key, mixed> $data */
	protected function log( string $message, array $data, string $type = 'info' ): void {
		if ( ! Icepay::areLogsEnabled() ) {
			return;
		}

		$this->logger->log(
			$type,
			$message . ( empty( $data ) ? '' : ( ' ' . json_encode( $data ) ) ),
			[ 'source' => Integration::NAME ]
		);
	}
}
