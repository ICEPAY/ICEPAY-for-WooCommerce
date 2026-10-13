<?php

use Icepay\WooCommerce\Gateway;
use Icepay\WooCommerce\PaymentMethod;
use Icepay\WooCommerce\Payment\PaymentAttempts;
use Icepay\WooCommerce\Payment\OrderLock;

$paidOrder = static function () use ( $makeOrder, $payment, $handler, &$payments ): WC_Order {
	$order = $makeOrder();
	$p = $payment( $order, 'pi-refund-' . $order->get_id() );
	PaymentAttempts::addAttempt( $order, $p, 'card' );
	$payments[ $p['key'] ] = $p;
	$handler->apply( $order, $p );
	return wc_get_order( $order->get_id() );
};
$refundOrder = static function ( $order, $amount, $reason = 'test' ) {
	$result = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => $amount, 'reason' => $reason, 'refund_payment' => true ] );
	// WooCommerce may retain totals cached during the gateway call after deleting a failed refund.
	WC_Cache_Helper::invalidate_cache_group( 'orders' );
	return $result;
};
$gateway = new Gateway( new PaymentMethod( 'card', 'Card', '', 'card.svg' ) );
$order = $paidOrder();
$first = $refundOrder( $order, 2 );
if ( is_wp_error( $first ) ) { throw new RuntimeException( $first->get_error_message() ); }
$first = wc_get_order( $first->get_id() );
$assert( $first instanceof WC_Order_Refund && $first->get_refunded_payment() && $first->get_meta( '_icepay_refund_key' ) === 'pr-regression-1', 'Accepted partial refund stores provider key on WooCommerce refund' );
$second = $refundOrder( $order, 2 );
if ( $second instanceof WC_Order_Refund ) { $second = wc_get_order( $second->get_id() ); }
$assert( $second instanceof WC_Order_Refund && $second->get_meta( '_icepay_refund_reference' ) !== $first->get_meta( '_icepay_refund_reference' ) && $refundPosts === 2, 'Equal amount and reason identify two legitimate partial refunds separately' );
$last = $refundOrder( $order, 6 );
$assert( $last instanceof WC_Order_Refund && wc_get_order( $order->get_id() )->has_status( 'refunded' ) && (float) wc_get_order( $order->get_id() )->get_total_refunded() === 10.0, 'Remaining refund completes WooCommerce accounting and order status' );
$count = $refundPosts;
$assert( is_wp_error( $refundOrder( $order, 1 ) ) && $refundPosts === $count, 'Over-refund cannot send an API request' );
$order = $paidOrder();
foreach ( [ null, 0, -1, 'no', INF, 0.001 ] as $amount ) {
	$assert( is_wp_error( $gateway->process_refund( $order->get_id(), $amount ) ) && $refundPosts === $count, 'Invalid refund amount rejected before API submission: ' . var_export( $amount, true ) );
}
$refundMode = 'reject';
$rejected = $refundOrder( $order, 2 );
$assert( is_wp_error( $rejected ), 'Definitive API rejection returns an error' );
$assert( (float) wc_get_order( $order->get_id() )->get_total_refunded() === 0.0, 'Definitive API rejection leaves no WooCommerce refund' );
$refundMode = 'normal';
$assert( $refundOrder( $order, 2 ) instanceof WC_Order_Refund, 'Definitive rejection permits a new refund attempt' );
foreach ( [ 'timeout', 'server', 'malformed' ] as $mode ) {
	$order = $paidOrder(); $refundMode = $mode;
	$result = $refundOrder( $order, 3 ); $count = $refundPosts;
	$assert( is_wp_error( $result ) && (float) wc_get_order( $order->get_id() )->get_total_refunded() === 0.0, $mode . ' preserves unknown outcome despite WooCommerce deleting refund' );
	$assert( is_wp_error( $refundOrder( $order, 3 ) ) && $refundPosts === $count, $mode . ' blocks duplicate API submission' );
	$key = $order->get_transaction_id();
	$payments[ $key ]['refunds'][0]['status'] = 'completed';
	$before = $emails;
	$handler->refresh( $order, $key );
	$refreshed = wc_get_order( $order->get_id() );
	$assert( (float) $refreshed->get_total_refunded() === 3.0 && count( $refreshed->get_refunds() ) === 1 && $refreshed->get_refunds()[0]->get_refunded_payment(), $mode . ' authenticated update recovers local accounting without another API submission' );
	$mailCount = $emails;
	$handler->refresh( $order, $key );
	$assert( $refundPosts === $count && $emails === $mailCount && count( wc_get_order( $order->get_id() )->get_refunds() ) === 1, $mode . ' replay does not duplicate refunds or emails' );
	$refundMode = 'normal';
	$assert( $refundOrder( $order, 3 ) instanceof WC_Order_Refund, $mode . ' reconciled outcome allows a subsequent legitimate partial refund' );
}
$order = $paidOrder(); $refundMode = 'timeout'; $key = $order->get_transaction_id();
$item = array_values( $order->get_items() )[0]; $productId = $item->get_product_id();
$result = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 10, 'reason' => 'return', 'refund_payment' => true,
	'restock_items' => true, 'line_items' => [ $item->get_id() => [ 'qty' => 1, 'refund_total' => 10, 'refund_tax' => [] ] ],
] );
WC_Cache_Helper::invalidate_cache_group( 'orders' );
$assert( is_wp_error( $result ) && (int) wc_get_product( $productId )->get_stock_quantity() === 9, 'Unknown refund does not restock before confirmation' );
$count = $refundPosts;
$handler->refresh( $order, $key ); $handler->refresh( $order, $key );
$recovered = wc_get_order( $order->get_id() );
$assert( $recovered->has_status( 'refunded' ) && (float) $recovered->get_total_refunded() === 10.0
	&& (int) wc_get_product( $productId )->get_stock_quantity() === 10 && $refundPosts === $count, 'Full-refund recovery restores line items and stock exactly once' );
$refundMode = 'normal';
$order = $paidOrder(); $key = $order->get_transaction_id();
$accepted = $refundOrder( $order, 2 ); $count = $refundPosts;
$payments[ $key ]['refunds'][0]['status'] = 'completed';
$handler->refresh( $order, $key );
$assert( wc_get_order( $accepted->get_id() )->get_meta( '_icepay_refund_status' ) === 'completed', 'Verified update advances stored refund status' );
$payments[ $key ]['refunds'][0]['status'] = 'pending'; $handler->refresh( $order, $key );
$assert( wc_get_order( $accepted->get_id() )->get_meta( '_icepay_refund_status' ) === 'completed', 'Completed refund status does not regress' );
$accepted->delete( true ); WC_Cache_Helper::invalidate_cache_group( 'orders' );
$assert( is_wp_error( $refundOrder( $order, 2 ) ) && $refundPosts === $count, 'Missing accepted accounting blocks another refund request' );
$handler->refresh( $order, $key );
$assert( (float) wc_get_order( $order->get_id() )->get_total_refunded() === 2.0 && $refundPosts === $count, 'Deleted accepted refund accounting is recovered without resubmission' );
$order = $paidOrder(); $key = $order->get_transaction_id(); $refundMode = 'timeout';
$refundOrder( $order, 2 ); $count = $refundPosts;
$payments[ $key ]['refunds'][0]['amount']['value']++;
$handler->refresh( $order, $key );
$assert( (float) wc_get_order( $order->get_id() )->get_total_refunded() === 0.0
	&& is_wp_error( $refundOrder( $order, 2 ) ) && $refundPosts === $count, 'Mismatched provider refund cannot resolve an uncertain attempt' );
$payments[ $key ]['refunds'][0]['amount']['value']--;
$payments[ $key ]['refunds'][] = array_merge( $payments[ $key ]['refunds'][0], [ 'key' => 'pr-ambiguous' ] );
$handler->refresh( $order, $key );
$assert( (float) wc_get_order( $order->get_id() )->get_total_refunded() === 0.0, 'Multiple provider refunds with the same reference cannot be guessed during recovery' );
$refundMode = 'normal';
$order = $paidOrder(); $key = $order->get_transaction_id();
$payments[ $key ]['amount']['currency'] = 'usd'; $count = $refundPosts;
$assert( is_wp_error( $refundOrder( $order, 2 ) ) && $refundPosts === $count, 'Mismatched original payment cannot be refunded' );
$order = $paidOrder(); $key = $order->get_transaction_id();
$payments[ $key ]['refunds'] = [ [ 'key' => 'pr-portal', 'status' => 'pending', 'amount' => [ 'value' => 900, 'currency' => 'eur' ] ] ];
$assert( is_wp_error( $refundOrder( $order, 2 ) ) && $refundPosts === $count, 'Portal refunds count toward remaining provider balance' );
$order = $paidOrder();
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lockName = 'icepay_' . sha1( $GLOBALS['wpdb']->prefix . ':' . $order->get_id() );
try {
	$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lockName ) );
	$assert( is_wp_error( $refundOrder( $order, 2 ) ) && $refundPosts === $count, 'Concurrent refund fails safely while independent connection holds order lock' );
} finally {
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
	$other->close();
}
$assert( OrderLock::acquire( $order->get_id() ), 'Refund error releases order lock' );
OrderLock::release( $order->get_id() );
