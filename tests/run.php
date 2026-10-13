<?php

/** Run through WP-CLI against a disposable development store: composer test. */

use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\Payment\PaymentStatusHandler;

$suiteNames = [ 'PaymentStatus', 'ProviderConflict', 'PaymentCreation', 'OrderLock', 'PaymentReturn', 'Refund', 'Restrictions' ];
$selected = $args[0] ?? 'all';
if ( $selected !== 'all' && ! in_array( $selected, $suiteNames, true ) ) {
	WP_CLI::error( 'Unknown suite. Choose: ' . implode( ', ', $suiteNames ) );
}
$total = 0;
foreach ( $suiteNames as $suite ) {
	if ( $selected !== 'all' && $selected !== $suite ) { continue; }
	// Fresh fixtures, counters, credentials and mocks for each independently runnable suite.
	$orders = []; $products = []; $checks = 0; $payments = []; $posts = 0;
	$cancellations = 0; $createMode = 'normal'; $completed = 0; $emails = 0;
	$refundPosts = 0; $refundMode = 'normal';
	$originalQuery = $_GET;
	$originalMerchant = get_option( Icepay::MERCHANT_ID, null );
	$originalSecret = get_option( Icepay::SECRET, null );
	$assert = static function ( $condition, string $message ) use ( &$checks ): void {
		if ( ! $condition ) { throw new RuntimeException( $message ); }
		++$checks; WP_CLI::log( 'PASS: ' . $message );
	};
	if ( ! class_exists( 'IcepayTestIntegration', false ) ) {
		require __DIR__ . '/bootstrap.php';
	} else {
		require __DIR__ . '/Support/MockIcepayResponses.php';
		require __DIR__ . '/Support/OrderFixtures.php';
	}
	add_filter( 'pre_http_request', $mock, 10, 3 );
	add_filter( 'pre_wp_mail', $onMail );
	add_action( 'woocommerce_payment_complete', $onComplete );
	update_option( Icepay::MERCHANT_ID, 'regression-merchant' );
	update_option( Icepay::SECRET, 'regression-secret' );
	$handler = new PaymentStatusHandler();
	try {
		WP_CLI::log( 'Suite: ' . $suite );
		require __DIR__ . '/Integration/' . $suite . 'Test.php';
		$total += $checks;
	} catch ( Throwable $exception ) {
		// Report after finally restores credentials and deletes fixtures.
		$failure = $exception;
	} finally {
		$_GET = $originalQuery;
		remove_filter( 'pre_http_request', $mock );
		remove_action( 'woocommerce_payment_complete', $onComplete );
		foreach ( $orders as $id ) {
			$order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); }
		}
		foreach ( $products as $id ) {
			$product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); }
		}
		foreach ( [ Icepay::MERCHANT_ID => $originalMerchant, Icepay::SECRET => $originalSecret ] as $name => $value ) {
			if ( $value === null ) { delete_option( $name ); } else { update_option( $name, $value ); }
		}
		remove_filter( 'pre_wp_mail', $onMail );
	}
	if ( isset( $failure ) ) { WP_CLI::error( $failure->getMessage() ); }
}
WP_CLI::success( $total . ' payment reliability checks passed.' );
