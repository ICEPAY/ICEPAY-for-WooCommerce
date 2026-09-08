<?php

declare(strict_types=1);

namespace Icepay\WooCommerce\Tests\Unit;

use Icepay\WooCommerce\PaymentMethod;
use Icepay\WooCommerce\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Covers Webhook::determineStatus(), the pure decision function extracted from Webhook::handle() so
 * the ICEPAY status + financialStatus -> WooCommerce order status mapping (including the clearing
 * hold from TODO-008-implement-financial-status-shipment-hold.md) can be tested without booting
 * WordPress/WooCommerce.
 */
final class WebhookDetermineStatusTest extends TestCase
{
    protected function setUp(): void
    {
        \icepay_test_reset_options();
    }

    public function testCompletedWithoutFinancialStatusIsProcessingAsBefore(): void
    {
        // Backward compatibility: if the API/older postbacks ever omit financialStatus, behaviour
        // must be unchanged from before this feature existed.
        $status = Webhook::determineStatus('completed', null, null, 100.0);

        $this->assertSame('processing', $status);
    }

    public function testCompletedAndClearedIsProcessing(): void
    {
        $method = $this->holdingMethod(0.0);

        $status = Webhook::determineStatus('completed', 'cleared', $method, 100.0);

        $this->assertSame('processing', $status);
    }

    public function testCancelledIgnoresFinancialStatus(): void
    {
        $method = $this->holdingMethod(0.0);

        $this->assertSame('cancelled', Webhook::determineStatus('cancelled', 'uncleared', $method, 100.0));
        $this->assertSame('cancelled', Webhook::determineStatus('expired', 'uncleared', $method, 100.0));
    }

    public function testUnrecognisedStatusFallsBackToPending(): void
    {
        $this->assertSame('pending', Webhook::determineStatus('started', 'uncleared', null, 100.0));
    }

    public function testCompletedAndUnclearedWithNoMatchingPaymentMethodIsProcessing(): void
    {
        // e.g. gateway id didn't resolve to a known PaymentMethod - fail open to existing behaviour
        // rather than holding an order we can't evaluate settings for.
        $status = Webhook::determineStatus('completed', 'uncleared', null, 100.0);

        $this->assertSame('processing', $status);
    }

    public function testCompletedAndUnclearedIsProcessingWhenHoldDisabled(): void
    {
        $method = $this->nonHoldingMethod();

        $status = Webhook::determineStatus('completed', 'uncleared', $method, 100.0);

        $this->assertSame('processing', $status);
    }

    public function testCompletedAndUnclearedIsOnHoldWhenHoldEnabledAndAboveThreshold(): void
    {
        $method = $this->holdingMethod(50.0);

        $status = Webhook::determineStatus('completed', 'uncleared', $method, 50.0);

        $this->assertSame('on-hold', $status);
    }

    public function testCompletedAndUnclearedIsProcessingWhenBelowThreshold(): void
    {
        // "from amount": a merchant can accept the risk for small orders and ship immediately.
        $method = $this->holdingMethod(50.0);

        $status = Webhook::determineStatus('completed', 'uncleared', $method, 49.99);

        $this->assertSame('processing', $status);
    }

    public function testSubsequentClearedPostbackReleasesAPreviouslyHeldOrder(): void
    {
        // Simulates the two-postback scenario: an "uncleared" postback holds the order, and a later
        // "cleared" postback (still status completed) resolves back to processing. Webhook::handle()
        // relies on its existing allowed-transition list (which already includes 'on-hold') to apply
        // this - unchanged by this feature.
        $method = $this->holdingMethod(0.0);

        $first = Webhook::determineStatus('completed', 'uncleared', $method, 100.0);
        $second = Webhook::determineStatus('completed', 'cleared', $method, 100.0);

        $this->assertSame('on-hold', $first);
        $this->assertSame('processing', $second);
    }

    protected function holdingMethod(float $thresholdAmount): PaymentMethod
    {
        \icepay_test_set_option('icepay-for-woocommerce_icepay-paybybank_settings', [
            'wait_for_clearing' => 'yes',
            'clearing_threshold_amount' => (string) $thresholdAmount,
        ]);

        return new PaymentMethod('paybybank', 'Pay by Bank', '', 'paybybank.svg');
    }

    protected function nonHoldingMethod(): PaymentMethod
    {
        \icepay_test_set_option('icepay-for-woocommerce_icepay-paybybank_settings', []);

        return new PaymentMethod('paybybank', 'Pay by Bank', '', 'paybybank.svg');
    }
}
