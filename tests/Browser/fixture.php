<?php

// Disposable paid order used to inspect the real HTTP return redirect.
add_filter( 'pre_wp_mail', static fn() => true );
if ( ( $args[0] ?? '' ) === 'delete' ) {
	$order = wc_get_order( (int) ( $args[1] ?? 0 ) );
	if ( $order && $order->get_meta( '_icepay_browser_fixture' ) === 'yes' ) { $order->delete( true ); }
	return;
}
$order = wc_create_order();
$order->set_payment_method( 'icepay-card' );
$order->set_status( 'processing' );
$order->update_meta_data( '_icepay_browser_fixture', 'yes' );
$order->save();
WP_CLI::log( wp_json_encode( [ 'id' => $order->get_id(), 'returnPath' => '/?via-icepay=1&key=' . $order->get_order_key() ] ) );
