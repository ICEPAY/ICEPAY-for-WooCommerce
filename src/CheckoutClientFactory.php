<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use ICEPAY\Checkout\CheckoutClient;
use ICEPAY\Checkout\HttpClient;
use Icepay\WooCommerce\Http\WordPressHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

class CheckoutClientFactory {

	public function create(): CheckoutClient {
		$psr17      = new Psr17Factory();
		$httpClient = new HttpClient( new WordPressHttpClient(), $psr17, $psr17 );

		return ( new CheckoutClient( $httpClient ) )->withAuthorization( Icepay::getMerchantId(), Icepay::getSecret() );
	}
}
