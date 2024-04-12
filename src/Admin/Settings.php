<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin;

use Icepay\WooCommerce\Integration;
use WC_Admin_Settings;
use WC_Settings_Page;

class Settings extends WC_Settings_Page {
	public function __construct( protected GeneralTab $generalTab = new GeneralTab() ) {
		$this->id    = 'icepay_settings';
		$this->label = __( Integration::NAME, Integration::ID );

		add_action(
			'woocommerce_sections_' . $this->id,
			[ $this, 'output_sections' ]
		);

		parent::__construct();
	}

	public function output(): void {
		global $current_section;

		WC_Admin_Settings::output_fields( $this->get_settings( $current_section ) );
	}

	public function get_settings( $currentSection = '' ) {
		$settings = [];

		if ( ! $currentSection ) {
			$settings = $this->generalTab->getOutput();
		} else {
			$section  = ucfirst( strtolower( $currentSection ) );
			$settings = ( new ( '\Icepay\WooCommerce\Admin\\' . $section . 'Tab' )() )->getOutput();
		}

		return apply_filters(
			'woocommerce_get_settings_' . $this->id,
			$settings,
			$currentSection
		);
	}

	public function save(): void {
		global $current_section;

		$settings = $this->get_settings( $current_section );

		WC_Admin_Settings::save_fields( $settings );
	}

	public function get_sections(): array {
		return [
			''        => __( 'Settings', Integration::ID ),
			'support' => __( 'Support', Integration::ID )
		];
	}
}
