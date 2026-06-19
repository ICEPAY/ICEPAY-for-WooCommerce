<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit\Http;

use Icepay\WooCommerce\Http\WordPressHttpClient;
use Icepay\WooCommerce\Tests\Support\WpStubs;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class WordPressHttpClientTest extends TestCase {

	private WordPressHttpClient $client;
	private Psr17Factory $factory;

	protected function setUp(): void {
		WpStubs::reset();
		$this->factory = new Psr17Factory();
		$this->client  = new WordPressHttpClient( $this->factory );
	}

	private function stubSuccessResponse( int $status = 200, string $body = '{}', array $headers = [] ): void {
		WpStubs::set( 'wp_remote_request', fn( string $url, array $args = [] ) => [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => $headers,
		] );
		WpStubs::set( 'is_wp_error', fn( mixed $thing ) => false );
		WpStubs::set( 'wp_remote_retrieve_response_code', fn( mixed $r ) => $status );
		WpStubs::set( 'wp_remote_retrieve_response_message', fn( mixed $r ) => 'OK' );
		WpStubs::set( 'wp_remote_retrieve_body', fn( mixed $r ) => $body );
		WpStubs::set( 'wp_remote_retrieve_headers', fn( mixed $r ) => $headers );
	}

	/** @test */
	public function itSendsTheRequestMethodAndUrlToWpRemoteRequest(): void {
		$this->stubSuccessResponse();

		$request = $this->factory->createRequest( 'POST', 'https://checkout.icepay.com/api/payments' );

		$this->client->sendRequest( $request );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://checkout.icepay.com/api/payments', $calls[0][0] );
		$this->assertSame( 'POST', $calls[0][1]['method'] );
	}

	/** @test */
	public function itForwardsRequestHeadersToWpRemoteRequest(): void {
		$this->stubSuccessResponse();

		$request = $this->factory->createRequest( 'GET', 'https://example.com' )
			->withHeader( 'Authorization', 'Basic abc123' )
			->withHeader( 'Content-Type', 'application/json' );

		$this->client->sendRequest( $request );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertSame( 'Basic abc123', $calls[0][1]['headers']['Authorization'] );
		$this->assertSame( 'application/json', $calls[0][1]['headers']['Content-Type'] );
	}

	/** @test */
	public function itForwardsTheRequestBodyToWpRemoteRequest(): void {
		$this->stubSuccessResponse();

		$body    = $this->factory->createStream( '{"amount":100}' );
		$request = $this->factory->createRequest( 'POST', 'https://example.com' )
			->withBody( $body );

		$this->client->sendRequest( $request );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertSame( '{"amount":100}', $calls[0][1]['body'] );
	}

	/** @test */
	public function itReturnsAPsrResponseWithTheWpStatusCodeBodyAndHeaders(): void {
		$this->stubSuccessResponse( 201, '{"id":"pay-1"}', [] );
		WpStubs::set( 'wp_remote_retrieve_response_code', fn( mixed $r ) => 201 );
		WpStubs::set( 'wp_remote_retrieve_body', fn( mixed $r ) => '{"id":"pay-1"}' );
		WpStubs::set( 'wp_remote_retrieve_headers', fn( mixed $r ) => [ 'content-type' => 'application/json' ] );
		WpStubs::set( 'wp_remote_retrieve_response_message', fn( mixed $r ) => 'Created' );

		$request  = $this->factory->createRequest( 'POST', 'https://example.com' );
		$response = $this->client->sendRequest( $request );

		$this->assertSame( 201, $response->getStatusCode() );
		$this->assertSame( '{"id":"pay-1"}', (string) $response->getBody() );
		$this->assertSame( 'application/json', $response->getHeaderLine( 'content-type' ) );
	}

	/** @test */
	public function itThrowsAPsrClientExceptionWhenWpRemoteRequestReturnsAWpError(): void {
		$wpError = new \WP_Error( 'http_request_failed', 'Could not resolve host' );
		WpStubs::set( 'wp_remote_request', fn( string $url, array $args = [] ) => $wpError );
		WpStubs::set( 'is_wp_error', fn( mixed $thing ) => true );

		$request = $this->factory->createRequest( 'GET', 'https://example.com' );

		$this->expectException( \Psr\Http\Client\NetworkExceptionInterface::class );

		$this->client->sendRequest( $request );
	}

	/** @test */
	public function itSetsSslverifyTrueOnEveryRequest(): void {
		$this->stubSuccessResponse();

		$request = $this->factory->createRequest( 'GET', 'https://example.com' );

		$this->client->sendRequest( $request );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertTrue( $calls[0][1]['sslverify'] );
	}

	/** @test */
	public function itReadsTheRequestBodyFromThePsrStreamAndOmitsBodyForEmptyBodyRequests(): void {
		$this->stubSuccessResponse();

		$request = $this->factory->createRequest( 'GET', 'https://example.com' );

		$this->client->sendRequest( $request );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertArrayNotHasKey( 'body', $calls[0][1] );
	}

	/** @test */
	public function itReturnsANormalResponseNotAnExceptionForA4xxOr5xxHttpStatus(): void {
		$this->stubSuccessResponse( 404, 'Not Found' );
		WpStubs::set( 'wp_remote_retrieve_response_code', fn( mixed $r ) => 404 );
		WpStubs::set( 'wp_remote_retrieve_body', fn( mixed $r ) => 'Not Found' );
		WpStubs::set( 'wp_remote_retrieve_response_message', fn( mixed $r ) => 'Not Found' );

		$request = $this->factory->createRequest( 'GET', 'https://example.com/missing' );

		$response = $this->client->sendRequest( $request );

		$this->assertSame( 404, $response->getStatusCode() );
	}
}
