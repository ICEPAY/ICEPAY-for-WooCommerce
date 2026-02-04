<?php
namespace Icepay\WooCommerce;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WpHttpWrapper implements ClientInterface {
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		// Convert PSR-7 headers (array of values) to WP expected string values.
		$wp_headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			// Skip Host, WP/cURL sets it automatically.
			if ( strtolower( $name ) === 'host' ) {
				continue;
			}
			// Implode multiple header values into a single string.
			$wp_headers[ $name ] = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
		}

		$args = array(
			'method'  => $request->getMethod(),
			'headers' => $wp_headers,
		);

		if ( 0 < $request->getBody()->getSize() ) {
			$args['body'] = (string) $request->getBody();
		}

		$response = wp_remote_request( (string) $request->getUri(), $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'HTTP request failed: ' . $response->get_error_message() );
		}

		$status_code    = wp_remote_retrieve_response_code( $response );
		$status_message = wp_remote_retrieve_response_message( $response );
		$body           = wp_remote_retrieve_body( $response );
		$headers        = wp_remote_retrieve_headers( $response );

		return new Response( $status_code, $headers->getAll(), $body, '1.1', $status_message );
	}
}
