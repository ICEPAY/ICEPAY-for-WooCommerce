<?php

/*
 * Plugin Name: ICEPAY for WooCommerce
 * Plugin URI: https://github.com/ICEPAY/ICEPAY-for-WooCommerce
 * Description: Accept payments on your WooCommerce store via ICEPAY.
 * Author: ICEPAY
 * Author URI: http://www.icepay.com
 * Version: 1.0.1
 * Copyright: Copyright © 2024 ICEPAY B.V. (https://icepay.com/)
 * Text Domain: icepay-for-woocommerce
 * Domain Path: /languages
 * Requires PHP: 8.1
 */

namespace Icepay\WooCommerce;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

define( 'ICEPAY_FILE', plugin_basename( Integration::ID . '/IcepayForWoocommerce.php' ) );
define( 'ICEPAY_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

function onError( Throwable $throwable ): void {
	$error = sprintf( '<strong>Error:</strong> %s <br><pre>%s</pre>', $throwable->getMessage(), $throwable->getTraceAsString() );
	add_action( 'all_admin_notices', fn() => printf( '<div class="notice notice-error"><p>%1$s</p></div>', wp_kses_post( $error ) ) );
}

function addIntegration(): void {
	try {
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return;
		}

		( new Integration )();
	} catch ( Throwable $throwable ) {
		onError( $throwable );
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\addIntegration' );
