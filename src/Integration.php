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
	}

	public function redirect(): void {
		if ( ! isset( $_GET['via-icepay'] ) ) {
			return;
		}

		$orderKey = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_SPECIAL_CHARS ) ?: null;
		$order    = wc_get_order( wc_get_order_id_by_order_key( $orderKey ) );
		$log      = new Log();

		// todo: check if order is already paid
		if ( ! $order->needs_payment() ) {
			$log->debug( 'Order does not require payment' );
			wp_safe_redirect( apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(),
				$order ) );
		}

		$client = new IcepayClient(
			Icepay::getMerchantId(),
			Icepay::getSecret(),
		);

		$paymentKeys = $order->get_meta( 'icepay-payment-key' );
		$keys        = str_contains( $paymentKeys, ',' ) ? explode( ',', $paymentKeys ) : [ $paymentKeys ];

		$log->debug( 'Payment keys are: ' . implode( ', ', $keys ), [
			'orderKey' => $orderKey,
			'order'    => $order,
		] );

		foreach ( $keys as $key ) {
			[ $isSuccessful, $payment ] = $client->get( $key );

			if ( ! $isSuccessful ) {
				continue;
			}

			if ( $order->get_id() !== ( $payment['meta']['woocommerce']['order_id'] ?? '' ) ) {
				continue;
			}

			if ( $payment['status'] === 'completed' || $payment['status'] === 'pending' ) {
				wp_safe_redirect( apply_filters( 'woocommerce_get_return_url',
					$order->get_checkout_order_received_url(), $order ) );
				die;
			}
		}

		wp_safe_redirect( $order->get_checkout_payment_url() );
		die;
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
