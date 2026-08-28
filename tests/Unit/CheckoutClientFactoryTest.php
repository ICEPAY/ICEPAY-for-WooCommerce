<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit;

use ICEPAY\Checkout\CheckoutClient;
use Icepay\WooCommerce\CheckoutClientFactory;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

class CheckoutClientFactoryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::set( 'get_option', fn( string $key, mixed $default = null ) => match ( $key ) {
			Icepay::MERCHANT_ID => '12345',
			Icepay::SECRET      => 'super-secret',
			default             => $default,
		} );
		$this->stubSuccessResponse();
	}

	private function stubSuccessResponse(): void {
		WpStubs::set( 'wp_remote_request', fn( string $url, array $args = [] ) => [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '[]',
			'headers'  => [],
		] );
		WpStubs::set( 'is_wp_error', fn( mixed $thing ) => false );
		WpStubs::set( 'wp_remote_retrieve_response_code', fn( mixed $r ) => 200 );
		WpStubs::set( 'wp_remote_retrieve_response_message', fn( mixed $r ) => 'OK' );
		WpStubs::set( 'wp_remote_retrieve_body', fn( mixed $r ) => '[]' );
		WpStubs::set( 'wp_remote_retrieve_headers', fn( mixed $r ) => [] );
	}

	/** @test */
	public function itAuthorisesRequestsWithTheConfiguredMerchantIdAndSecret(): void {
		$factory = new CheckoutClientFactory();

		$factory->create()->getPaymentMethods();

		$calls                 = WpStubs::calls( 'wp_remote_request' );
		$expectedAuthorization = 'Basic ' . base64_encode( '12345:super-secret' );
		$this->assertSame( $expectedAuthorization, $calls[0][1]['headers']['Authorization'] );
	}

	/** @test */
	public function itRoutesRequestsThroughTheWordPressHttpTransport(): void {
		$factory = new CheckoutClientFactory();

		$factory->create()->getPaymentMethods();

		$this->assertTrue( WpStubs::wasCalled( 'wp_remote_request' ) );
	}

	/** @test */
	public function itReturnsAUsableClientWhenCredentialsAreConfigured(): void {
		$factory = new CheckoutClientFactory();

		$client = $factory->create();

		$this->assertInstanceOf( CheckoutClient::class, $client );
	}
}
