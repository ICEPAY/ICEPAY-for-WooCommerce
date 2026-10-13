<?php

use Icepay\WooCommerce\Gateway;
use Icepay\WooCommerce\PaymentMethod;

$optionKey = ( new PaymentMethod( 'card', 'Card', '', 'card.svg' ) )->getOptionKey();
$original = get_option( $optionKey, null );
try {
	update_option( $optionKey, [ 'enabled' => 'yes', 'countries' => [ 'NL', 'BE' ], 'minimum' => '10', 'maximum' => '20' ] );
	$method = new PaymentMethod( 'card', 'Card', '', 'card.svg' );
	$assert( $method->isEligible( 'NL', 10 ) && $method->isEligible( 'BE', 20 ), 'Country and inclusive amount boundaries allow payment' );
	$assert( ! $method->isEligible( 'DE', 15 ) && ! $method->isEligible( '', 15 ) && ! $method->isEligible( 'NL', 9.99 ) && ! $method->isEligible( 'NL', 20.01 ), 'Country and amount restrictions reject ineligible checkout' );
	$gateway = new Gateway( $method );
	$originalCart = WC()->cart; $originalCustomer = WC()->customer;
	$originalQuery = $GLOBALS['wp']->query_vars; $originalPayQuery = get_query_var( 'order-pay', '' );
	try {
		WC()->cart = new WC_Cart(); WC()->customer = new WC_Customer();
		WC()->cart->set_total( 15 ); WC()->customer->set_billing_country( 'NL' );
		$assert( $gateway->is_available(), 'Classic checkout exposes eligible gateway' );
		WC()->customer->set_billing_country( 'DE' );
		$assert( ! $gateway->is_available(), 'Classic checkout hides restricted gateway' );
		$payOrder = $makeOrder(); $payOrder->set_billing_country( 'BE' ); $payOrder->save();
		$GLOBALS['wp']->query_vars['order-pay'] = $payOrder->get_id();
		set_query_var( 'order-pay', $payOrder->get_id() );
		WC()->cart->set_total( 100 );
		$assert( $gateway->is_available(), 'Order-pay uses order country and total instead of unrelated cart' );
		$payOrder->set_billing_country( 'DE' ); $payOrder->save();
		$assert( ! $gateway->is_available(), 'Order-pay hides gateway for restricted order' );
	} finally {
		WC()->cart = $originalCart; WC()->customer = $originalCustomer;
		$GLOBALS['wp']->query_vars = $originalQuery; set_query_var( 'order-pay', $originalPayQuery );
	}
	$order = $makeOrder(); $order->set_billing_country( 'DE' ); $order->save(); $count = $posts;
	$assert( $gateway->process_payment( $order->get_id() )['result'] === 'failure' && $posts === $count, 'Direct payment submission cannot bypass country restrictions' );
	$order->set_billing_country( 'NL' ); $order->set_total( '21' ); $order->save();
	$assert( $gateway->process_payment( $order->get_id() )['result'] === 'failure' && $posts === $count, 'Direct payment submission cannot bypass amount restrictions' );
	$order->set_total( '10' ); $order->save();
	$assert( $gateway->process_payment( $order->get_id() )['result'] === 'success' && $posts === $count + 1, 'Eligible order creates payment' );
	$assert( $gateway->validate_countries_field( 'countries', [ 'NL', 'NL' ] ) === [ 'NL' ], 'Country settings deduplicate valid entries' );
	foreach ( [ [ 'countries', [ 'XX' ] ], [ 'minimum', '-1' ], [ 'maximum', 'invalid' ] ] as [ $field, $value ] ) {
		$rejected = false;
		try { $gateway->{ 'validate_' . $field . '_field' }( $field, $value ); } catch ( Exception $exception ) { $rejected = true; }
		$assert( $rejected, 'Invalid ' . $field . ' setting is rejected' );
	}
	$originalPost = $_POST;
	try {
		$_POST[ $gateway->get_field_key( 'minimum' ) ] = '20';
		$_POST[ $gateway->get_field_key( 'maximum' ) ] = '10';
		foreach ( [ 'minimum' => '20', 'maximum' => '10' ] as $field => $value ) {
			$rejected = false;
			try { $gateway->{ 'validate_' . $field . '_field' }( $field, $value ); } catch ( Exception $exception ) { $rejected = true; }
			$assert( $rejected, 'Inverted range preserves previous ' . $field . ' setting' );
		}
	} finally { $_POST = $originalPost; }
	update_option( $optionKey, [] );
	$unrestricted = new PaymentMethod( 'card', 'Card', '', 'card.svg' );
	$assert( $unrestricted->isEligible( '', 0 ) && $unrestricted->isEligible( 'US', 999999 ), 'Existing settings default to unrestricted country and amount' );
} finally {
	if ( $original === null ) { delete_option( $optionKey ); } else { update_option( $optionKey, $original ); }
}
