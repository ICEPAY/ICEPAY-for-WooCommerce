<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use ICEPAY\Checkout\Models\Amount;
use ICEPAY\Checkout\Models\Request\Refund as RefundRequest;
use ICEPAY\Checkout\Models\Request\Checkout as CheckoutRequest;
use WC_Payment_Gateway;
use WP_Error;

class Gateway extends WC_Payment_Gateway {
	public function __construct(
		protected PaymentMethod $paymentMethod,
		protected Log $log = new Log(),
	) {
		$this->id                 = $this->paymentMethod->getId();
		$this->icon               = $this->paymentMethod->getIcon();
		$this->method_title       = 'ICEPAY ' . $this->paymentMethod->getDefaultName();
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

		return map_deep( $_POST[ $key ], 'sanitize_text_field' );
	}

	public function process_payment( $order_id ): array {
		$this->log->info( 'Processing payment for order ' . $order_id );

        $checkoutClient = Icepay::getCheckoutClient();

		$order     = wc_get_order( $order_id );
		$reference = str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() );

        $checkoutRequest = (new CheckoutRequest(
            reference: $reference,
			description: $reference,
            amount: new Amount(
                value: (int) round($order->get_total() * 100),
                currency: $order->get_currency()
            ),
            redirectUrl: $this->getRedirectUrl( $order ),
            webhookUrl: add_query_arg( 'wc-api', 'icepay-webhook', home_url( '/' ) ),
            paymentMethod: new \ICEPAY\Checkout\Models\PaymentMethod(
                type: $this->paymentMethod->getType()
            ),
        ))
            ->withCustomerEmail($order->get_billing_email())
            ->withIntegrationInformation
            (
                type: 'woocommerce',
                version: Integration::VERSION,
                developer: 'ICEPAY'
            );

        try {
            $payment = $checkoutClient->createCheckout( $checkoutRequest );
        }catch ( \Exception $exception ) {
            $this->log->error('Unable to create payment for order #' . $order_id, [
				'message' => $exception->getMessage()
            ]);

            return [ 'result' => 'failure' ];
        }

		$this->addPaymentKey( $order, $payment->key );
		$this->log->info( 'Create payment', (array)$payment );

		return [
			'result'   => 'success',
			'redirect' => esc_url_raw( $payment->links->direct )
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

        $checkoutClient = Icepay::getCheckoutClient();

        $refundRequest = new RefundRequest(
            reference: $reason,
            amount: (int) round($amount * 100),
            description: $reason
        );

        try {
            $refundResponse = $checkoutClient->refund($refundRequest, $paymentKey);
        }
        catch ( \Exception $exception ) {
            $this->log->error( 'Unable to refund payment for #' . $order_id, ['message' => $exception->getMessage()] );

            return new WP_Error( '1', 'Unable to refund order, could not refund payment. ' . $exception->getMessage() );
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

		$order->add_order_note(
			sprintf(
				__( 'ICEPAY payment created with key: %1$s', Integration::ID ),
				$key
			)
		);

		$order->update_meta_data( 'icepay-payment-key', $key );
		$order->save();
	}
}
