<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Icepay\WooCommerce\Admin\Settings;
use Icepay\WooCommerce\Checkout\PaymentReceipt;
use Icepay\WooCommerce\Checkout\PaymentRetryController;
use Icepay\WooCommerce\Checkout\PaymentReturnController;

class Integration {
	public const NAME = 'ICEPAY for WooCommerce';
	public const ID = 'icepay-for-woocommerce';
	public const VERSION = '1.1.2';

	public function __invoke(): void {
		$this->addTranslations();
		$this->addSettings();
		$this->addGateways();
		$this->addBlocks();
		$this->addWebhook();
		$this->addrefunds();
		$this->addCustomLinks();
		$this->addMultipleCardIcons();
		add_action( 'template_redirect', [ new PaymentReturnController(), 'handle' ] );
		add_action( 'template_redirect', [ new PaymentRetryController(), 'handle' ] );
		add_filter( 'woocommerce_thankyou_order_received_text', [ new PaymentReceipt(), 'text' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueCheckoutRestore' ] );
	}


	public function addTranslations(): void {
		add_action( 'init', function () {
			load_plugin_textdomain( self::ID, false, dirname( ICEPAY_FILE ) . '/languages' );
		}, 0 );
	}

	public static function enqueueCheckoutRestore(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_register_script( 'icepay-checkout-restore', false, [], Integration::VERSION, true );
		wp_enqueue_script( 'icepay-checkout-restore' );
		wp_add_inline_script( 'icepay-checkout-restore',
			"window.addEventListener('pageshow',function(e){if(e.persisted){window.location.reload();}});" );
	}

	protected function addSettings(): void {
		add_filter( 'woocommerce_get_settings_pages',
			fn( array $data ): array => array_merge( $data, [ new Settings() ] ) );
	}

	protected function addGateways(): void {
		add_filter( 'woocommerce_payment_gateways', fn( $gateways ) => array_merge( $gateways, $this->getGateways() ) );
	}

	/** @return array<string, Gateway> */
	protected function getGateways(): array {
		$gateways = [];
		foreach ( PaymentMethod::getAll() as $paymentMethod ) {
			$gateways[ $paymentMethod->getId() ] = new Gateway( $paymentMethod );
		}

		return $gateways;
	}

	protected function addBlocks(): void {
		add_action( 'before_woocommerce_init', function (): void {
			if ( class_exists( FeaturesUtil::class ) ) {
				FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ICEPAY_FILE, true );
			}
		} );
		add_action( 'woocommerce_blocks_loaded', function (): void {
			self::addBlock();
		} );
	}

	public static function addBlock(): void {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action( 'woocommerce_blocks_payment_method_type_registration',
				function ( PaymentMethodRegistry $registry ): void {
					$registry->register( new Block() );
				} );
		}
	}

	protected function addWebhook(): void {
		add_action( 'woocommerce_api_icepay-webhook', [ new Webhook(), 'handle' ] );
	}

	protected function addrefunds(): void {
		add_action( 'woocommerce_create_refund', [ \Icepay\WooCommerce\Payment\RefundService::class, 'prepare' ], 10,
			2 );
		add_filter( 'woocommerce_pre_delete_order_refund',
			[ \Icepay\WooCommerce\Payment\RefundService::class, 'preventDelete' ], 10, 2 );
		add_action( 'woocommerce_admin_order_items_after_refunds',
			[ \Icepay\WooCommerce\Payment\RefundService::class, 'hideDeleteAction' ] );
		add_filter( 'woocommerce_order_actions', function ( array $actions, $order ): array {
			if ( $order instanceof \WC_Order && $order->get_meta( '_icepay_refunds' ) ) {
				$actions['icepay_reconcile_refunds'] = __( 'Reconcile ICEPAY refunds', 'icepay-for-woocommerce' );
			}

			return $actions;
		}, 10, 2 );
		add_action( 'woocommerce_order_action_icepay_reconcile_refunds', function ( \WC_Order $order ): void {
			try {
				$result = ( new \Icepay\WooCommerce\Payment\PaymentStatusHandler() )->refresh( $order,
					$order->get_transaction_id() );
				$order->add_order_note( is_wp_error( $result )
					? __( 'ICEPAY refund reconciliation could not retrieve a verified payment. Check the ICEPAY Portal.',
						'icepay-for-woocommerce' )
					: __( 'ICEPAY refund reconciliation checked the latest payment. Unmatched attempts remain blocked; review the ICEPAY Portal if necessary.',
						'icepay-for-woocommerce' ) );
			} catch ( \Throwable $exception ) {
				( new Log() )->error( 'Refund reconciliation failed.',
					[ 'order' => $order->get_id(), 'message' => $exception->getMessage() ] );
			}
		} );
	}

	public function addCustomLinks(): void {
		add_filter( 'plugin_action_links_' . ICEPAY_FILE, function ( array $data ): array {
			$links = [
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=icepay_settings' ) . '">' . __( 'Settings',
					'icepay-for-woocommerce' ) . '</a>',
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Payment Methods',
					'icepay-for-woocommerce' ) . '</a>',
				'<a href="' . admin_url( 'admin.php?page=wc-status&tab=logs' ) . '">' . __( 'Logs',
					'icepay-for-woocommerce' ) . '</a>',
			];

			return array_merge( $links, $data );
		} );
	}

	public function addMultipleCardIcons(): void {
		$cardOptions = get_option( Integration::ID . '_icepay-card_settings', null ) ?? [];
		if ( empty( $cardOptions['separated'] ) || $cardOptions['separated'] === 'no' ) {
			return;
		}
		add_filter( 'woocommerce_gateway_icon', function ( $icon, $gateway_id ) {
			if ( $gateway_id === 'icepay-card' ) {
				$icon = str_replace( 'card.svg', 'mastercard.svg', $icon ) . str_replace( 'card.svg', 'visa.svg',
						$icon );
			}

			return $icon;
		}, 10, 2 );
	}
}
