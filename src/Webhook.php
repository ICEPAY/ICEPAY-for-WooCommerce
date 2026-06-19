<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use ICEPAY\Checkout\Exceptions\InvalidSignature;
use ICEPAY\Checkout\Models\Status;
use ICEPAY\Checkout\PostbackHandler;

class Webhook {
	public function handle(): void {
		$log = new Log();
		$log->info( 'got postback via webhook' );

		if (
			! isset( $_SERVER['REQUEST_METHOD'] ) || ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) || ! isset( $_GET['wc-api'] )
			|| ( sanitize_text_field( wp_unslash( $_GET['wc-api'] ) ) !== 'icepay-webhook' )
		) {
			$log->error( 'invalid request method or wc-api' );

			return;
		}

		$body      = $this->getRequestBody();
		$headers   = array_change_key_case( $this->getHeader() ?: [] );
		$signature = $headers['icepay-signature'] ?? '';
		$handler   = new PostbackHandler( Icepay::getSecret() );

		try {
			$payment = $handler->handle( $body, $signature );
		} catch ( InvalidSignature ) {
			$log->warning( 'got postback, but could not validate it.' );
			status_header( 200 );
			$this->terminate();
			return;
		} catch ( \JsonException ) {
			$log->warning( 'got postback, but could not parse body.' );
			status_header( 200 );
			$this->terminate();
			return;
		}

		$log->info( 'got postback and could validate it.' );
		$order = Order::FindOrderByKey( $payment->key );

		if ( ! $order ) {
			$log->warning( 'Order not found' );
			status_header( 200 );
			$this->terminate();
			return;
		}

		$status = match ( $payment->status ) {
			Status::completed                    => 'processing',
			Status::cancelled, Status::expired   => 'cancelled',
			default                              => 'pending',
		};

		$paymentMethod = $order->get_payment_method();
		$orderStatus   = $order->get_status();

		if ( ! str_starts_with( $paymentMethod, 'icepay' ) && $status !== 'processing' ) {
			$log->info(
				'Order ' . $order->get_id() . ': ICEPAY webhook received, but payment was also started via ' .
				$paymentMethod . '. ICEPAY has not order status not updated.', [
					'order'         => $order->get_id(),
					'icepay status' => $status,
					'order status'  => $orderStatus,
				]
			);

			$order->add_order_note(
				sprintf(
				/* translators: 1: payment method used by the order */
					__(
						'ICEPAY received a webhook but is not currently used for the payment. The payment was also started via %s, so we did not update the order status.',
						'icepay-for-woocommerce'
					),
					$paymentMethod
				)
			);

			status_header( 200 );
			$this->terminate();
			return;
		}

		if ( $orderStatus === 'pending' || $orderStatus === 'on-hold' || $orderStatus === 'cancelled' || $orderStatus === 'checkout-draft' ) {
			$log->info( 'Updating ' . ( str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() ) ) . ' status to ' . $status . ' for ' . $payment->key );
			$order->update_status( $status );
			$order->set_transaction_id( $payment->key );
			$order->save();
		} else {
			$log->info(
				'Did not update '
				. ( str_replace( '{ORDER_ID}', $order->get_order_number(), Icepay::getDescription() ) )
				. ' status to ' . $status . ' for ' . $payment->key
				. 'because the current status was ' . $order->get_status()
			);
		}

		status_header( 200 );
		$this->terminate();
	}

	protected function getRequestBody(): string {
		return (string) file_get_contents( 'php://input' );
	}

	/** @return false|array */
	protected function getHeader(): false|array {
		if ( ! function_exists( 'getallheaders' ) ) {
			$headers = [];

			foreach ( $_SERVER as $name => $value ) {
				if ( str_starts_with( $name, 'HTTP_' ) ) {
					$headers[ str_replace( ' ', '-',
						ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}

			return $headers;
		}

		return getallheaders();
	}

	protected function terminate(): void {
		exit;
	}
}
