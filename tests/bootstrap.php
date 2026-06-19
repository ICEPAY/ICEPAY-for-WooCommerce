<?php

declare( strict_types=1 );

/**
 * PHPUnit bootstrap for the Icepay\WooCommerce unit suite.
 *
 * Load order matters:
 *  1. Composer autoloader (src/ + WpStubs support class)
 *  2. Runtime class doubles (WP_Error, WC_Payment_Gateway, WC_Logger_Interface)
 *  3. Namespaced shadow functions (must be defined BEFORE any src/ class is loaded that
 *     calls the shadowed function, to avoid "cannot redeclare" errors in PHP < 8.3)
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Plugin constants normally defined by the bootstrap entry file.
if ( ! defined( 'ICEPAY_URL' ) ) {
	define( 'ICEPAY_URL', 'http://example.com/wp-content/plugins/icepay-for-woocommerce' );
}
if ( ! defined( 'ICEPAY_FILE' ) ) {
	define( 'ICEPAY_FILE', 'icepay-for-woocommerce/ICEPAY-for-WooCommerce.php' );
}

// Runtime class doubles — WP/WC classes needed by src/ classes at class-load time.
require_once __DIR__ . '/stubs.php';

// Namespaced shadow functions that intercept unqualified WP/WC function calls.
require_once __DIR__ . '/wp-function-shadows.php';
