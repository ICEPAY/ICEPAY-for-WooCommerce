<?php

use Icepay\WooCommerce\Checkout\PaymentReceipt;
use Icepay\WooCommerce\Payment\PaymentAttempts as Order;

$order = $makeOrder();
$assert( ( new PaymentReceipt() )->text( '', $order ) === '', 'Unrelated order keeps standard received text' );
$p = $payment( $order, 'pi-receipt', 'pending' ); Order::addAttempt( $order, $p, 'card' );
$payments[ $p['key'] ] = $p; $handler->refresh( $order, $p['key'] );
$assert( str_contains( ( new PaymentReceipt() )->text( '', wc_get_order( $order->get_id() ) ), 'do not pay again' ), 'Pending receipt explains confirmation without encouraging retry' );
$payments[ $p['key'] ]['status'] = 'cancelled'; $handler->refresh( $order, $p['key'] );
$assert( str_contains( ( new PaymentReceipt() )->text( '', wc_get_order( $order->get_id() ) ), 'icepay-retry' ), 'Cancelled receipt exposes nonce-protected retry link' );
foreach ( [
	[ 'source_type' => 'typein', 'utm_source' => '(direct)' ],
	[ 'source_type' => 'utm', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer-sale' ],
	[ 'source_type' => 'referral', 'utm_source' => 'example.com', 'referrer' => 'https://example.com/' ],
] as $attribution ) {
	$order = $makeOrder();
	foreach ( $attribution as $name => $value ) { $order->update_meta_data( '_wc_order_attribution_' . $name, $value ); }
	$order->save(); $p = $payment( $order, 'pi-attribution-' . $order->get_id() );
	Order::addAttempt( $order, $p, 'card' ); $payments[ $p['key'] ] = $p;
	$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key() ];
	$integration = new IcepayTestIntegration();
	try { $integration->redirect(); } catch ( IcepayTestReturnRedirect $exception ) {}
	$fresh = wc_get_order( $order->get_id() );
	$assert( $fresh->is_paid() && $integration->redirectedOrder === $order->get_id(), 'Payment return confirms order before referrer-safe redirect for ' . $attribution['source_type'] );
	foreach ( $attribution as $name => $value ) {
		$assert( $fresh->get_meta( '_wc_order_attribution_' . $name ) === $value, 'Payment confirmation and return preserve attribution ' . $name . ' for ' . $attribution['source_type'] );
	}
}
$_GET = $originalQuery;
