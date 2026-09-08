<?php

declare(strict_types=1);

namespace Icepay\WooCommerce\Tests\Unit;

use Icepay\WooCommerce\PaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * Covers the "hold shipment until financial status cleared" logic added for
 * TODO-008-implement-financial-status-shipment-hold.md: eligibility, the per-method
 * settings, and the amount-threshold rule shared by Webhook and Gateway::process_refund().
 */
final class PaymentMethodClearingTest extends TestCase
{
    protected function setUp(): void
    {
        \icepay_test_reset_options();
    }

    /** @dataProvider eligibleTypesProvider */
    public function testSupportsClearingHoldOnlyForEligibleTypes(string $type, bool $expected): void
    {
        $method = $this->makePaymentMethod($type);

        $this->assertSame($expected, $method->supportsClearingHold());
    }

    public static function eligibleTypesProvider(): array
    {
        return [
            'ideal is not eligible' => ['ideal', false],
            'paybybank is eligible' => ['paybybank', true],
            'onlineueberweisen is eligible' => ['onlineueberweisen', true],
            'card is not eligible' => ['card', false],
            'bancontact is not eligible' => ['bancontact', false],
            'paypal is not eligible' => ['paypal', false],
            'eps is eligible' => ['eps', true],
            'cartebancaire is not eligible' => ['cartebancaire', false],
            'dankort is not eligible' => ['dankort', false],
        ];
    }

    public function testWaitsForClearingIsFalseByDefault(): void
    {
        $method = $this->makePaymentMethod('paybybank');

        $this->assertFalse($method->waitsForClearing());
    }

    public function testWaitsForClearingReflectsSetting(): void
    {
        $method = $this->makePaymentMethod('paybybank', ['wait_for_clearing' => 'yes']);

        $this->assertTrue($method->waitsForClearing());
    }

    public function testWaitsForClearingIsFalseForIneligibleTypeEvenIfSettingSomehowSet(): void
    {
        // Defensive: the admin UI never exposes this setting for ineligible types, but the getter
        // must not trust stray option data for a type that isn't eligible.
        $method = $this->makePaymentMethod('paypal', ['wait_for_clearing' => 'yes']);

        $this->assertFalse($method->waitsForClearing());
    }

    public function testClearingThresholdAmountDefaultsToZero(): void
    {
        $method = $this->makePaymentMethod('paybybank', ['wait_for_clearing' => 'yes']);

        $this->assertSame(0.0, $method->getClearingThresholdAmount());
    }

    public function testClearingThresholdAmountIsCastFromStoredString(): void
    {
        $method = $this->makePaymentMethod('paybybank', [
            'wait_for_clearing' => 'yes',
            'clearing_threshold_amount' => '49.95',
        ]);

        $this->assertSame(49.95, $method->getClearingThresholdAmount());
    }

    public function testShouldHoldForAmountIsFalseWhenHoldDisabled(): void
    {
        $method = $this->makePaymentMethod('paybybank', ['clearing_threshold_amount' => '10']);

        $this->assertFalse($method->shouldHoldForAmount(100.0));
    }

    public function testShouldHoldForAmountAlwaysHoldsWhenThresholdIsZeroOrEmpty(): void
    {
        $method = $this->makePaymentMethod('paybybank', ['wait_for_clearing' => 'yes']);

        $this->assertTrue($method->shouldHoldForAmount(0.01));
        $this->assertTrue($method->shouldHoldForAmount(0.0));
    }

    public function testShouldHoldForAmountBoundaryJustBelowThresholdDoesNotHold(): void
    {
        $method = $this->makePaymentMethod('paybybank', [
            'wait_for_clearing' => 'yes',
            'clearing_threshold_amount' => '50',
        ]);

        $this->assertFalse($method->shouldHoldForAmount(49.99));
    }

    public function testShouldHoldForAmountBoundaryExactlyAtThresholdHolds(): void
    {
        $method = $this->makePaymentMethod('paybybank', [
            'wait_for_clearing' => 'yes',
            'clearing_threshold_amount' => '50',
        ]);

        $this->assertTrue($method->shouldHoldForAmount(50.0));
    }

    public function testShouldHoldForAmountBoundaryJustAboveThresholdHolds(): void
    {
        $method = $this->makePaymentMethod('paybybank', [
            'wait_for_clearing' => 'yes',
            'clearing_threshold_amount' => '50',
        ]);

        $this->assertTrue($method->shouldHoldForAmount(50.01));
    }

    public function testFindByGatewayIdReturnsMatchingMethod(): void
    {
        $method = PaymentMethod::findByGatewayId('icepay-paybybank');

        $this->assertNotNull($method);
        $this->assertSame('icepay-paybybank', $method->getId());
    }

    public function testFindByGatewayIdReturnsNullForUnknownGateway(): void
    {
        $this->assertNull(PaymentMethod::findByGatewayId('some-other-gateway'));
    }

    protected function makePaymentMethod(string $type, array $options = []): PaymentMethod
    {
        \icepay_test_set_option('icepay-for-woocommerce_icepay-' . $type . '_settings', $options);

        return new PaymentMethod($type, 'Test method', '', 'icon.svg');
    }
}
