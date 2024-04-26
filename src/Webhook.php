<?php

namespace Icepay\WooCommerce;

class Webhook {
	public function handle(): void {
		$log = new Log();
		$log->info( 'got postback via webhook' );

		if (
			! isset( $_SERVER['REQUEST_METHOD'] ) || ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) || ! isset( $_GET['wc-api'] )
			|| ( sanitize_text_field( wp_unslash( $_GET['wc-api'] ) ) !== 'icepay-webhook' )
		) {
			$log->error( 'invalid request method or wc-api' );

			return;
		}

		$data      = file_get_contents( 'php://input' );
		$headers   = $this->getHeader() ?: [];
		$secret    = Icepay::getSecret();
		$signature = base64_encode( hash_hmac( 'sha256', $data, $secret, true ) );

		$data = json_decode( $data, true );

		if ( $signature !== ($headers['Icepay-Signature'] ?? $headers['icepay-signature'])) {
			$log->warning( 'got postback, but could not validate it.' );
			status_header( 200 );
			exit;
		}

		$log->info( 'got postback and could validate it.' );
		$order = Order::FindOrderByKey( $data['key'] );

		if ( ! $order ) {
			$log->warning( 'Order not found' );
			status_header( 200 );
			exit;
		}

		$status = match ( $data['status'] ) {
			'completed' => 'completed',
			'cancelled', 'expired' => 'cancelled',
			'pending' => 'processing',
			default => 'pending',
		};

		if ( $order->get_status() === 'processing' || $order->get_status() === 'pending' || $order->get_status() === 'on-hold' ) {
			$log->info( 'Updating order status to ' . $status );
			$order->update_status( $status );
		}

		status_header( 200 );
		exit;
	}

	/** @return false|array */
	protected function getHeader(): false|array {
		if ( ! function_exists( 'getallheaders' ) ) {
			$headers = [];

			foreach ( $_SERVER as $name => $value ) {
				if ( str_starts_with( $name, 'HTTP_' ) ) {
					$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}

			return $headers;
		}

		return getallheaders();
	}
}
