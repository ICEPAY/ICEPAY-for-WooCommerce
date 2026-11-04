<?php

use Icepay\WooCommerce\Gateway;
use Icepay\WooCommerce\PaymentMethod;
use Icepay\WooCommerce\Payment\OrderLock;

$order = $makeOrder(); $id = $order->get_id();
$gateway = new Gateway( new PaymentMethod( 'card', 'Card', '', 'card.svg' ) );
$assert( OrderLock::acquire( $id ), 'Order lock acquired' );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lockName = 'icepay_' . sha1( $GLOBALS['wpdb']->prefix . ':' . $id );
try {
	$assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lockName ) ) === '0', 'Independent database connection cannot acquire concurrent order lock' );
	OrderLock::release( $id );
	$assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lockName ) ) === '1', 'Lock available after release' );
	$result = $gateway->process_payment( $id );
	$assert( $result['result'] === 'failure', 'Concurrent submission fails safely while another connection holds lock' );
} finally {
	OrderLock::release( $id );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
	$other->close();
}
