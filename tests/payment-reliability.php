<?php

/**
 * Integration regression checks against a disposable WooCommerce development store.
 * Run: docker compose exec -T app wp eval-file wp-content/plugins/icepay-for-woocommerce/tests/payment-reliability.php
 * All ICEPAY HTTP requests and outgoing email are intercepted. Fixtures are removed.
 */

use Icepay\WooCommerce\Gateway;
use Icepay\WooCommerce\Icepay;
use Icepay\WooCommerce\IcepayClient;
use Icepay\WooCommerce\Integration;
use Icepay\WooCommerce\Order;
use Icepay\WooCommerce\OrderLock;
use Icepay\WooCommerce\PaymentMethod;
use Icepay\WooCommerce\PaymentStatus;
use Icepay\WooCommerce\Webhook;

class IcepayTestReturnRedirect extends RuntimeException {}

class IcepayTestIntegration extends Integration {
	public ?int $redirectedOrder = null;

	protected function redirectPaymentReturn( WC_Order $order ): void {
		$this->redirectedOrder = $order->get_id();
		throw new IcepayTestReturnRedirect();
	}
}

$orders = [];
$products = [];
$checks = 0;
$payments = [];
$posts = 0;
$cancellations = 0;
$createMode = 'normal';
$completed = 0;
$emails = 0;
$originalQuery = $_GET;
$originalMerchant = get_option( Icepay::MERCHANT_ID, null );
$originalSecret = get_option( Icepay::SECRET, null );
$cleanupOption = Integration::ID . '_reconciliation_removed';
$originalCleanup = get_option( $cleanupOption, null );
$cleanupArgs = [ 0, 'pi-cleanup-' . wp_generate_uuid4(), 0 ];
$assert = static function ( $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
	WP_CLI::log( 'PASS: ' . $message );
};
$onComplete = static function () use ( &$completed ): void { ++$completed; };
$onMail = static function () use ( &$emails ): bool { ++$emails; return true; };
$mock = static function ( $pre, $args, $url ) use ( &$payments, &$posts, &$cancellations, &$createMode ) {
	if ( ! str_starts_with( $url, 'https://checkout.icepay.com/api/' ) ) {
		return $pre;
	}
	$path = substr( $url, strlen( 'https://checkout.icepay.com/api/' ) );
	if ( $path === 'payments' && $args['method'] === 'POST' ) {
		++$posts;
		$data = json_decode( $args['body'], true );
		$data['key'] = 'pi-regression-' . $posts;
		$data['status'] = 'started';
		$data['isTest'] = true;
		$data['links'] = [ 'checkout' => 'https://checkout.icepay.com/checkout/' . $data['key'] ];
		$payments[ $data['key'] ] = $data;
		if ( $createMode === 'timeout' ) {
			return new WP_Error( 'http_request_failed', 'Simulated timeout after creation' );
		}
		if ( $createMode === 'reject' ) {
			return [ 'response' => [ 'code' => 400, 'message' => 'Bad Request' ], 'body' => 'not-json', 'headers' => [] ];
		}
		if ( $createMode === 'malformed' ) {
			return [ 'response' => [ 'code' => 201, 'message' => 'Created' ], 'body' => '<html>proxy</html>', 'headers' => [] ];
		}
	} else {
		$key = explode( '/', $path )[1] ?? '';
		if ( ! isset( $payments[ $key ] ) ) {
			return new WP_Error( 'missing_mock', 'Unknown mocked payment' );
		}
		if ( str_ends_with( $path, '/cancel' ) ) {
			++$cancellations;
			$payments[ $key ]['status'] = 'cancelled';
		}
		$data = $payments[ $key ];
	}
	return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( $data ), 'headers' => [] ];
};
$makeOrder = static function ( bool $virtual = false ) use ( &$orders, &$products ): WC_Order {
	$product = new WC_Product_Simple();
	$product->set_name( 'ICEPAY regression fixture' );
	$product->set_regular_price( '10' );
	$product->set_virtual( $virtual );
	$product->set_downloadable( $virtual );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 10 );
	$product->save();
	$products[] = $product->get_id();
	$order = wc_create_order();
	$orders[] = $order->get_id();
	$order->set_currency( 'EUR' );
	$order->set_payment_method( 'icepay-card' );
	$order->add_product( $product, 1 );
	$order->set_billing_email( 'icepay-regression@example.invalid' );
	$order->calculate_totals( false );
	$order->save();
	return $order;
};
$payment = static function ( WC_Order $order, string $key, string $status = 'completed', string $method = 'card' ): array {
	return [ 'key' => $key, 'status' => $status, 'financialStatus' => 'uncleared',
		'amount' => [ 'value' => (int) round( (float) $order->get_total() * 100 ), 'currency' => 'eur' ],
		'paymentMethod' => [ 'type' => $method ], 'meta' => [ 'woocommerce' => [ 'order_id' => (string) $order->get_id() ] ],
		'links' => [ 'checkout' => 'https://checkout.icepay.com/checkout/' . $key ], 'isTest' => true ];
};

add_filter( 'pre_http_request', $mock, 10, 3 );
add_filter( 'pre_wp_mail', $onMail );
add_action( 'woocommerce_payment_complete', $onComplete );
update_option( Icepay::MERCHANT_ID, 'regression-merchant' );
update_option( Icepay::SECRET, 'regression-secret' );
try {
	delete_option( $cleanupOption );
	as_schedule_single_action( time() + 3600, 'icepay_reconcile_payment', $cleanupArgs, 'icepay' );
	$secondCleanupArgs = $cleanupArgs; $secondCleanupArgs[2] = 1;
	as_schedule_single_action( time() + 7200, 'icepay_reconcile_payment', $secondCleanupArgs, 'icepay' );
	as_schedule_single_action( time() + 3600, 'icepay_reconcile_payment', $cleanupArgs, 'icepay-test-other' );
	( new Integration() )->removeScheduledReconciliation();
	$assert( ! as_has_scheduled_action( 'icepay_reconcile_payment', null, 'icepay' )
		&& get_option( $cleanupOption ) === 'yes', 'One-time migration cancels legacy fallback jobs with different arguments' );
	$assert( as_has_scheduled_action( 'icepay_reconcile_payment', $cleanupArgs, 'icepay-test-other' ), 'Queued-job cleanup preserves actions in other groups' );
	$assert( ! has_action( 'icepay_reconcile_payment' ), 'Scheduled reconciliation callback is no longer registered' );
	$body = "{\n\"reference\":\"<order> / café\"\n}";
	$signature = base64_encode( hash_hmac( 'sha256', $body, 'regression-secret', true ) );
	$assert( Webhook::verifySignature( $body, $signature, 'regression-secret' ), 'Signature uses exact raw bytes including whitespace and special characters' );
	$assert( ! Webhook::verifySignature( sanitize_text_field( $body ), $signature, 'regression-secret' ), 'Modified signed body is rejected' );
	$assert( ! Webhook::verifySignature( $body, null, 'regression-secret' ) && ! Webhook::verifySignature( $body, $signature, '' ), 'Missing signature or merchant secret cannot authenticate webhook' );
	$handler = new PaymentStatus();
	$order = $makeOrder();
	$p = $payment( $order, 'pi-first' );
	Order::addAttempt( $order, $p, 'card' );
	$assert( Order::FindOrderByKey( 'pi-first' )->get_id() === $order->get_id(), 'Exact legacy-compatible payment-key lookup' );
	$assert( Order::FindOrderByKey( 'pi-fir' ) === null, 'Partial payment keys are rejected' );
	$wrong = $p; $wrong['amount']['value']++;
	$assert( is_wp_error( $handler->apply( $order, $wrong ) ), 'Amount mismatch cannot complete an order' );
	$wrong = $p; $wrong['amount']['currency'] = 'usd';
	$assert( is_wp_error( $handler->apply( $order, $wrong ) ), 'Currency mismatch cannot complete an order' );
	$wrong = $p; $wrong['isTest'] = false;
	$assert( is_wp_error( $handler->apply( $order, $wrong ) ), 'Processing-mode mismatch is rejected' );
	$wrong = $p; $wrong['key'] = 'pi-unknown';
	$assert( is_wp_error( $handler->apply( $order, $wrong ) ), 'Unknown payment cannot be attached by order ID' );
	$before = $completed;
	$assert( $handler->apply( $order, $p ) === true, 'Valid completed payment accepted' );
	$order = wc_get_order( $order->get_id() );
	$assert( $order->has_status( 'processing' ) && $order->get_date_paid() && $order->get_transaction_id() === 'pi-first', 'Physical order has correct status, payment date, and transaction ID' );
	$productId = array_values( $order->get_items() )[0]->get_product_id();
	$assert( (int) wc_get_product( $productId )->get_stock_quantity() === 9, 'Stock reduced once on payment completion' );
	$mailCount = $emails;
	$handler->apply( $order, $p );
	$p['status'] = 'pending'; $handler->apply( $order, $p );
	$p['status'] = 'cancelled'; $handler->apply( $order, $p );
	$assert( $completed === $before + 1 && $emails === $mailCount && (int) wc_get_product( $productId )->get_stock_quantity() === 9, 'Duplicate and stale updates do not repeat payment hooks, emails, or stock reduction' );
	$assert( wc_get_order( $order->get_id() )->has_status( 'processing' ), 'Paid order never regresses' );
	$p2 = $payment( $order, 'pi-second' ); Order::addAttempt( $order, $p2, 'card' );
	$handler->apply( $order, $p2 ); $handler->apply( $order, $p2 );
	$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
	$duplicateNotes = array_filter( $notes, static fn( $note ) => str_contains( $note->content, 'possible duplicate charge' ) );
	$assert( count( $duplicateNotes ) === 1 && wc_get_order( $order->get_id() )->get_transaction_id() === 'pi-first', 'Second completed payment flagged once without replacing transaction' );

	$order = $makeOrder( true ); $p = $payment( $order, 'pi-virtual' ); Order::addAttempt( $order, $p, 'card' );
	$handler->apply( $order, $p );
	$assert( wc_get_order( $order->get_id() )->has_status( 'completed' ), 'Virtual order completes through WooCommerce' );

	$order = $makeOrder(); $order->set_status( 'checkout-draft' ); $order->save();
	$p = $payment( $order, 'pi-draft' ); Order::addAttempt( $order, $p, 'card' ); $handler->apply( $order, $p );
	$assert( wc_get_order( $order->get_id() )->is_paid(), 'Blocks checkout-draft can complete payment' );

	foreach ( [ 'paybybank', 'eps', 'onlineueberweisen' ] as $method ) {
		$order = $makeOrder(); $p = $payment( $order, 'pi-bank-' . $method, 'completed', $method ); Order::addAttempt( $order, $p, 'card' );
		$handler->apply( $order, $p );
		$order = wc_get_order( $order->get_id() );
		$assert( $order->is_paid() && $order->get_date_paid() && $order->get_payment_method() === 'icepay-' . $method, $method . ' completed status confirms payment even with uncleared financial status' );
		$before = $completed;
		$p['financialStatus'] = 'cleared'; $handler->apply( $order, $p );
		$assert( $completed === $before, $method . ' financial-status change does not repeat completion' );
	}
	$order = $makeOrder(); $p = $payment( $order, 'pi-no-financial-status', 'completed', 'paybybank' ); unset( $p['financialStatus'] );
	Order::addAttempt( $order, $p, 'paybybank' ); $handler->apply( $order, $p );
	$assert( wc_get_order( $order->get_id() )->is_paid(), 'Completed payment requires no financialStatus field' );

	$order = $makeOrder(); $old = $payment( $order, 'pi-old', 'started' ); Order::addAttempt( $order, $old, 'card' );
	$new = $payment( $order, 'pi-new', 'pending' ); Order::addAttempt( $order, $new, 'card' ); $handler->apply( $order, $new );
	$old['status'] = 'cancelled'; $handler->apply( $order, $old );
	$assert( wc_get_order( $order->get_id() )->has_status( 'on-hold' ), 'Old cancellation cannot cancel newer attempt' );
	$old['status'] = 'completed'; $handler->apply( $order, $old );
	$assert( wc_get_order( $order->get_id() )->is_paid(), 'Late successful payment still accepted' );

	// Representative gateway IDs exercise provider-independent ownership rules;
	// external completion is simulated with WooCommerce's payment API.
	foreach ( [ 'mollie_wc_gateway_ideal', 'multisafepay_ideal', 'pay_gateway_ideal' ] as $provider ) {
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
			$assert( $fresh->has_status( 'pending' ) && $fresh->get_payment_method() === $provider
				&& $fresh->get_transaction_id() === 'external-' . $provider, $provider . ': ICEPAY ' . $status . ' preserves other provider order state' );
			$assert( $integration->paymentReceivedText( 'Other provider instructions', $fresh ) === 'Other provider instructions', $provider . ': historical ' . $status . ' attempt leaves receipt text unchanged' );
		}
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_icepay_creation_uncertain', time() ); $order->save();
		$assert( $integration->paymentReceivedText( 'Other provider instructions', $order ) === 'Other provider instructions', $provider . ': unknown ICEPAY creation does not override receipt' );
		$order->delete_meta_data( '_icepay_creation_uncertain' ); $order->save();
		$payments[ $p['key'] ] = $p;
		$payments[ $p['key'] ]['status'] = 'completed';
		$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key() ];
		try {
			$integration->redirect();
		} catch ( IcepayTestReturnRedirect $exception ) {
		}
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
		$assert( ( $conflict['gateway'] ?? '' ) === $provider && ( $conflict['transaction'] ?? '' ) === 'external-' . $provider
			&& ( $conflict['title'] ?? '' ) === 'Other provider iDEAL', $provider . ': previous provider details preserved for review' );
		$productId = array_values( $fresh->get_items() )[0]->get_product_id(); $mailCount = $emails;
		$handler->apply( $order, $p );
		$p['status'] = 'cancelled'; $handler->apply( $order, $p );
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$conflictNotes = array_filter( $notes, static fn( $note ) => str_contains( $note->content, 'completed after the order switched' ) );
		$assert( count( $conflictNotes ) === 1 && $completed === $before + 1 && $emails === $mailCount
			&& (int) wc_get_product( $productId )->get_stock_quantity() === 9, $provider . ': repeated conflict notifications preserve one note, completion, and stock reduction' );
		// The other provider arriving second cannot repeat WooCommerce completion.
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
		$assert( $fresh->is_paid() && $fresh->get_payment_method() === $provider && $fresh->get_transaction_id() === 'external-paid-' . $provider
			&& $completed === $before && $emails === $mailCount, $provider . ': external payment first is preserved even when ICEPAY caller has stale order' );
		$record = Order::getAttempts( $fresh )[ $p['key'] ];
		$assert( count( $duplicateNotes ) === 1 && ( $record['duplicate_gateway'] ?? '' ) === $provider
			&& ( $record['duplicate_transaction'] ?? '' ) === 'external-paid-' . $provider, $provider . ': additional ICEPAY success records existing transaction and flags duplicate once' );
	}

	$order = $makeOrder(); $gateway = new Gateway( new PaymentMethod( 'card', 'Card', '', 'card.svg' ) );
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $result['result'] === 'success' && str_contains( $result['redirect'], '/checkout/' ), 'Create falls back to checkout URL when direct URL missing' );
	$firstKey = Order::activeKey( wc_get_order( $order->get_id() ) );
	$count = $posts; $result = $gateway->process_payment( $order->get_id() );
	$secondKey = Order::activeKey( wc_get_order( $order->get_id() ) );
	$assert( $posts === $count + 1 && $result['result'] === 'success' && $secondKey !== $firstKey
		&& str_contains( $result['redirect'], $secondKey ), 'Repeated submission creates a fresh payment and redirect URL' );
	$assert( $payments[ $firstKey ]['status'] === 'started' && $payments[ $secondKey ]['paymentMethod']['type'] === 'card'
		&& isset( Order::getAttempts( wc_get_order( $order->get_id() ) )[ $firstKey ] ) && $cancellations === 0, 'Same-method retry keeps previous started payment open and tracked without cancellation' );
	$count = $posts;
	$key = Order::activeKey( wc_get_order( $order->get_id() ) );
	$payments[ $key ]['status'] = 'pending';
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $posts === $count && str_contains( $result['redirect'], 'via-icepay' ), 'Pending payment blocks a second payment and returns to confirmation' );
	$payments[ $key ]['status'] = 'expired';
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $posts === $count + 1 && $result['result'] === 'success', 'Confirmed expired payment permits a new attempt' );

	$order = $makeOrder(); $createMode = 'timeout';
	$result = $gateway->process_payment( $order->get_id() ); $count = $posts;
	$assert( $result['result'] === 'failure' && wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Creation timeout persists unknown outcome' );
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $posts === $count && $result['result'] === 'failure', 'Unknown creation outcome blocks blind retry' );
	$key = 'pi-regression-' . $posts; $payments[ $key ]['status'] = 'completed';
	$result = $handler->refresh( $order, $key );
	$assert( ! is_wp_error( $result ) && wc_get_order( $order->get_id() )->is_paid() && ! wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Authenticated attempt token recovers lost creation response' );

	$order = $makeOrder(); $createMode = 'reject'; $gateway->process_payment( $order->get_id() );
	$assert( ! wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Definitive rejection allows retry even with non-JSON error body' );
	$order = $makeOrder(); $createMode = 'malformed'; $gateway->process_payment( $order->get_id() );
	$assert( (bool) wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Malformed successful response treated as unknown creation outcome' );
	$createMode = 'normal';
	$order = $makeOrder(); $gateway->process_payment( $order->get_id() );
	$key = Order::activeKey( wc_get_order( $order->get_id() ) ); $count = $posts;
	$bankGateway = new Gateway( new PaymentMethod( 'paybybank', 'Pay by Bank', '', 'paybybank.svg' ) );
	$result = $bankGateway->process_payment( $order->get_id() );
	$assert( $payments[ $key ]['status'] === 'started' && $posts === $count + 1 && $result['result'] === 'success'
		&& $cancellations === 0, 'Changing methods creates a fresh payment without cancelling earlier attempt' );
	$newKey = Order::activeKey( wc_get_order( $order->get_id() ) );
	$assert( $newKey !== $key && $payments[ $newKey ]['paymentMethod']['type'] === 'paybybank'
		&& str_contains( $result['redirect'], $newKey ), 'New payment and redirect use the newly selected payment method' );
	$payments[ $newKey ]['status'] = 'pending';
	$handler->refresh( $order, $newKey );
	$assert( str_contains( ( new Integration() )->paymentReceivedText( '', wc_get_order( $order->get_id() ) ), 'do not pay again' ), 'Pending receipt explains confirmation without encouraging retry' );
	$payments[ $newKey ]['status'] = 'cancelled'; $handler->refresh( $order, $newKey );
	$assert( str_contains( ( new Integration() )->paymentReceivedText( '', wc_get_order( $order->get_id() ) ), 'icepay-retry' ), 'Cancelled receipt exposes nonce-protected retry link' );
	// Both independently created payments can still report success later.
	$payments[ $key ]['status'] = 'completed'; $handler->refresh( $order, $key );
	$assert( wc_get_order( $order->get_id() )->get_transaction_id() === $key, 'Older uncancelled payment can complete the order after a method switch' );
	$payments[ $newKey ]['status'] = 'completed'; $handler->refresh( $order, $newKey ); $handler->refresh( $order, $newKey );
	$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
	$duplicateNotes = array_filter( $notes, static fn( $note ) => str_contains( $note->content, 'possible duplicate charge' ) );
	$assert( count( $duplicateNotes ) === 1 && wc_get_order( $order->get_id() )->get_transaction_id() === $key, 'Additional success from fresh payment is flagged once without replacing first transaction' );

	$order = $makeOrder(); $p = $payment( $order, 'pi-changed' ); Order::addAttempt( $order, $p, 'card' );
	$order->set_total( '20' ); $order->save();
	$assert( is_wp_error( $handler->apply( $order, $p ) ) && ! wc_get_order( $order->get_id() )->is_paid(), 'Old payment cannot fulfil an order with an increased total' );

	$order = $makeOrder(); $createMode = 'timeout'; $gateway->process_payment( $order->get_id() );
	$key = 'pi-regression-' . $posts;
	$payments[ $key ]['meta']['woocommerce']['creation_token'] = 'wrong-token';
	$assert( is_wp_error( $handler->refresh( $order, $key ) ) && Order::activeKey( wc_get_order( $order->get_id() ) ) === '', 'Unknown payment cannot recover creation using the wrong attempt token' );
	$createMode = 'normal';

	$order = $makeOrder(); $gateway->process_payment( $order->get_id() );
	$key = Order::activeKey( wc_get_order( $order->get_id() ) ); $payments[ $key ]['status'] = 'completed';
	$payments[ $key ]['paymentMethod']['type'] = 'paybybank';
	$payments[ $key ]['financialStatus'] = 'uncleared';
	$assert( ! as_has_scheduled_action( 'icepay_reconcile_payment', null, 'icepay' ), 'Payment creation and lost-response recovery do not queue reconciliation jobs' );
	$handler->refresh( $order, $key );
	$assert( wc_get_order( $order->get_id() )->is_paid(), 'Explicit payment refresh confirms completion without scheduled fallback' );
	$order = $makeOrder(); $gateway->process_payment( $order->get_id() );
	$key = Order::activeKey( wc_get_order( $order->get_id() ) );
	$known = $payments[ $key ]; unset( $payments[ $key ] ); $count = $posts;
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $result['result'] === 'failure' && $posts === $count, 'API outage cannot trigger a second payment' );
	$assert( is_wp_error( $handler->refresh( $order, $key ) ) && ! as_has_scheduled_action( 'icepay_reconcile_payment', null, 'icepay' ), 'API failure does not schedule fallback retries' );
	$payments[ $key ] = $known;

	$order = $makeOrder(); $id = $order->get_id();
	$assert( OrderLock::acquire( $id ), 'Order lock acquired' );
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$lockName = 'icepay_' . sha1( $GLOBALS['wpdb']->prefix . ':' . $id );
	$assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lockName ) ) === '0', 'Independent database connection cannot acquire concurrent order lock' );
	OrderLock::release( $id );
	$assert( (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lockName ) ) === '1', 'Lock available after release' );
	$result = $gateway->process_payment( $id );
	$assert( $result['result'] === 'failure', 'Concurrent submission fails safely while another connection holds lock' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
	$other->close();

	$text = ( new Integration() )->paymentReceivedText( '', wc_get_order( $id ) );
	$assert( $text === '', 'Unrelated order keeps standard received text' );
	$assert( $cancellations === 0, 'Checkout and retry flows never call the payment cancellation API' );
	foreach ( [
		[ 'source_type' => 'typein', 'utm_source' => '(direct)' ],
		[ 'source_type' => 'utm', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer-sale' ],
		[ 'source_type' => 'referral', 'utm_source' => 'example.com', 'referrer' => 'https://example.com/' ],
	] as $attribution ) {
		$order = $makeOrder();
		foreach ( $attribution as $name => $value ) {
			$order->update_meta_data( '_wc_order_attribution_' . $name, $value );
		}
		$order->save();
		$p = $payment( $order, 'pi-attribution-' . $order->get_id() );
		Order::addAttempt( $order, $p, 'card' );
		$payments[ $p['key'] ] = $p;
		$_GET = [ 'via-icepay' => '1', 'key' => $order->get_order_key() ];
		$integration = new IcepayTestIntegration();
		try {
			$integration->redirect();
		} catch ( IcepayTestReturnRedirect $exception ) {
		}
		$fresh = wc_get_order( $order->get_id() );
		$assert( $fresh->is_paid() && $integration->redirectedOrder === $order->get_id(), 'Payment return confirms order before referrer-safe redirect for ' . $attribution['source_type'] );
		foreach ( $attribution as $name => $value ) {
			$assert( $fresh->get_meta( '_wc_order_attribution_' . $name ) === $value, 'Payment confirmation and return preserve attribution ' . $name . ' for ' . $attribution['source_type'] );
		}
	}
	$_GET = $originalQuery;
	WP_CLI::success( $checks . ' payment reliability checks passed.' );
} finally {
	as_unschedule_all_actions( 'icepay_reconcile_payment', $cleanupArgs, 'icepay' );
	if ( isset( $secondCleanupArgs ) ) {
		as_unschedule_all_actions( 'icepay_reconcile_payment', $secondCleanupArgs, 'icepay' );
	}
	as_unschedule_all_actions( 'icepay_reconcile_payment', $cleanupArgs, 'icepay-test-other' );
	if ( $originalCleanup === null ) { delete_option( $cleanupOption ); } else { update_option( $cleanupOption, $originalCleanup, false ); }
	$_GET = $originalQuery;
	remove_filter( 'pre_http_request', $mock );
	remove_action( 'woocommerce_payment_complete', $onComplete );
	foreach ( $orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( $products as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) { $product->delete( true ); }
	}
	foreach ( [ Icepay::MERCHANT_ID => $originalMerchant, Icepay::SECRET => $originalSecret ] as $name => $value ) {
		if ( $value === null ) { delete_option( $name ); } else { update_option( $name, $value ); }
	}
	remove_filter( 'pre_wp_mail', $onMail );
}
