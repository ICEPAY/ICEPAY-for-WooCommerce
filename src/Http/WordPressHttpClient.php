<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class WordPressHttpClient implements ClientInterface {

	public function __construct(
		private readonly Psr17Factory $factory = new Psr17Factory(),
	) {
	}

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$args = [
			'method'      => $request->getMethod(),
			'headers'     => $this->flattenHeaders( $request->getHeaders() ),
			'sslverify'   => true,
			'timeout'     => 10,
			'httpversion' => '1.1',
		];

		$body = (string) $request->getBody();

		if ( $body !== '' ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( (string) $request->getUri(), $args );

		if ( is_wp_error( $response ) ) {
			throw new WordPressHttpClientException(
				$request,
				$response->get_error_message(),
			);
		}

		$statusCode    = (int) wp_remote_retrieve_response_code( $response );
		$reasonPhrase  = wp_remote_retrieve_response_message( $response );
		$responseBody  = wp_remote_retrieve_body( $response );
		$headers       = $this->normalizeHeaders( wp_remote_retrieve_headers( $response ) );

		$psrResponse = $this->factory->createResponse( $statusCode, $reasonPhrase )
			->withBody( $this->factory->createStream( $responseBody ) );

		foreach ( $headers as $name => $value ) {
			$psrResponse = $psrResponse->withHeader( $name, $value );
		}

		return $psrResponse;
	}

	/**
	 * @param  array<string, string[]>  $headers
	 * @return array<string, string>
	 */
	private function flattenHeaders( array $headers ): array {
		return array_map( fn( array $values ) => implode( ', ', $values ), $headers );
	}

	/**
	 * @param  mixed  $headers
	 * @return array<string, string>
	 */
	private function normalizeHeaders( mixed $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return $headers->getAll();
		}

		if ( is_array( $headers ) ) {
			return $headers;
		}

		return (array) $headers;
	}
}
