<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Payment\PaymentService;
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

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( '1', 'Unable to refund order, could not to find order' );
		}
		$key = $order->get_transaction_id();
		if ( ! $key ) {
			return new WP_Error( '1', 'Unable to refund order, could not find payment key related to order' );
		}
		[ $success, $refund ] = $this->client->refund( $key, [
			'amount' => [ 'value' => (int) round( $amount * 100 ), 'currency' => $order->get_currency() ],
			'reference' => $reason, 'description' => $reason,
		] );
		if ( ! $success ) {
			$this->log->error( 'Unable to refund payment for #' . $order_id, $refund );
			return new WP_Error( '1', 'Unable to refund order, could not refund payment. ' . $refund['message'] );
		}
		return true;
	}
}
