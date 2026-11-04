<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Api;

/**
 * @phpstan-import-type Payment from ResponseValidator
 * @phpstan-import-type Refund from ResponseValidator
 * @phpstan-type ApiError array{message: string, uncertain?: bool}
 */
class IcepayClient {
	public static function fromSettings(): self {
		return new self( \Icepay\WooCommerce\Icepay::getMerchantId(), \Icepay\WooCommerce\Icepay::getSecret() );
	}

	protected const Endpoint = 'https://checkout.icepay.com/api';

	public function __construct(
		protected ?string $merchantId,
		protected ?string $secretCode
	) {
	}

	/** @param array<string, mixed> $data
	 * @return array{true, Payment}|array{false, ApiError}
	 */
	public function create( array $data ): array {
		return $this->paymentResult( $this->do( '/payments', $data, 'POST' ), true );
	}

	/** @return array{true, Payment}|array{false, ApiError} */
	public function cancel( string $id ): array {
		return $this->paymentResult( $this->do( '/payments/' . rawurlencode( $id ) . '/cancel', method: 'POST' ), true );
	}

	/** @return array{true, Payment}|array{false, ApiError} */
	public function get( string $id ): array {
		return $this->paymentResult( $this->do( '/payments/' . rawurlencode( $id ) ) );
	}

	/** @param array{amount: array{value: int}, reference: string, description: string} $data
	 * @return array{true, Refund}|array{false, ApiError}
	 */
	public function refund( string $id, array $data ): array {
		$result = $this->do( '/payments/' . rawurlencode( $id ) . '/refund', $data, 'POST' );
		if ( ! $result[0] ) { return $result; }
		return ResponseValidator::refund( $result[1] ) ? [ true, $result[1] ]
			: [ false, [ 'message' => 'Invalid refund response from ICEPAY.', 'uncertain' => true ] ];
	}

	/** @param array{true, array<string, mixed>}|array{false, ApiError} $result
	 * @return array{true, Payment}|array{false, ApiError}
	 */
	private function paymentResult( array $result, bool $uncertain = false ): array {
		if ( ! $result[0] ) { return $result; }
		$payment = ResponseValidator::normalizePayment( $result[1] );
		if ( ResponseValidator::payment( $payment ) ) { return [ true, $payment ]; }
		return [ false, [ 'message' => 'Invalid payment response from ICEPAY. Invalid fields: '
			. implode( ', ', ResponseValidator::invalidPaymentFields( $payment ) ), 'uncertain' => $uncertain ] ];
	}

	/** @param array<string, mixed>|null $body
	 * @return array{true, array<string, mixed>}|array{false, ApiError}
	 */
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

		if ( ! is_array( $responseBody ) || array_is_list( $responseBody ) || count( array_filter( array_keys( $responseBody ), 'is_string' ) ) !== count( $responseBody ) ) {
			return [ false, [ 'message' => 'Invalid JSON response from ICEPAY.', 'uncertain' => $method === 'POST' ] ];
		}

		return [ true, $responseBody ];
	}

	protected function toBasicAuthorization(): string {
		return 'Basic ' . base64_encode( $this->merchantId . ':' . $this->secretCode );
	}
}
