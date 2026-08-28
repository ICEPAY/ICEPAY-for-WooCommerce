<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use ICEPAY\Checkout\Exceptions\ApiException;
use ICEPAY\Checkout\Models\Status;
use Icepay\WooCommerce\Admin\Settings;

class Integration {
	public const NAME = 'ICEPAY for WooCommerce';
	public const ID = 'icepay-for-woocommerce';
	public const VERSION = '1.2.0';

	public function __construct(
		protected CheckoutClientFactory $clientFactory = new CheckoutClientFactory(),
		protected Log $log = new Log()
	) {
	}

	public function __invoke(): void {
		$this->addSettings();
		$this->addGateways();

		$this->addGatewayFilters();

		$this->addBlocks();
		$this->addWebhook();

		$this->addCustomLinks();

		$this->addMultipleCardIcons();

		add_action( 'template_redirect', [ $this, 'redirect' ] );
	}

	public function redirect(): void {
		if ( ! isset( $_GET['via-icepay'] ) ) {
			return;
		}

		$orderKey = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_SPECIAL_CHARS ) ?? null;
		$order    = wc_get_order( wc_get_order_id_by_order_key( $orderKey ) );

		if ( ! $order ) {
			$this->log->warning( 'Could not resolve order for key: ' . $orderKey );
			wp_safe_redirect( home_url( '/' ) );
			$this->terminate();
			return;
		}

		$paymentKey = $order->get_meta( 'icepay-payment-key' );

		try {
			$payment     = $this->clientFactory->create()->getCheckout( $paymentKey );
			$redirectUrl = $payment->status === Status::started
				? $order->get_checkout_payment_url()
				: apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
		} catch ( ApiException $e ) {
			$this->log->error( $e->getMessage(), [ 'type' => $e->type, 'code' => $e->getCode() ] );
			$redirectUrl = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
		}

		wp_safe_redirect( $redirectUrl );
		$this->terminate();
	}

	protected function terminate(): void {
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
