<?php

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
