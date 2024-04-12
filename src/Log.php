<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use WC_Logger_Interface;

class Log {
	protected WC_Logger_Interface $logger;

	public function __construct() {
		$this->logger = wc_get_logger();
	}

	public function info( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'info' );
	}

	public function debug( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'debug' );
	}

	public function warning( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'warning' );
	}

	public function error( string $message, array $data = [] ): void {
		$this->log( $message, $data, 'error' );
	}

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
