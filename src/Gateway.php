<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use ICEPAY\Checkout\Exceptions\ApiException;
use ICEPAY\Checkout\Models\Amount;
use ICEPAY\Checkout\Models\Request\Checkout as CheckoutRequest;
use ICEPAY\Checkout\Models\Request\Refund as RefundRequest;
use WC_Payment_Gateway;
use WP_Error;

class Gateway extends WC_Payment_Gateway {
	public function __construct(
		protected PaymentMethod $paymentMethod,
		protected Log $log = new Log(),
		protected CheckoutClientFactory $clientFactory = new CheckoutClientFactory(),
	) {
		$this->id                 = $this->paymentMethod->getId();
		$this->icon               = $this->paymentMethod->getIcon();
		$this->method_title       = 'ICEPAY ' . $this->paymentMethod->getName();
		$this->method_description = $this->paymentMethod->getDefaultDescription();;
		$this->title       = $this->paymentMethod->getName();
		$this->description = $this->paymentMethod->getDescription();
		$this->enabled     = $this->paymentMethod->isEnabled();
		$this->form_fields = $this->paymentMethod->getFormFields();
		$this->plugin_id   = Integration::ID . '_';

		$this->supports = [
			'products',
			'refunds',
		];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'display_errors' ] );
	}

	protected function getData( $key ): mixed {
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}

		return map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' );
	}

	public function process_payment( $order_id ): array {
		$this->log->info( 'Processing payment for order ' . $order_id );

		$order     = wc_get_order( $order_id );
		$reference = str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() );

		$req = new CheckoutRequest(
			reference:     $reference,
			amount:        new Amount( (int) round( $order->get_total() * 100 ), $order->get_currency() ),
			redirectUrl:   $this->getRedirectUrl( $order ),
			webhookUrl:    add_query_arg( 'wc-api', 'icepay-webhook', home_url( '/' ) ),
			paymentMethod: $this->paymentMethod->getType(),
			expireAfter:   Icepay::getExpireAfter(),
		);

		$req->withCustomer( [
			'email'   => $this->limit( $order->get_billing_email(), 128 ),
			'address' => [
				'country'     => $this->limit( $order->get_billing_country(), 2 ),
				'city'        => $this->limit( $order->get_billing_city(), 254 ),
				'postalCode'  => $this->limit( $order->get_billing_postcode(), 31 ),
				'streetName'  => $this->limit( $order->get_billing_address_1(), 128 ),
				'houseNumber' => $this->limit( $order->get_billing_address_2(), 31 ),
			],
		] );

		$req->withIntegrationInformation( 'woocommerce', Integration::VERSION, 'ICEPAY' );

		try {
			$response = $this->clientFactory->create()->createCheckout( $req );
		} catch ( ApiException $e ) {
			$this->log->error( 'Unable to create payment', [
				'message' => $e->getMessage(),
				'type'    => $e->type,
				'code'    => $e->getCode(),
			] );

			return [ 'result' => 'failure' ];
		}

		if ( $response->links->direct === null ) {
			$this->log->error( 'Unable to create payment, response did not contain a redirect link', [
				'key' => $response->key,
			] );

			return [ 'result' => 'failure' ];
		}

		$this->addPaymentKey( $order, $response->key );
		$this->log->info( 'Create payment' );

		return [
			'result'   => 'success',
			'redirect' => esc_url_raw( $response->links->direct ),
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( '1', 'Unable to refund order, could not to find order' );
		}

		$paymentKey = $order->get_meta( 'icepay-payment-key' );

		if ( ! $paymentKey ) {
			return new WP_Error( '1', 'Unable to refund order, could not find payment key related to order' );
		}

		if ( $amount === null || $amount <= 0 ) {
			return new WP_Error( '1', 'Unable to refund order, refund amount must be greater than zero' );
		}

		$refundRequest = new RefundRequest(
			reference:   $reason,
			amount:      new Amount( (int) round( $amount * 100 ), $order->get_currency() ),
			description: $reason,
		);

		try {
			$this->clientFactory->create()->refund( $refundRequest, $paymentKey );
		} catch ( ApiException $e ) {
			$this->log->error( 'Unable to refund payment for #' . $order_id, [
				'message' => $e->getMessage(),
				'type'    => $e->type,
				'code'    => $e->getCode(),
			] );

			return new WP_Error( '1', 'Unable to refund order, could not refund payment. ' . $e->getMessage() );
		}

		return true;
	}

	protected function getRedirectUrl( $order ): string {
		$url = untrailingslashit( $this->get_return_url( $order ) );

		return add_query_arg( [
			'key'        => $order->get_order_key(),
			'via-icepay' => true,
		], $url );
	}

	protected function addPaymentKey( $order, string $key ): void {
		$currentKey = $order->get_meta( 'icepay-payment-key' );
		if ( $currentKey === $key ) {
			return;
		}

		/* translators: 1: ICEPAY Checkout Key */
		$order->add_order_note(
			sprintf(
				__( 'ICEPAY payment created with key: %1$s', 'icepay-for-woocommerce' ),
				$key
			)
		);

		$order->update_meta_data( 'icepay-payment-key', $key );
		$order->save();
	}

	protected function limit( $value, $limit = 100, $end = '' ): string {
		if ( mb_strwidth( $value, 'UTF-8' ) <= $limit ) {
			return $value;
		}

		return rtrim( mb_strimwidth( $value, 0, $limit, '', 'UTF-8' ) ) . $end;
	}
}
