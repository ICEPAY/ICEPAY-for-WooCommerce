<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin\Tabs;

use Icepay\WooCommerce\Admin\Settings;
use Icepay\WooCommerce\Integration;
use WC_Admin_Settings;

class SupportTab implements TabInterface {
	public const SECTION = 'support';

	public function render(): void {
		WC_Admin_Settings::hide_save_button();
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
				'title' => __( 'Support', Integration::ID ),
				'type'  => 'title',
				'desc'  => __( 'For support please send an email to info@icepay.com', Integration::ID ),
				'id'    => Integration::ID . '_support',
			],
		];
	}
}
