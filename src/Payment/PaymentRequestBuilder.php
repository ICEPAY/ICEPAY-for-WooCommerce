<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Integration;
use Icepay\WooCommerce\PaymentMethod;
use WC_Order;

class PaymentRequestBuilder {
	public function build( WC_Order $order, PaymentMethod $method, string $returnUrl, string $token ): array {
		return [
			'reference' => str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() ),
			'amount' => [ 'value' => (int) round( (float) $order->get_total() * 100 ), 'currency' => $order->get_currency() ],
			'paymentMethod' => [ 'type' => $method->getType() ],
			'customer' => [
				'email' => $this->limit( $order->get_billing_email(), 128 ),
				'address' => [
					'country' => $this->limit( $order->get_billing_country(), 2 ),
					'city' => $this->limit( $order->get_billing_city(), 254 ),
					'postalCode' => $this->limit( $order->get_billing_postcode(), 31 ),
					'streetName' => $this->limit( $order->get_billing_address_1(), 128 ),
					'houseNumber' => $this->limit( $order->get_billing_address_2(), 31 ),
				],
			],
			'expireAfter' => Icepay::getExpireAfter(),
			'webhookUrl' => add_query_arg( 'wc-api', 'icepay-webhook', home_url( '/' ) ),
			'redirectUrl' => self::returnUrl( $returnUrl ),
			'meta' => [
				'integration' => [ 'type' => 'woocommerce', 'version' => Integration::VERSION, 'developer' => 'ICEPAY' ],
				'woocommerce' => [ 'order_id' => $order->get_id(), 'creation_token' => $token ],
			],
		];
	}

	public static function returnUrl( string $url ): string {
		return add_query_arg( 'via-icepay', true, untrailingslashit( $url ) );
	}

	private function limit( string $value, int $limit ): string {
		return mb_strwidth( $value, 'UTF-8' ) <= $limit ? $value : rtrim( mb_strimwidth( $value, 0, $limit, '', 'UTF-8' ) );
	}
}
