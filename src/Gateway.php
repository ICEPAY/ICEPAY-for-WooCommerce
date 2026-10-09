<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use WC_Payment_Gateway;
use WP_Error;

class Gateway extends WC_Payment_Gateway {
	public function __construct(
		protected PaymentMethod $paymentMethod,
		protected Log $log = new Log(),
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
		$orderId = (int) $order_id;
		if ( ! OrderLock::acquire( $orderId ) ) {
			return $this->paymentFailure( __( 'Your payment is already being processed. Please wait before trying again.',
				'icepay-for-woocommerce' ) );
		}
		try {
			return $this->processPaymentLocked( $orderId );
		} catch ( \Throwable $exception ) {
			$this->log->error( 'Payment processing failed.',
				[ 'order' => $orderId, 'message' => $exception->getMessage() ] );

			return $this->paymentFailure( __( 'We could not confirm your payment. Please check your order before trying again.',
				'icepay-for-woocommerce' ) );
		} finally {
			OrderLock::release( $orderId );
		}
	}

	protected function processPaymentLocked( int $order_id ): array {
		$this->log->info( 'Processing payment for order ' . $order_id );
		$client = new IcepayClient(
			Icepay::getMerchantId(),
			Icepay::getSecret(),
		);

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->paymentFailure( __( 'This order could not be found.', 'icepay-for-woocommerce' ) );
		}
		if ( $order->is_paid() || $order->get_date_paid() ) {
			return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
		}
		if ( $order->has_status( 'refunded' ) ) {
			return $this->paymentFailure( __( 'This order has been refunded and cannot be paid again.',
				'icepay-for-woocommerce' ) );
		}
		if ( $order->get_meta( '_icepay_creation_uncertain' ) ) {
			return $this->paymentFailure( __( 'We could not confirm whether your payment was started. Please contact the store so we can check before you pay again.',
				'icepay-for-woocommerce' ) );
		}
		foreach ( Order::getAttempts( $order ) as $key => $attempt ) {
			[ $success, $existing ] = $client->get( $key );
			if ( ! $success || ( $existing['key'] ?? '' ) !== $key ) {
				return $this->paymentFailure( __( 'We are still confirming your previous payment. Please wait before trying again.',
					'icepay-for-woocommerce' ) );
			}
			$result = ( new PaymentStatus() )->applyLocked( $order, $existing );
			if ( is_wp_error( $result ) ) {
				$this->log->warning( $result->get_error_message(), [ 'order' => $order_id, 'key' => $key ] );

				return $this->paymentFailure( __( 'Your previous payment needs to be checked. Please contact the store.',
					'icepay-for-woocommerce' ) );
			}
			if ( $existing['status'] === 'completed' || $existing['status'] === 'pending' ) {
				return [ 'result' => 'success', 'redirect' => $this->getRedirectUrl( $order ) ];
			}
			// Started attempts remain open. Each eligible checkout creates a fresh
			// payment for the selected method instead of reusing an earlier URL.
		}
		if ( Icepay::getMerchantId() === '' || Icepay::getSecret() === '' ) {
			return $this->paymentFailure( __( 'This payment method is temporarily unavailable. Please choose another payment method.',
				'icepay-for-woocommerce' ) );
		}
		$reference = str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() );
		// Persist before sending: a crash or timeout must not permit a blind second POST.
		$creationToken = wp_generate_uuid4();
		$order->update_meta_data( '_icepay_creation_uncertain', [
			'token'    => $creationToken,
			'merchant' => Icepay::getMerchantId(),
			'amount'   => (int) round( (float) $order->get_total() * 100 ),
			'currency' => $order->get_currency(),
			'method'   => $this->paymentMethod->getType(),
			'created'  => time(),
		] );
		$order->save();

		[ $isSuccessful, $payment ] = $client->create(
			[
				'reference'     => $reference,
				'amount'        => [
					'value'    => (int) round( $order->get_total() * 100 ),
					'currency' => $order->get_currency(),
				],
				'paymentMethod' => [
					'type' => $this->paymentMethod->getType(),
				],
				'customer'      => [
					'email'   => $this->limit( $order->get_billing_email(), 128 ),
					'address' => [
						'country'     => $this->limit( $order->get_billing_country(), 2 ),
						'city'        => $this->limit( $order->get_billing_city(), 254 ),
						'postalCode'  => $this->limit( $order->get_billing_postcode(), 31 ),
						'streetName'  => $this->limit( $order->get_billing_address_1(), 128 ),
						'houseNumber' => $this->limit( $order->get_billing_address_2(), 31 ),
					]
				],
				'expireAfter'   => Icepay::getExpireAfter(),
				'webhookUrl'    => add_query_arg( 'wc-api', 'icepay-webhook', home_url( '/' ) ),
				'redirectUrl'   => $this->getRedirectUrl( $order ),
				'meta'          => [
					'integration' => [
						'type'      => 'woocommerce',
						'version'   => Integration::VERSION,
						'developer' => 'ICEPAY',
					],
					'woocommerce' => [
						'order_id'       => $order->get_id(),
						'creation_token' => $creationToken,
					]
				]
			]
		);

		if ( ! $isSuccessful ) {
			$this->log->error( 'Unable to create payment ', $payment );

			if ( empty( $payment['uncertain'] ) ) {
				$order->delete_meta_data( '_icepay_creation_uncertain' );
				$order->save();

				return $this->paymentFailure( __( 'We could not start your payment. Please try again or choose another payment method.',
					'icepay-for-woocommerce' ) );
			}
			$order->add_order_note( __( 'ICEPAY payment creation has an unknown outcome. Check the ICEPAY Portal before retrying; clear _icepay_creation_uncertain only after reconciliation.',
				'icepay-for-woocommerce' ) );

			return $this->paymentFailure( __( 'We could not confirm whether your payment was started. Please contact the store before paying again.',
				'icepay-for-woocommerce' ) );
		}

		if ( ! is_string( $payment['key'] ?? null ) || $payment['key'] === '' ) {
			return $this->paymentFailure( __( 'We could not confirm whether your payment was started. Please contact the store before paying again.',
				'icepay-for-woocommerce' ) );
		}
		Order::addAttempt( $order, $payment, $this->paymentMethod->getType() );
		$result = ( new PaymentStatus() )->applyLocked( $order, $payment );
		$url    = PaymentStatus::redirectUrl( $payment );
		if ( is_wp_error( $result ) || $url === '' ) {
			return $this->paymentFailure( __( 'We could not open your payment page. Please check your order before trying again.',
				'icepay-for-woocommerce' ) );
		}
		$this->log->info( 'Created payment', [ 'order' => $order_id, 'key' => $payment['key'] ] );

		return [
			'result'   => 'success',
			'redirect' => $url
		];
	}

	protected function paymentFailure( string $message ): array {
		wc_add_notice( $message, 'error' );

		return [ 'result' => 'failure' ];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( '1', 'Unable to refund order, could not to find order' );
		}

		$paymentKey = $order->get_transaction_id();

		if ( ! $paymentKey ) {
			return new WP_Error( '1', 'Unable to refund order, could not find payment key related to order' );
		}

		$client = new IcepayClient(
			Icepay::getMerchantId(),
			Icepay::getSecret(),
		);

		[ $isSuccessful, $refund ] = $client->refund( $paymentKey, [
			'amount'      => [
				'value'    => (int) round( $amount * 100 ),
				'currency' => $order->get_currency(),
			],
			'reference'   => $reason,
			'description' => $reason,
		] );

		if ( ! $isSuccessful ) {
			$this->log->error( 'Unable to refund payment for #' . $order_id, $refund );

			return new WP_Error( '1', 'Unable to refund order, could not refund payment. ' . $refund['message'] );
		}

		return true;
	}

	protected function getRedirectUrl( $order ): string {
		$url = untrailingslashit( $this->get_return_url( $order ) );

		return add_query_arg( [
			'via-icepay' => true,
		], $url );
	}

	protected function limit( $value, $limit = 100, $end = '' ): string {
		if ( mb_strwidth( $value, 'UTF-8' ) <= $limit ) {
			return $value;
		}

		return rtrim( mb_strimwidth( $value, 0, $limit, '', 'UTF-8' ) ) . $end;
	}
}
