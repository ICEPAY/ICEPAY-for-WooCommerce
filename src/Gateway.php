<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Payment\PaymentService;
use Icepay\WooCommerce\Payment\RefundService;
use WC_Payment_Gateway;
use WP_Error;

class Gateway extends WC_Payment_Gateway {
	protected PaymentService $payments;
	protected IcepayClient $client;

	public function __construct( protected PaymentMethod $paymentMethod, protected Log $log = new Log(),
		?PaymentService $payments = null, ?IcepayClient $client = null ) {
		$this->client = $client ?? IcepayClient::fromSettings();
		$this->payments = $payments ?? new PaymentService( $this->client, log: $this->log );
		$this->id = $paymentMethod->getId();
		$this->icon = $paymentMethod->getIcon();
		$this->method_title = 'ICEPAY ' . $paymentMethod->getName();
		$this->method_description = $paymentMethod->getDefaultDescription();
		$this->title = $paymentMethod->getName();
		$this->description = $paymentMethod->getDescription();
		$this->enabled = $paymentMethod->isEnabled();
		$this->form_fields = $paymentMethod->getFormFields();
		$this->plugin_id = Integration::ID . '_';
		$this->supports = [ 'products', 'refunds' ];
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, function (): void { $this->process_admin_options(); } );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'display_errors' ] );
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		$result = $this->payments->process( (int) $order_id, $this->paymentMethod, $this->get_return_url( $order ?: null ) );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return [ 'result' => 'failure' ];
		}
		return $result;
	}

	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			return $order && $this->paymentMethod->isEligible( $order->get_billing_country(), (float) $order->get_total() );
		}
		return ! WC()->cart || $this->paymentMethod->isEligible(
			WC()->customer ? WC()->customer->get_billing_country() : '', (float) WC()->cart->get_total( '' ) );
	}

	public function validate_countries_field( $key, $value ): array {
		$countries = (array) $value;
		if ( array_diff( $countries, array_keys( WC()->countries->get_countries() ) ) ) {
			throw new \Exception( __( 'Select valid billing countries.', 'icepay-for-woocommerce' ) );
		}
		return array_values( array_unique( $countries ) );
	}

	public function validate_minimum_field( $key, $value ): string {
		$minimum = $this->validateAmountLimit( $value );
		$data = $this->get_post_data();
		$maximum = $this->validateAmountLimit( $data[ $this->get_field_key( 'maximum' ) ] ?? '' );
		if ( $minimum !== '' && $maximum !== '' && (float) $minimum > (float) $maximum ) {
			throw new \Exception( __( 'The minimum order amount cannot exceed the maximum.', 'icepay-for-woocommerce' ) );
		}
		return $minimum;
	}

	public function validate_maximum_field( $key, $value ): string {
		$maximum = $this->validateAmountLimit( $value );
		$data = $this->get_post_data();
		$minimum = $this->validateAmountLimit( $data[ $this->get_field_key( 'minimum' ) ] ?? '' );
		if ( $minimum !== '' && $maximum !== '' && (float) $minimum > (float) $maximum ) {
			throw new \Exception( __( 'The minimum order amount cannot exceed the maximum.', 'icepay-for-woocommerce' ) );
		}
		return $maximum;
	}

	private function validateAmountLimit( mixed $value ): string {
		if ( is_string( $value ) && trim( $value ) === '' ) {
			return '';
		}
		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( ! preg_match( '/^\d+(?:[.,]\d{1,2})?$/D', $raw ) ) {
			throw new \Exception( __( 'Order amount limits must be non-negative numbers with at most two decimal places, or empty.', 'icepay-for-woocommerce' ) );
		}
		$decimal = str_replace( ',', '.', $raw );
		if ( ! is_numeric( $decimal ) || ! is_finite( (float) $decimal ) || (float) $decimal < 0 ) {
			throw new \Exception( __( 'Order amount limits must be non-negative numbers or empty.', 'icepay-for-woocommerce' ) );
		}
		return wc_format_decimal( $decimal, 2 );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
		return ( new RefundService( $this->client, $this->log ) )->process( (int) $order_id, $amount, (string) $reason );
	}
}
