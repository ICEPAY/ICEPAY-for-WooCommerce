<?php

// phpcs:disable -- This file uses multiple namespace blocks; declare(strict_types=1) is incompatible with that.

/**
 * Namespaced shadow functions for WordPress / WooCommerce globals.
 *
 * PHP resolves an unqualified function call to the current namespace first, then the global
 * namespace. By defining same-named functions in each src/ namespace here, calls from src/
 * classes automatically route through WpStubs without touching real WordPress.
 *
 * Add a new namespace block whenever a new src/ namespace is introduced.
 */

// ---------------------------------------------------------------------------
// Namespace: Icepay\WooCommerce  (src/Icepay.php, Gateway.php, Log.php, etc.)
// ---------------------------------------------------------------------------
namespace Icepay\WooCommerce {

	use Icepay\WooCommerce\Tests\Support\WpStubs;

	function get_option( string $option, mixed $default_value = false ): mixed {
		return WpStubs::call( 'get_option', func_get_args(), $default_value );
	}

	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return WpStubs::call( 'add_action', func_get_args(), true );
	}

	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return WpStubs::call( 'add_filter', func_get_args(), true );
	}

	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		return WpStubs::call( 'remove_filter', func_get_args(), true );
	}

	function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
		return WpStubs::call( 'update_option', func_get_args(), true );
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return WpStubs::call( 'wp_salt', func_get_args(), 'test-salt' );
	}

	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		return WpStubs::call( 'apply_filters', func_get_args(), $value );
	}

	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		return WpStubs::call( 'wp_safe_redirect', func_get_args(), true );
	}

	function wp_remote_request( string $url, array $args = [] ): array|\WP_Error {
		return WpStubs::call( 'wp_remote_request', func_get_args(), [] );
	}

	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int|string {
		return WpStubs::call( 'wp_remote_retrieve_response_code', func_get_args(), 200 );
	}

	function wp_remote_retrieve_response_message( array|\WP_Error $response ): string {
		return WpStubs::call( 'wp_remote_retrieve_response_message', func_get_args(), '' );
	}

	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		return WpStubs::call( 'wp_remote_retrieve_body', func_get_args(), '' );
	}

	function wp_remote_retrieve_headers( array|\WP_Error $response ): mixed {
		return WpStubs::call( 'wp_remote_retrieve_headers', func_get_args(), [] );
	}

	function is_wp_error( mixed $thing ): bool {
		return WpStubs::call( 'is_wp_error', func_get_args(), $thing instanceof \WP_Error );
	}

	function wc_get_order( int|bool $order_id = false ): \WC_Order|bool {
		return WpStubs::call( 'wc_get_order', func_get_args(), false );
	}

	function wc_get_order_id_by_order_key( string $order_key ): int {
		return WpStubs::call( 'wc_get_order_id_by_order_key', func_get_args(), 0 );
	}

	function wc_get_orders( array $args ): array {
		return WpStubs::call( 'wc_get_orders', func_get_args(), [] );
	}

	function wc_get_logger(): \WC_Logger_Interface {
		return WpStubs::call( 'wc_get_logger', func_get_args() );
	}

	function add_query_arg( mixed ...$args ): string {
		return WpStubs::call( 'add_query_arg', func_get_args(), '' );
	}

	function home_url( string $path = '', ?string $scheme = null ): string {
		return WpStubs::call( 'home_url', func_get_args(), 'http://example.com' . $path );
	}

	function esc_url_raw( string $url, array $protocols = [] ): string {
		return WpStubs::call( 'esc_url_raw', func_get_args(), $url );
	}

	function esc_url( string $url, ?array $protocols = null, string $_context = 'display' ): string {
		return WpStubs::call( 'esc_url', func_get_args(), $url );
	}

	function filter_input( int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0 ): mixed {
		return WpStubs::call( 'filter_input', func_get_args(), null );
	}

	function status_header( int $code, string $description = '' ): void {
		WpStubs::call( 'status_header', func_get_args() );
	}

	function sanitize_text_field( string $str ): string {
		return WpStubs::call( 'sanitize_text_field', func_get_args(), $str );
	}

	function wp_unslash( mixed $value ): mixed {
		return WpStubs::call( 'wp_unslash', func_get_args(), $value );
	}

	function map_deep( mixed $value, callable $callback ): mixed {
		return WpStubs::call( 'map_deep', func_get_args(), $value );
	}

	function __( string $text, string $domain = 'default' ): string {
		return WpStubs::call( '__', func_get_args(), $text );
	}

	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return WpStubs::call( 'admin_url', func_get_args(), 'http://example.com/wp-admin/' . $path );
	}

	function untrailingslashit( string $string ): string {
		return WpStubs::call( 'untrailingslashit', func_get_args(), rtrim( $string, '/\\' ) );
	}
}

// ---------------------------------------------------------------------------
// Namespace: Icepay\WooCommerce\Http  (PSR-18 adapter — task 002)
// ---------------------------------------------------------------------------
namespace Icepay\WooCommerce\Http {

	use Icepay\WooCommerce\Tests\Support\WpStubs;

	function wp_remote_request( string $url, array $args = [] ): array|\WP_Error {
		return WpStubs::call( 'wp_remote_request', func_get_args(), [] );
	}

	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int|string {
		return WpStubs::call( 'wp_remote_retrieve_response_code', func_get_args(), 200 );
	}

	function wp_remote_retrieve_response_message( array|\WP_Error $response ): string {
		return WpStubs::call( 'wp_remote_retrieve_response_message', func_get_args(), '' );
	}

	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		return WpStubs::call( 'wp_remote_retrieve_body', func_get_args(), '' );
	}

	function wp_remote_retrieve_headers( array|\WP_Error $response ): mixed {
		return WpStubs::call( 'wp_remote_retrieve_headers', func_get_args(), [] );
	}

	function is_wp_error( mixed $thing ): bool {
		return WpStubs::call( 'is_wp_error', func_get_args(), $thing instanceof \WP_Error );
	}

	function get_option( string $option, mixed $default_value = false ): mixed {
		return WpStubs::call( 'get_option', func_get_args(), $default_value );
	}
}

// ---------------------------------------------------------------------------
// Namespace: Icepay\WooCommerce\Admin  (src/Admin/SecretField.php)
// ---------------------------------------------------------------------------
namespace Icepay\WooCommerce\Admin {

	use Icepay\WooCommerce\Tests\Support\WpStubs;

	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return WpStubs::call( 'add_action', func_get_args(), true );
	}

	function esc_attr( string $text ): string {
		return WpStubs::call( 'esc_attr', func_get_args(), htmlspecialchars( $text, ENT_QUOTES ) );
	}

	function esc_html( string $text ): string {
		return WpStubs::call( 'esc_html', func_get_args(), htmlspecialchars( $text, ENT_QUOTES ) );
	}

	function __( string $text, string $domain = 'default' ): string {
		return WpStubs::call( '__', func_get_args(), $text );
	}
}
