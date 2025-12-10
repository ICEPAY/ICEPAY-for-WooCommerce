<?php

namespace Icepay\WooCommerce;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WpHttpWrapper implements ClientInterface
{

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $args = [
            'method' => $request->getMethod(),
            'headers' => $request->getHeaders(),
        ];

        if ($request->getBody()->getSize() > 0) {
            $args['body'] = (string)$request->getBody();
        }

        $response = wp_remote_request((string)$request->getUri(), $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('HTTP request failed: ' . $response->get_error_message());
        }
        $statusCode = wp_remote_retrieve_response_code($response);
        $statusMessage = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        return new Response($statusCode, $headers->toArray(), $body, '1.1', $statusMessage);
    }
}
