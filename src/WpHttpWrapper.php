<?php
/**
 * WpHttpWrapper class for WooCommerce ICEPAY integration.
 *
 * @package Icepay\WooCommerce
 */

namespace Icepay\WooCommerce;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class WpHttpWrapper
 *
 * Wraps WordPress HTTP API for PSR-18 compatibility.
 */
class WpHttpWrapper implements ClientInterface {

    /**
     * Send a PSR-7 request using WordPress HTTP API.
     *
     * @param RequestInterface $request Request object.
     * @return ResponseInterface
     * @throws \RuntimeException When HTTP request fails.
     */
    public function sendRequest( RequestInterface $request ): ResponseInterface {
        $args = array(
            'method'  => $request->getMethod(),
            'headers' => $request->getHeaders(),
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
