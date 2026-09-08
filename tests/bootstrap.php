<?php

/**
 * Minimal PHPUnit bootstrap for pure-logic unit tests.
 *
 * This intentionally does not boot WordPress/WooCommerce (no test suite for that exists yet).
 * It only stubs the handful of global functions needed by the classes under test so that
 * decision logic (PaymentMethod clearing-hold rules, Webhook::determineStatus()) can be unit tested
 * in isolation. Do not grow this file into a WP/WC emulation layer.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!defined('ICEPAY_URL')) {
    define('ICEPAY_URL', 'https://example.test/wp-content/plugins/icepay-for-woocommerce');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return $text;
    }
}

/**
 * Test-controllable stand-in for WordPress' get_option(), so PaymentMethod::getOptions() can read
 * back whatever a test has configured via icepay_test_set_option().
 *
 * @var array<string, mixed>
 */
$GLOBALS['icepay_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['icepay_test_options'][$key] ?? $default;
    }
}

function icepay_test_set_option(string $key, mixed $value): void
{
    $GLOBALS['icepay_test_options'][$key] = $value;
}

function icepay_test_reset_options(): void
{
    $GLOBALS['icepay_test_options'] = [];
}
