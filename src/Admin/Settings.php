<?php

declare( strict_types=1 );

namespace Icepay\WooCommerce\Admin;

use Icepay\WooCommerce\Admin\Tabs\GeneralTab;
use Icepay\WooCommerce\Admin\Tabs\PaymentMethodsTab;
use Icepay\WooCommerce\Admin\Tabs\SupportTab;
use Icepay\WooCommerce\Admin\Tabs\TabInterface;
use Icepay\WooCommerce\Integration;
use WC_Settings_Page;

class Settings extends WC_Settings_Page {
	public const ID = 'icepay_settings';

	/** @var array<string, TabInterface> */
	private array $tabs;

	public function __construct() {
		$this->id    = self::ID;
		$this->label = __( Integration::NAME, Integration::ID );

		$this->tabs = [
			GeneralTab::SECTION        => new GeneralTab(),
			PaymentMethodsTab::SECTION => new PaymentMethodsTab(),
			SupportTab::SECTION        => new SupportTab(),
		];

		add_action(
			'woocommerce_sections_' . $this->id,
			[ $this, 'output_sections' ]
		);

		parent::__construct();
	}

	public function output(): void {
		global $current_section;

		$this->tabFor( (string) $current_section )->render();
	}

	public function save(): void {
		global $current_section;

		$this->tabFor( (string) $current_section )->save();
	}

	public function get_sections(): array {
		return [
			GeneralTab::SECTION        => __( 'Settings', Integration::ID ),
			PaymentMethodsTab::SECTION => __( 'Payment methods', Integration::ID ),
			SupportTab::SECTION        => __( 'Support', Integration::ID ),
		];
	}

	private function tabFor( string $section ): TabInterface {
		return $this->tabs[ $section ] ?? $this->tabs[ GeneralTab::SECTION ];
	}
}
