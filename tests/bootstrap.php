<?php

use Icepay\WooCommerce\Checkout\PaymentReceipt;
use Icepay\WooCommerce\Checkout\PaymentReturnController;
use Icepay\WooCommerce\Checkout\PaymentRetryController;

class IcepayTestReturnRedirect extends RuntimeException {}

class IcepayTestIntegration extends PaymentReturnController {
	public ?int $redirectedOrder = null;
	protected function redirectPaymentReturn( WC_Order $order ): void {
		$this->redirectedOrder = $order->get_id();
		throw new IcepayTestReturnRedirect();
	}
	public function redirect(): void { $this->handle(); }
	public function retryPayment(): void { ( new PaymentRetryController() )->handle(); }
	public function paymentReceivedText( string $text, $order ): string { return ( new PaymentReceipt() )->text( $text, $order ); }
}

require __DIR__ . '/Support/MockIcepayResponses.php';
require __DIR__ . '/Support/OrderFixtures.php';
