<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Icepay\WooCommerce\Admin\Settings;

class Integration {
	public const NAME = 'ICEPAY for WooCommerce';
	public const ID = 'icepay-for-woocommerce';
	public const VERSION = '1.1.2';

	public function __invoke(): void {
		$this->addSettings();
		$this->addGateways();

		$this->addGatewayFilters();

		$this->addBlocks();
		$this->addWebhook();

		$this->addCustomLinks();

		$this->addMultipleCardIcons();

		add_action( 'template_redirect', [ $this, 'redirect' ] );
		add_action( 'template_redirect', [ $this, 'retryPayment' ] );
		add_action( 'init', [ $this, 'removeScheduledReconciliation' ], 20 );
		add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'paymentReceivedText' ], 10, 2 );
	}

	/** One-time migration for jobs queued by the removed reconciliation fallback. */
	public function removeScheduledReconciliation(): void {
		$option = Integration::ID . '_reconciliation_removed';
		if ( get_option( $option ) || ! did_action( 'action_scheduler_init' )
		     || ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		// Null matches every argument set while keeping cleanup scoped to our group.
		as_unschedule_all_actions( 'icepay_reconcile_payment', null, 'icepay' );
		if ( ! as_has_scheduled_action( 'icepay_reconcile_payment', null, 'icepay' ) ) {
			update_option( $option, 'yes', false );
		}
	}

	public function redirect(): void {
		if ( ! isset( $_GET['via-icepay'] ) ) {
			return;
		}

		$orderKey = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order    = wc_get_order( wc_get_order_id_by_order_key( $orderKey ) );
		if ( ! $order || $orderKey === '' || ! hash_equals( $order->get_order_key(), $orderKey ) ) {
			wc_add_notice( __( 'This payment return link is invalid. Please check your order confirmation or contact the store.',
				'icepay-for-woocommerce' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		if ( ! Order::isIcepay( $order ) ) {
			if ( Order::activeKey( $order ) !== '' || $order->get_meta( '_icepay_creation_uncertain' ) ) {
				$this->redirectPaymentReturn( $order );
			}

			return;
		}
		$key = Order::activeKey( $order );
		if ( ! $order->is_paid() && ! $order->get_date_paid() && $key !== '' ) {
			try {
				$result = ( new PaymentStatus() )->refresh( $order, $key );
				if ( is_wp_error( $result ) ) {
					wc_add_notice( __( 'Your payment is being confirmed. Please do not pay again while we check it.',
						'icepay-for-woocommerce' ), 'notice' );
				}
			} catch ( \Throwable $exception ) {
				( new Log() )->error( 'Unable to confirm payment on return.',
					[ 'order' => $order->get_id(), 'message' => $exception->getMessage() ] );
				wc_add_notice( __( 'Your payment is being confirmed. Please do not pay again while we check it.',
					'icepay-for-woocommerce' ), 'notice' );
			}
		}
		$order = wc_get_order( $order->get_id() );
		$this->redirectPaymentReturn( $order );
	}

	protected function redirectPaymentReturn( \WC_Order $order ): void {
		// Applied to the redirect response, before attribution scripts can run.
		// Preserve existing attribution cookies; only suppress the payment referrer.
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(),
			$order ) );
		exit;
	}

	public function paymentReceivedText( string $text, $order ): string {
		if ( ! $order instanceof \WC_Order || ! Order::isIcepay( $order ) || $order->is_paid()
		     || $order->get_date_paid() || $order->has_status( 'refunded' ) ) {
			return $text;
		}
		if ( $order->get_meta( '_icepay_creation_uncertain' ) ) {
			return esc_html__( 'We could not confirm whether your payment was started. Please contact the store before paying again.',
				'icepay-for-woocommerce' );
		}
		if ( Order::activeKey( $order ) === '' ) {
			return $text;
		}
		$attempts = Order::getAttempts( $order );
		$status   = $attempts[ Order::activeKey( $order ) ]['status'] ?? 'unknown';
		if ( in_array( $status, [ 'unknown', 'pending', 'completed' ], true ) ) {
			return esc_html__( 'Your order has been received. Your payment is still being confirmed; please do not pay again. We will email you when it is confirmed.',
				'icepay-for-woocommerce' );
		}
		// Cancelled WooCommerce orders do not expose a payment form. Retry is handled
		// under the order lock and always verifies the previous attempt first.
		$url     = wp_nonce_url( add_query_arg( [
			'icepay-retry' => $order->get_id(),
			'key'          => $order->get_order_key()
		], wc_get_checkout_url() ), 'icepay-retry-' . $order->get_id() );
		$message = in_array( $status, [ 'cancelled', 'expired' ], true )
			? __( 'Your payment was cancelled or expired. You can try again or choose another payment method.',
				'icepay-for-woocommerce' )
			: __( 'Your payment has not been completed. You can return to checkout to continue.',
				'icepay-for-woocommerce' );

		return esc_html( $message ) . ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Try again',
				'icepay-for-woocommerce' ) . '</a>';
	}

	public function retryPayment(): void {
		if ( ! isset( $_GET['icepay-retry'] ) ) {
			return;
		}
		$id    = is_scalar( $_GET['icepay-retry'] ) ? absint( $_GET['icepay-retry'] ) : 0;
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$order = wc_get_order( $id );
		if ( ! $order || $key === '' || ! hash_equals( $order->get_order_key(), $key ) || ! wp_verify_nonce( $nonce,
				'icepay-retry-' . $id ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		$url = $order->get_checkout_order_received_url();
		if ( ! Order::isIcepay( $order ) ) {
			return;
		}
		if ( OrderLock::acquire( $id ) ) {
			try {
				$order = wc_get_order( $id );
				if ( ! Order::isIcepay( $order ) ) {
					return;
				}
				$paymentKey = Order::activeKey( $order );
				if ( $paymentKey !== '' && ! $order->is_paid() && ! $order->get_date_paid()
				     && ! $order->has_status( 'refunded' ) && ! $order->get_meta( '_icepay_creation_uncertain' ) ) {
					[ $success, $payment ] = ( new IcepayClient( Icepay::getMerchantId(),
						Icepay::getSecret() ) )->get( $paymentKey );
					if ( $success && ( $payment['key'] ?? '' ) === $paymentKey ) {
						$result = ( new PaymentStatus() )->applyLocked( $order, $payment );
						if ( ! is_wp_error( $result ) && in_array( $payment['status'],
								[ 'started', 'cancelled', 'expired' ], true )
						     && Order::isIcepay( $order ) ) {
							if ( $order->has_status( 'cancelled' ) ) {
								$order->update_status( 'pending',
									__( 'Customer requested another payment attempt.', 'icepay-for-woocommerce' ) );
							}
							if ( $order->needs_payment() ) {
								$url = $order->get_checkout_payment_url();
							}
						}
					}
				}
			} catch ( \Throwable $exception ) {
				( new Log() )->error( 'Unable to prepare payment retry.',
					[ 'order' => $id, 'message' => $exception->getMessage() ] );
			} finally {
				OrderLock::release( $id );
			}
		}
		if ( $url === $order->get_checkout_order_received_url() ) {
			wc_add_notice( __( 'Your payment must be confirmed before you can try again. Please wait or contact the store.',
				'icepay-for-woocommerce' ), 'notice' );
		}
		wp_safe_redirect( $url );
		exit;
	}

	protected function addSettings(): void {
		add_filter(
			'woocommerce_get_settings_pages',
			fn( array $data ): array => array_merge( $data, [ new Settings() ] )
		);
	}

	protected function addGateways(): void {
		add_filter( 'woocommerce_payment_gateways', fn( $gateways ) => array_merge( $gateways, $this->getGateways() ) );
	}

	protected function getGateways(): array {
		$gateways = [];

		foreach ( PaymentMethod::getAll() as $paymentMethod ) {
			$gateways[ $paymentMethod->getId() ] = new Gateway( $paymentMethod );
		}

		return $gateways;
	}

	protected function addBlocks(): void {
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ICEPAY_FILE, true );
			}
		} );

		add_action( 'woocommerce_blocks_loaded', fn() => Integration::addBlock() );
	}

	public static function addBlock(): void {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				fn( PaymentMethodRegistry $paymentMethodRegistry ) => $paymentMethodRegistry->register( new Block() )
			);
		}
	}

	protected function addWebhook(): void {
		add_action( 'woocommerce_api_' . 'icepay-webhook', fn() => ( new Webhook )->handle() );
	}

	protected function addGatewayFilters(): void {
		add_filter( 'woocommerce_available_payment_gateways', function ( array $gateways ): array {
			if ( $gateways && ! empty( WC()->cart ) ) {
				$totalCartAmount = (float) WC()->cart->get_total( '' );

				if ( $totalCartAmount === 0.0 ) {
					return $gateways;
				}

				foreach ( $gateways as $key => $gateway ) {
					if ( ! str_contains( $key, 'icepay_' ) ) {
						continue;
					}

					if ( ! empty( $gateway->min_quote_amount ) && $totalCartAmount < $gateway->min_quote_amount ) {
						unset( $gateways[ $key ] );
					}

					if ( ! empty( $gateway->max_quote_amount ) && $totalCartAmount > $gateway->max_quote_amount ) {
						unset( $gateways[ $key ] );
					}
				}
			}

			return $gateways;
		} );

		add_filter( 'woocommerce_available_payment_gateways', function ( array $gateways ): array {
			if (
				$gateways && ! empty( WC()->cart ) && ( $customerСountry = ( WC()->customer )
					? WC()->customer->get_billing_country() : false )
			) {
				foreach ( $gateways as $key => $gateway ) {
					if ( ! str_contains( $key, 'icepay_' ) ) {
						continue;
					}

					if (
						! empty( $gateway->countries )
						&& ! in_array( $customerСountry, $gateway->countries, true )
					) {
						unset( $gateways[ $key ] );
					}
				}
			}

			return $gateways;
		} );
	}

	public function addCustomLinks(): void {
		add_filter( 'plugin_action_links_' . ICEPAY_FILE, function ( array $data ): array {
			$action_links = [
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=icepay_settings' )
				. '">' . __( 'Settings', 'icepay-for-woocommerce' )
				. '</a>',
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' )
				. '">' . __( 'Payment Methods', 'icepay-for-woocommerce' ) . '</a>',
				'<a href="' . admin_url( 'admin.php?page=wc-status&tab=logs' )
				. '">' . __( 'Logs', 'icepay-for-woocommerce' ) . '</a>',

			];

			return array_merge( $action_links, $data );
		} );
	}

	public function addMultipleCardIcons(): void {
		$cardOptions = get_option( Integration::ID . '_icepay-card_settings', null ) ?? [];

		if ( empty( $cardOptions['separated'] ) || $cardOptions['separated'] === 'no' ) {
			return;
		}

		add_filter( 'woocommerce_gateway_icon', function ( $icon, $gateway_id ) {
			// Change 'cod' to your specific gateway ID
			if ( $gateway_id === 'icepay-card' ) {
				$icon = str_replace( 'card.svg', 'mastercard.svg', $icon ) .
				        str_replace( 'card.svg', 'visa.svg', $icon );
			}

			return $icon;
		}, 10, 2 );
	}
}
