<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin\Tabs;

use Icepay\WooCommerce\Admin\SecretField;
use Icepay\WooCommerce\Admin\Settings;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Integration;
use WC_Admin_Settings;

class GeneralTab implements TabInterface {
	public const SECTION = '';

	public function render(): void {
		WC_Admin_Settings::output_fields( $this->getFilteredFields() );
	}

	public function save(): void {
		WC_Admin_Settings::save_fields( $this->getFilteredFields() );
	}

	private function getFilteredFields(): array {
		return apply_filters(
			'woocommerce_get_settings_' . Settings::ID,
			$this->getFields(),
			self::SECTION
		);
	}

	private function getFields(): array {
		return [
			[
				'title' => __( 'Settings', 'icepay-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Setting for your ICEPAY account.', 'icepay-for-woocommerce' ),
				'id'    => Integration::ID . '_general',
			],
			[
				'id'          => Icepay::MERCHANT_ID,
				'title'       => __( 'Merchant ID', 'icepay-for-woocommerce' ),
				'type'        => 'text',
				'desc'        => __( 'Get the Merchant ID from your ICEPAY account.', 'icepay-for-woocommerce' ),
				'css'         => 'width: 350px',
				'placeholder' => __( '12345', 'icepay-for-woocommerce' ),
			],
			[
				'id'           => Icepay::SECRET,
				'title'        => __( 'Secret', 'icepay-for-woocommerce' ),
				'type'         => SecretField::TYPE,
				'desc'         => __( 'Get the Secret from your ICEPAY account.', 'icepay-for-woocommerce' ),
				'css'          => 'width: 350px',
				'setting_type' => 'string',
			],
			[
				'id'           => Icepay::DESCRIPTION,
				'title'        => __( 'Reference', 'icepay-for-woocommerce' ),
				'type'         => 'text',
				'desc'         => __( 'Reference shown on payment. {ORDER_ID} will be replaced with the order id.',
					'icepay-for-woocommerce' ),
				'css'          => 'width: 350px',
				'placeholder'  => __( 'Order #{ORDER_ID}', 'icepay-for-woocommerce' ),
				'setting_type' => 'string',
			],
			[
				'id'                => Icepay::EXPIRE_AFTER,
				'title'             => __( 'Expire after', 'icepay-for-woocommerce' ),
				'type'              => 'number',
				'desc'              => __( 'In minutes. By default the payment expires after four hours (240 minutes).',
					'icepay-for-woocommerce' ),
				'css'               => 'width: 350px',
				'placeholder'       => __( '240', 'icepay-for-woocommerce' ),
				'custom_attributes' => [
					'step' => '10',
					'min'  => '30',
					'max'  => '1440'
				],
			],
			[
				'id'      => Icepay::SHOW_ICONS,
				'title'   => __( 'Show Icons', 'icepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'css'     => 'width: 350px',
				'default' => 'yes',
			],
			[
				'id'      => Icepay::ENABLE_LOGS,
				'title'   => __( 'Enable Logs', 'icepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'css'     => 'width: 350px',
				'default' => 'yes',
			],
			[
				'id'   => Integration::ID . '_general_sectionend',
				'type' => 'sectionend',
			],
		];
	}
}
