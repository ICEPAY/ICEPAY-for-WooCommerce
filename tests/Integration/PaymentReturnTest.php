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
foreach ( [ 'completed', 'pending', 'cancelled' ] as $returnedStatus ) {
	$order = $makeOrder(); $oldToken = wp_generate_uuid4(); $newToken = wp_generate_uuid4();
	$old = $payment( $order, 'pi-return-old-' . $returnedStatus, $returnedStatus );
	Order::addAttempt( $order, $old, 'card', $oldToken ); $payments[ $old['key'] ] = $old;
	$new = $payment( $order, 'pi-return-new-' . $returnedStatus, 'started' );
	Order::addAttempt( $order, $new, 'card', $newToken );
	$handler->apply( $order, $new );
	// The newer API response intentionally differs: fetching it would complete the order.
	$payments[ $new['key'] ] = $new; $payments[ $new['key'] ]['status'] = 'completed';
	$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key(), 'icepay-attempt' => $oldToken ];
	$integration = new IcepayTestIntegration();
	try { $integration->redirect(); } catch ( IcepayTestReturnRedirect $exception ) {}
	$fresh = wc_get_order( $order->get_id() ); $attempts = Order::getAttempts( $fresh );
	$assert( $attempts[ $old['key'] ]['status'] === $returnedStatus && $attempts[ $new['key'] ]['status'] === 'started',
		'Return from older ' . $returnedStatus . ' attempt refreshes only the identified payment' );
	$assert( $returnedStatus === 'completed' ? $fresh->get_transaction_id() === $old['key'] && $fresh->is_paid() : $fresh->has_status( 'pending' ),
		'Older ' . $returnedStatus . ' return preserves newer order state unless payment succeeds' );
	if ( $returnedStatus === 'completed' ) {
		$before = $completed;
		try { $integration->redirect(); } catch ( IcepayTestReturnRedirect $exception ) {}
		$assert( $completed === $before, 'Repeated attempt-aware return does not repeat payment completion' );
	}
}
$otherOrder = $makeOrder(); $foreignToken = wp_generate_uuid4();
$foreignPayment = $payment( $otherOrder, 'pi-foreign' ); Order::addAttempt( $otherOrder, $foreignPayment, 'card', $foreignToken );
foreach ( [ $foreignToken, wp_generate_uuid4(), 'malformed', [ 'invalid' ], null ] as $invalidToken ) {
	$order = $makeOrder(); $token = wp_generate_uuid4();
	$p = $payment( $order, 'pi-reject-' . $order->get_id() ); Order::addAttempt( $order, $p, 'card', $token ); $payments[ $p['key'] ] = $p;
	$requests = 0;
	$counter = static function ( $pre ) use ( &$requests ) { ++$requests; return $pre; };
	add_filter( 'pre_http_request', $counter, 1 );
	$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key(), 'icepay-attempt' => $invalidToken ];
	try { ( new IcepayTestIntegration() )->redirect(); } catch ( IcepayTestReturnRedirect $exception ) {}
	remove_filter( 'pre_http_request', $counter, 1 );
	$assert( $requests === 0 && ! wc_get_order( $order->get_id() )->is_paid(), 'Invalid or cross-order attempt token never triggers payment lookup' );
}
$_GET = $originalQuery;
