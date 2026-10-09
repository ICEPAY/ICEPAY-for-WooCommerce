<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

class IcepayClient {
	protected const Endpoint = 'https://checkout.icepay.com/api';

	public function __construct(
		protected ?string $merchantId,
		protected ?string $secretCode
	) {
	}

	public function create( array $data ): array {
		return $this->do( '/payments', $data, 'POST' );
	}

	public function cancel( string $id ): array {
		return $this->do( '/payments/' . rawurlencode( $id ) . '/cancel', method: 'POST' );
	}

	public function get( string $id ): array {
		return $this->do( '/payments/' . rawurlencode( $id ) );
	}

	public function refund( string $id, array $data ): array {
		return $this->do( '/payments/' . rawurlencode( $id ) . '/refund', $data, 'POST' );
	}

	protected function do( string $uri, ?array $body = null, string $method = 'GET' ): array {
		if ( ! $this->merchantId || ! $this->secretCode ) {
			return [ false, [ 'message' => 'Merchant ID or Secret Code not set' ] ];
		}

		$request_args = [
			'method'      => $method,
			'headers'     => [
				'Authorization' => $this->toBasicAuthorization(),
				'Content-Type'  => 'application/json',
			],
			'httpversion' => '1.1',
			'sslverify'   => true,
			'timeout'     => 10,
		];

		if ( $body ) {
			$request_args['body'] = wp_json_encode( $body );
			if ( $request_args['body'] === false ) {
				return [ false, [ 'message' => 'Unable to encode payment request.', 'uncertain' => false ] ];
			}
		}

		$response = wp_remote_request(
			self::Endpoint . $uri,
			$request_args
		);

		if ( is_wp_error( $response ) ) {
			return [ false, [ 'message' => $response->get_error_message(), 'uncertain' => $method === 'POST' ] ];
		}

		$responseBody          = json_decode( wp_remote_retrieve_body( $response ), true );
		$responseStatusCode    = wp_remote_retrieve_response_code( $response );
		$responseStatusMessage = wp_remote_retrieve_response_message( $response );

		if ( $responseStatusCode < 200 || $responseStatusCode >= 300 ) {
			return [
				false,
				[
					'message'   => $responseStatusCode . ': ' . $responseStatusMessage,
					'uncertain' => $method === 'POST' && ( $responseStatusCode >= 500 || in_array( $responseStatusCode,
								[ 408, 409, 429 ], true ) ),
				]
			];
		}

		if ( ! is_array( $responseBody ) || array_is_list( $responseBody ) ) {
			return [ false, [ 'message' => 'Invalid JSON response from ICEPAY.', 'uncertain' => $method === 'POST' ] ];
		}

		return [ true, $responseBody ];
	}

	protected function toBasicAuthorization(): string {
		return 'Basic ' . base64_encode( $this->merchantId . ':' . $this->secretCode );
	}
}
