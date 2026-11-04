<?php

use Icepay\WooCommerce\Api\IcepayClient;

$client = IcepayClient::fromSettings();
$order = $makeOrder();
$valid = $payment( $order, 'pi-validation' );
$nullable = $valid;
$nullable['status'] = 'started';
$nullable['paymentMethod'] = null;
$nullable['meta'] = null;
$nullable['refunds'] = null;
$nullable['links']['direct'] = null;
$http = static fn() => [ 'response' => [ 'code' => 201, 'message' => 'Created' ], 'headers' => [], 'body' => wp_json_encode( $nullable ) ];
add_filter( 'pre_http_request', $http, 20 );
try {
	foreach ( [ 'create', 'get' ] as $operation ) {
		$result = $operation === 'create' ? $client->create( [] ) : $client->get( 'pi-validation' );
		$assert( $result[0] && ! isset( $result[1]['paymentMethod'], $result[1]['meta'] ) && $result[1]['refunds'] === []
			&& ! array_key_exists( 'direct', $result[1]['links'] ), 'Documented nullable fields normalized for payment ' . $operation );
		$assert( \Icepay\WooCommerce\Payment\PaymentStatusHandler::redirectUrl( $result[1] ) === $valid['links']['checkout'], 'Null direct link falls back to checkout URL for ' . $operation );
	}
} finally { remove_filter( 'pre_http_request', $http, 20 ); }

// Real creation shape retains merchant metadata, but sends null collection/direct fields.
$createMode = 'normal';
$normalizeMock = static function ( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) { return $response; }
	$body = json_decode( $response['body'], true );
	$body['refunds'] = null;
	$body['links']['direct'] = null;
	$response['body'] = wp_json_encode( $body );
	return $response;
};
add_filter( 'pre_http_request', $normalizeMock, 20 );
try {
	$order = $makeOrder();
	$gateway = new \Icepay\WooCommerce\Gateway( new \Icepay\WooCommerce\PaymentMethod( 'card', 'Card', '', 'card.svg' ) );
	$result = $gateway->process_payment( $order->get_id() );
	$assert( $result['result'] === 'success' && ! wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Nullable creation response redirects and clears uncertain marker' );
	$order = $makeOrder(); $createMode = 'timeout';
	$gateway->process_payment( $order->get_id() );
	$key = 'pi-regression-' . $posts;
	$payments[ $key ]['status'] = 'completed';
	$result = $handler->refresh( $order, $key );
	$assert( ! is_wp_error( $result ) && wc_get_order( $order->get_id() )->is_paid()
		&& ! wc_get_order( $order->get_id() )->get_meta( '_icepay_creation_uncertain' ), 'Authenticated recovery accepts nullable API fields and validates persisted creation token' );
} finally { remove_filter( 'pre_http_request', $normalizeMock, 20 ); $createMode = 'normal'; }
$malformed = [];
$p = $valid; unset( $p['key'] ); $malformed['missing key'] = $p;
$p = $valid; $p['amount']['value'] = '1000'; $malformed['string amount'] = $p;
$p = $valid; $p['amount']['value'] = -1; $malformed['negative amount'] = $p;
$p = $valid; $p['amount']['currency'] = []; $malformed['invalid currency'] = $p;
$p = $valid; $p['links'] = 'url'; $malformed['invalid links'] = $p;
$p = $valid; $p['meta']['woocommerce'] = 'order'; $malformed['invalid metadata'] = $p;
$p = $valid; $p['isTest'] = 'true'; $malformed['invalid test flag'] = $p;
$p = $valid; unset( $p['paymentMethod'] ); $malformed['completed without method'] = $p;
$p = $valid; $p['refunds'] = [ [ 'key' => 'pr-invalid' ] ]; $malformed['invalid nested refund'] = $p;
$p = $valid; $p['paymentMethod'] = null; $malformed['completed with null method'] = $p;

foreach ( $malformed as $case => $payload ) {
	$http = static fn() => [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [], 'body' => wp_json_encode( $payload ) ];
	add_filter( 'pre_http_request', $http, 20 );
	try {
		$result = $client->get( 'pi-validation' );
		$assert( $result[0] === false && ! $result[1]['uncertain'], 'Malformed GET rejected at transport boundary: ' . $case );
		$assert( str_contains( $result[1]['message'], 'Invalid fields:' ) && ! str_contains( $result[1]['message'], 'pi-validation' ), 'Validation diagnostic contains field names without payment data: ' . $case );
		$result = $client->create( [] );
		$assert( $result[0] === false && $result[1]['uncertain'], 'Malformed creation keeps unknown outcome: ' . $case );
	} finally { remove_filter( 'pre_http_request', $http, 20 ); }
}
$validRefund = [ 'key' => 'pr-validation', 'status' => 'pending', 'reference' => 'wc-validation', 'amount' => [ 'value' => 200, 'currency' => 'eur' ] ];
foreach ( [ 'valid' => $validRefund, 'invalid' => array_merge( $validRefund, [ 'amount' => [ 'value' => 2.5, 'currency' => 'eur' ] ] ) ] as $case => $payload ) {
	$http = static fn() => [ 'response' => [ 'code' => 201, 'message' => 'Created' ], 'headers' => [], 'body' => wp_json_encode( $payload ) ];
	add_filter( 'pre_http_request', $http, 20 );
	try {
		$result = $client->refund( 'pi-validation', [ 'amount' => [ 'value' => 200 ], 'reference' => 'wc-validation', 'description' => '' ] );
		$assert( $result[0] === ( $case === 'valid' ), 'Refund response validated at transport boundary: ' . $case );
		if ( $case === 'invalid' ) { $assert( $result[1]['uncertain'], 'Malformed refund success cannot permit blind retry' ); }
	} finally { remove_filter( 'pre_http_request', $http, 20 ); }
}
