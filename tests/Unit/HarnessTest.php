<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Tests\Unit;

use Icepay\WooCommerce\Tests\Support\WpStubs;
use Icepay\WooCommerce\Icepay;
use PHPUnit\Framework\TestCase;

class HarnessTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	/** @test */
	public function itLetsATestStubAWordpressGlobalFunctionAndObserveTheResult(): void {
		WpStubs::set( 'wp_remote_request', fn( string $url, array $args = [] ) => [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '{"status":"ok"}',
		] );

		$result = \Icepay\WooCommerce\wp_remote_request( 'https://example.com', [] );

		$this->assertSame( 200, $result['response']['code'] );

		$calls = WpStubs::calls( 'wp_remote_request' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://example.com', $calls[0][0] );
	}

	/** @test */
	public function itLetsATestControlIcepayStaticSettingsAccessors(): void {
		WpStubs::set( 'get_option', fn( string $key, mixed $default = null ) => match ( $key ) {
			Icepay::MERCHANT_ID => 'test-merchant',
			Icepay::SECRET      => 'test-secret',
			default             => $default,
		} );

		$this->assertSame( 'test-merchant', Icepay::getMerchantId() );
		$this->assertSame( 'test-secret', Icepay::getSecret() );
	}
}
