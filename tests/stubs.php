<?php

declare( strict_types=1 );

/**
 * Minimal runtime class doubles for WordPress / WooCommerce classes.
 *
 * These are needed because:
 *  - php-stubs/woocommerce-stubs ships PHPStan-only stubs (no runtime method bodies).
 *  - WordPress itself is not loaded in the unit-test suite.
 *
 * Keep each double as thin as possible — only the surface used by src/ classes.
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
		) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WC_Logger_Interface' ) ) {
	// WooCommerce defines this as an interface; we define a minimal concrete base so
	// Log can type-hint it and test doubles can extend it.
	interface WC_Logger_Interface {
		/** @param array<string, mixed> $context */
		public function log( string $level, string $message, array $context = [] ): void;
	}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	// Gateway extends this, so it must be loadable without WordPress.
	abstract class WC_Payment_Gateway {
		public string $id                 = '';
		public string $icon               = '';
		public string $method_title       = '';
		public string $method_description = '';
		public string $title              = '';
		public string $description        = '';
		public string $enabled            = 'yes';
		public string $plugin_id          = '';
		/** @var array<string, mixed> */
		public array $form_fields = [];
		/** @var string[] */
		public array $supports = [];

		public function process_admin_options(): void {
		}

		public function display_errors(): void {
		}

		public function get_return_url( mixed $order = null ): string {
			return '';
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	// Concrete base so the wc_get_order shadow's \WC_Order|bool return type resolves and
	// test order doubles can extend it. Holds the union of every method the doubles override.
	class WC_Order {
		public function get_id(): int {
			return 0;
		}

		public function get_status(): string {
			return '';
		}

		public function get_total( $context = 'view' ): float {
			return 0.00;
		}

		public function get_currency( $context = 'view' ): string {
			return 'EUR';
		}

		public function get_order_number(): string {
			return '0';
		}

		public function get_order_key( $context = 'view' ): string {
			return '';
		}

		public function get_payment_method( $context = 'view' ): string {
			return '';
		}

		public function get_billing_email( $context = 'view' ): string {
			return '';
		}

		public function get_billing_country( $context = 'view' ): string {
			return '';
		}

		public function get_billing_city( $context = 'view' ): string {
			return '';
		}

		public function get_billing_postcode( $context = 'view' ): string {
			return '';
		}

		public function get_billing_address_1( $context = 'view' ): string {
			return '';
		}

		public function get_billing_address_2( $context = 'view' ): string {
			return '';
		}

		public function get_meta( $key = '', $single = true, $context = 'view' ): mixed {
			return '';
		}

		public function update_meta_data( $key, $value, $meta_id = 0 ): void {
		}

		public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ): int {
			return 0;
		}

		public function update_status( $new_status = '', $note = '', $manual = false ): bool {
			return true;
		}

		public function set_transaction_id( $value = '' ): void {
		}

		public function save(): int {
			return 0;
		}

		public function get_checkout_payment_url(): string {
			return '';
		}

		public function get_checkout_order_received_url(): string {
			return '';
		}
	}
}

if ( ! class_exists( 'WC_Admin_Settings' ) ) {
	// SecretField renders through this helper; the WooCommerce stubs have no runtime body.
	class WC_Admin_Settings {
		/** @return array{description: string, tooltip_html: string} */
		public static function get_field_description( array $value ): array {
			return [
				'description'  => isset( $value['desc'] ) ? '<span class="description">' . $value['desc'] . '</span>' : '',
				'tooltip_html' => '',
			];
		}
	}
}
