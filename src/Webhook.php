<?php

namespace Icepay\WooCommerce;

use Icepay\WooCommerce\Payment\PaymentAttempts as Order;
use Icepay\WooCommerce\Payment\PaymentStatusHandler;

class Webhook {
	protected PaymentStatusHandler $statusHandler;

	public function __construct( ?PaymentStatusHandler $statusHandler = null ) {
		$this->statusHandler = $statusHandler ?? new PaymentStatusHandler();
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Server-to-server notifications authenticate the raw body with HMAC, not a browser nonce.
		$log = new Log();
		$log->info( 'got postback via webhook' );

		if (
			! isset( $_SERVER['REQUEST_METHOD'] ) || ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) || ! isset( $_GET['wc-api'] )
			|| ! is_string( $_GET['wc-api'] ) || ( sanitize_text_field( wp_unslash( $_GET['wc-api'] ) ) !== 'icepay-webhook' )
		) {
			$log->error( 'invalid request method or wc-api' );

			status_header( 405 );
			exit;
		}

		$data    = file_get_contents( 'php://input', false, null, 0, 1048577 );
		$headers = array_change_key_case( $this->getHeader() ?: [] );
		$secret  = Icepay::getSecret();
		if ( $secret === '' || $data === false ) {
			status_header( 503 );
			exit;
		}
		if ( strlen( $data ) > 1048576 ) {
			status_header( 413 );
			exit;
		}
		if ( ! self::verifySignature( $data, $headers['icepay-signature'] ?? null, $secret ) ) {
			$log->warning( 'got postback, but could not validate it.' );
			status_header( 401 );
			exit;
		}

		$data = json_decode( $data, true );
		if ( ! is_array( $data ) || ! is_string( $data['key'] ?? null ) || $data['key'] === '' ) {
			status_header( 400 );
			exit;
		}
		$log->info( 'got postback and could validate it.' );
		$order = Order::FindOrderByKey( $data['key'] );
		if ( ! $order && isset( $data['meta']['woocommerce']['order_id'] ) && is_scalar( $data['meta']['woocommerce']['order_id'] ) ) {
			$candidate = wc_get_order( absint( $data['meta']['woocommerce']['order_id'] ) );
			if ( $candidate && $candidate->get_meta( '_icepay_creation_uncertain' ) ) {
				// refresh() verifies the persisted creation token before attaching a key.
				$order = $candidate;
			}
		}

		if ( ! $order ) {
			$log->warning( 'Order not found' );
			// The notification may have arrived before the create response was persisted.
			status_header( 503 );
			exit;
		}

		try {
			// Notifications have no reliable sequence number; retrieve current state once.
			$result = $this->statusHandler->refresh( $order, $data['key'] );
			if ( is_wp_error( $result ) ) {
				$log->warning( $result->get_error_message(), [ 'order' => $order->get_id(), 'key' => $data['key'] ] );
				status_header( in_array( $result->get_error_code(), [ 'busy', 'unavailable' ], true ) ? 503 : 422 );
			} else {
				status_header( 200 );
			}
		} catch ( \Throwable $exception ) {
			$log->error( 'Unable to persist payment notification.', [ 'message' => $exception->getMessage() ] );
			status_header( 503 );
		}
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function verifySignature( string $body, mixed $received, string $secret ): bool {
		return $secret !== '' && is_string( $received )
		       && hash_equals( base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ), $received );
	}
	
	/** @return array<string, string>|false */
	protected function getHeader(): false|array {
		if ( ! function_exists( 'getallheaders' ) ) {
			$headers = [];

			foreach ( $_SERVER as $name => $value ) {
				if ( str_starts_with( $name, 'HTTP_' ) ) {
					$headers[ str_replace( ' ', '-',
						ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}

			return $headers;
		}

		return getallheaders();
	}
}
