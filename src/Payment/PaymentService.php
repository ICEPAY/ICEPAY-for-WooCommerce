<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Payment;

use Icepay\WooCommerce\Api\IcepayClient;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Log;
use Icepay\WooCommerce\PaymentMethod;
use WP_Error;

class PaymentService {
	protected IcepayClient $client;
	protected PaymentStatusHandler $statusHandler;
	protected Log $log;

	public function __construct( ?IcepayClient $client = null, ?PaymentStatusHandler $statusHandler = null,
		protected PaymentRequestBuilder $requestBuilder = new PaymentRequestBuilder(), ?Log $log = null ) {
		$this->client = $client ?? IcepayClient::fromSettings();
		$this->statusHandler = $statusHandler ?? new PaymentStatusHandler( $this->client );
		$this->log = $log ?? new Log();
	}

	/** @return array{result: 'success', redirect: string}|WP_Error */
	public function process( int $id, PaymentMethod $method, string $returnUrl ): array|WP_Error {
		if ( ! OrderLock::acquire( $id ) ) {
			return new WP_Error( 'busy', __( 'Your payment is already being processed. Please wait before trying again.', 'icepay-for-woocommerce' ) );
		}
		try {
			return $this->processLocked( $id, $method, $returnUrl );
		} catch ( \Throwable $exception ) {
			$this->log->error( 'Payment processing failed.', [ 'order' => $id, 'message' => $exception->getMessage() ] );
			return new WP_Error( 'payment_error', __( 'We could not confirm your payment. Please check your order before trying again.', 'icepay-for-woocommerce' ) );
		} finally {
			OrderLock::release( $id );
		}
	}

	/** @return array{result: 'success', redirect: string}|WP_Error */
	private function processLocked( int $id, PaymentMethod $method, string $returnUrl ): array|WP_Error {
		$this->log->info( 'Processing payment for order ' . $id );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return new WP_Error( 'missing_order', __( 'This order could not be found.', 'icepay-for-woocommerce' ) );
		}
		if ( $order->is_paid() || $order->get_date_paid() ) {
			return [ 'result' => 'success', 'redirect' => $returnUrl ];
		}
		if ( $order->has_status( 'refunded' ) ) {
			return new WP_Error( 'refunded', __( 'This order has been refunded and cannot be paid again.', 'icepay-for-woocommerce' ) );
		}
		if ( $order->get_meta( '_icepay_creation_uncertain' ) ) {
			return new WP_Error( 'uncertain', __( 'We could not confirm whether your payment was started. Please contact the store so we can check before you pay again.', 'icepay-for-woocommerce' ) );
		}
		$pending = false;
		$verificationError = null;
		foreach ( PaymentAttempts::getAttempts( $order ) as $key => $attempt ) {
			[ $success, $existing ] = $this->client->get( $key );
			if ( ! $success || ( $existing['key'] ?? '' ) !== $key ) {
				$verificationError = new WP_Error( 'unavailable', __( 'We are still confirming your previous payment. Please wait before trying again.', 'icepay-for-woocommerce' ) );
				continue;
			}
			$result = $this->statusHandler->applyLocked( $order, $existing );
			// The handler can complete or refund the order; re-read its persisted state.
			$order = wc_get_order( $id );
			if ( ! $order ) { return new WP_Error( 'missing_order', 'Order no longer exists.' ); }
			if ( is_wp_error( $result ) ) {
				$this->log->warning( $result->get_error_message(), [ 'order' => $id, 'key' => $key ] );
				$verificationError = new WP_Error( 'invalid_payment', __( 'Your previous payment needs to be checked. Please contact the store.', 'icepay-for-woocommerce' ) );
				continue;
			}
			if ( $order->is_paid() || $order->get_date_paid() ) {
				return [ 'result' => 'success', 'redirect' => PaymentRequestBuilder::returnUrl( $returnUrl ) ];
			}
			$pending = $pending || in_array( $existing['status'], [ 'completed', 'pending' ], true );
			// Leave started payments open; every eligible submission creates a fresh payment.
		}
		if ( $pending ) {
			return [ 'result' => 'success', 'redirect' => PaymentRequestBuilder::returnUrl( $returnUrl ) ];
		}
		if ( $verificationError !== null ) {
			return $verificationError;
		}
		if ( Icepay::getMerchantId() === '' || Icepay::getSecret() === '' ) {
			return new WP_Error( 'configuration', __( 'This payment method is temporarily unavailable. Please choose another payment method.', 'icepay-for-woocommerce' ) );
		}
		if ( ! $method->isEligible( $order->get_billing_country(), (float) $order->get_total() ) ) {
			return new WP_Error( 'restricted_method', __( 'This payment method is not available for your billing country or order amount. Please choose another method.', 'icepay-for-woocommerce' ) );
		}
		$token = wp_generate_uuid4();
		$order->update_meta_data( '_icepay_creation_uncertain', [
			'token' => $token, 'merchant' => Icepay::getMerchantId(),
			'amount' => (int) round( (float) $order->get_total() * 100 ), 'currency' => $order->get_currency(),
			'method' => $method->getType(), 'created' => time(),
		] );
		$order->save();
		[ $success, $payment ] = $this->client->create( $this->requestBuilder->build( $order, $method, $returnUrl, $token ) );
		if ( ! $success ) {
			$this->log->error( 'Unable to create payment', $payment );
			if ( empty( $payment['uncertain'] ) ) {
				$order->delete_meta_data( '_icepay_creation_uncertain' );
				$order->save();
				return new WP_Error( 'creation_failed', __( 'We could not start your payment. Please try again or choose another payment method.', 'icepay-for-woocommerce' ) );
			}
			$order->add_order_note( __( 'ICEPAY payment creation has an unknown outcome. Check the ICEPAY Portal before retrying; clear _icepay_creation_uncertain only after reconciliation.', 'icepay-for-woocommerce' ) );
			return new WP_Error( 'uncertain', __( 'We could not confirm whether your payment was started. Please contact the store before paying again.', 'icepay-for-woocommerce' ) );
		}
		PaymentAttempts::addAttempt( $order, $payment, $method->getType(), $token );
		$result = $this->statusHandler->applyLocked( $order, $payment );
		$url = PaymentStatusHandler::redirectUrl( $payment );
		if ( is_wp_error( $result ) || $url === '' ) {
			return new WP_Error( 'redirect_failed', __( 'We could not open your payment page. Please check your order before trying again.', 'icepay-for-woocommerce' ) );
		}
		$this->log->info( 'Created payment', [ 'order' => $id, 'key' => $payment['key'] ] );
		return [ 'result' => 'success', 'redirect' => $url ];
	}
}
