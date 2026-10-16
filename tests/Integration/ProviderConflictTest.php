<?php

use Icepay\WooCommerce\Payment\PaymentAttempts as Order;

// These tests simulate external completion through WooCommerce, not PSP callbacks.
foreach ( [ 'external_gateway_a', 'external_gateway_b', 'external_gateway_c' ] as $provider ) {
	$order = $makeOrder(); $p = $payment( $order, 'pi-switch-' . $provider, 'started' );
	Order::addAttempt( $order, $p, 'card' );
	$order->set_payment_method( $provider );
	$order->set_payment_method_title( 'Other provider iDEAL' );
	$order->set_transaction_id( 'external-' . $provider );
	$order->save();
	$integration = new IcepayTestIntegration();
	foreach ( [ 'pending', 'cancelled', 'expired', 'started' ] as $status ) {
		$p['status'] = $status; $handler->apply( $order, $p );
		$fresh = wc_get_order( $order->get_id() );
		$assert( $fresh->has_status( 'pending' ) && $fresh->get_payment_method() === $provider && $fresh->get_transaction_id() === 'external-' . $provider, $provider . ': ICEPAY ' . $status . ' preserves other provider order state' );
		$assert( $integration->paymentReceivedText( 'Other provider instructions', $fresh ) === 'Other provider instructions', $provider . ': historical ' . $status . ' attempt leaves receipt text unchanged' );
	}
	$order = wc_get_order( $order->get_id() );
	$order->update_meta_data( '_icepay_creation_uncertain', time() ); $order->save();
	$assert( $integration->paymentReceivedText( 'Other provider instructions', $order ) === 'Other provider instructions', $provider . ': unknown ICEPAY creation does not override receipt' );
	$order->delete_meta_data( '_icepay_creation_uncertain' ); $order->save();
	$payments[ $p['key'] ] = $p;
	$payments[ $p['key'] ]['status'] = 'completed';
	$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key() ];
	try { $integration->redirect(); } catch ( IcepayTestReturnRedirect $exception ) {}
	$assert( $integration->redirectedOrder === $order->get_id(), $provider . ': historical ICEPAY return uses referrer-safe redirect' );
	$_GET = [ 'icepay-retry' => $order->get_id(), 'key' => $order->get_order_key(), '_wpnonce' => wp_create_nonce( 'icepay-retry-' . $order->get_id() ) ];
	$integration->retryPayment();
	$assert( ! wc_get_order( $order->get_id() )->is_paid() && wc_get_order( $order->get_id() )->get_payment_method() === $provider, $provider . ': old return and retry links cannot act on switched order' );
	$_GET = $originalQuery;
	$before = $completed;
	$p['status'] = 'completed'; $handler->apply( $order, $p );
	$fresh = wc_get_order( $order->get_id() );
	$conflict = Order::getAttempts( $fresh )[ $p['key'] ]['provider_conflict'] ?? [];
	$assert( $fresh->is_paid() && $fresh->get_payment_method() === 'icepay-card' && $fresh->get_transaction_id() === $p['key'], $provider . ': late ICEPAY success completes and switches gateway' );
	$assert( ( $conflict['gateway'] ?? '' ) === $provider && ( $conflict['transaction'] ?? '' ) === 'external-' . $provider && ( $conflict['title'] ?? '' ) === 'Other provider iDEAL', $provider . ': previous provider details preserved for review' );
	$productId = array_values( $fresh->get_items() )[0]->get_product_id(); $mailCount = $emails;
	$handler->apply( $order, $p );
	$p['status'] = 'cancelled'; $handler->apply( $order, $p );
	$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
	$conflictNotes = array_filter( $notes, static fn( $note ) => str_contains( $note->content, 'completed after the order switched' ) );
	$assert( count( $conflictNotes ) === 1 && $completed === $before + 1 && $emails === $mailCount && (int) wc_get_product( $productId )->get_stock_quantity() === 9, $provider . ': repeated conflict notifications preserve one note, completion, and stock reduction' );
	$fresh = wc_get_order( $order->get_id() ); $fresh->payment_complete( 'external-' . $provider );
	$assert( wc_get_order( $order->get_id() )->get_transaction_id() === $p['key'] && $completed === $before + 1, $provider . ': subsequent external payment_complete preserves winning transaction' );
	$order = $makeOrder(); $p = $payment( $order, 'pi-paid-elsewhere-' . $provider );
	Order::addAttempt( $order, $p, 'card' );
	$order->set_payment_method( $provider ); $order->save();
	$staleOrder = wc_get_order( $order->get_id() );
	$order->payment_complete( 'external-paid-' . $provider );
	$before = $completed; $mailCount = $emails;
	$payments[ $p['key'] ] = $p;
	$handler->refresh( $order, $p['key'] );
	$handler->apply( $staleOrder, $p );
	$fresh = wc_get_order( $order->get_id() );
	$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
	$duplicateNotes = array_filter( $notes, static fn( $note ) => str_contains( $note->content, 'possible duplicate charge' ) );
	$assert( $fresh->is_paid() && $fresh->get_payment_method() === $provider && $fresh->get_transaction_id() === 'external-paid-' . $provider && $completed === $before && $emails === $mailCount, $provider . ': external payment first is preserved even when ICEPAY caller has stale order' );
	$record = Order::getAttempts( $fresh )[ $p['key'] ];
	$assert( count( $duplicateNotes ) === 1 && ( $record['duplicate_gateway'] ?? '' ) === $provider && ( $record['duplicate_transaction'] ?? '' ) === 'external-paid-' . $provider, $provider . ': additional ICEPAY success records existing transaction and flags duplicate once' );
}
