<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin;

use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Integration;

class GeneralTab {
	public function getOutput(): array {
		return [
			[
				'title' => __( 'Settings', Integration::ID ),
				'type'  => 'title',
				'desc'  => __( 'Setting for your ICEPAY account.' ),
				'id'    => Integration::ID . '_general',
			],
			[
				'id'          => Icepay::MERCHANT_ID,
				'title'       => __( 'Merchant ID', Integration::ID ),
				'type'        => 'text',
				'desc'        => __( 'Get the Merchant ID from your ICEPAY account.', Integration::ID ),
				'css'         => 'width: 350px',
				'placeholder' => __( '12345', Integration::ID ),
			],
			[
				'id'           => Icepay::SECRET,
				'title'        => __( 'Secret', Integration::ID ),
				'type'         => 'password',
				'desc'         => __( 'Get the Secret from your ICEPAY account.', Integration::ID ),
				'css'          => 'width: 350px',
				'placeholder'  => __( '', Integration::ID ),
				'setting_type' => 'string',
			],
			[
				'id'           => Icepay::DESCRIPTION,
				'title'        => __( 'Reference', Integration::ID ),
				'type'         => 'text',
				'desc'         => __( 'Reference shown on payment. {ORDER_ID} will be replaced with the order id.', Integration::ID ),
				'css'          => 'width: 350px',
				'placeholder'  => __( 'Order #{ORDER_ID}', Integration::ID ),
				'setting_type' => 'string',
			],
			[
				'id'      => Icepay::SHOW_ICONS,
				'title'   => __( 'Show Icons', Integration::ID ),
				'type'    => 'checkbox',
				'css'     => 'width: 350px',
				'default' => 'yes',
			],
			[
				'id'      => Icepay::ENABLE_LOGS,
				'title'   => __( 'Enable Logs', Integration::ID ),
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
