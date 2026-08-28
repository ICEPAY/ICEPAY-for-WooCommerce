import { type Page, expect } from '@playwright/test';
import { type IcepayOutcome } from '../utils/selectors';

const ICEPAY_CHECKOUT_URL_PATTERN = /checkout\.icepay\.com\//;
const ICEPAY_NAVIGATE_TIMEOUT_MS = 20000;

const OUTCOME_BUTTON_NAMES: Partial<Record<IcepayOutcome, string>> = {
    completed: 'Complete',
    expired: 'Expire',
};

export class IcepaySimulatorPage {
    constructor(private readonly page: Page) {}

    async assertMethodIs(method: string): Promise<void> {
        await this.page.waitForURL(ICEPAY_CHECKOUT_URL_PATTERN, { timeout: ICEPAY_NAVIGATE_TIMEOUT_MS });

        const expectedHeading = `Test Payment for ${method.toLowerCase()}`;
        const h1 = this.page.locator('h1');

        await expect(h1).toHaveText(expectedHeading, { timeout: ICEPAY_NAVIGATE_TIMEOUT_MS });
    }

    async selectOutcome(outcome: IcepayOutcome): Promise<void> {
        if (outcome === 'cancelled') {
            await this.cancelByGoingBack();
            return;
        }

        const buttonName = OUTCOME_BUTTON_NAMES[outcome];

        if (buttonName === undefined) {
            throw new Error(`No button mapped for outcome "${outcome}". ` +
                `Supported non-cancel outcomes: ${Object.keys(OUTCOME_BUTTON_NAMES).join(', ')}.`);
        }

        const button = this.page.getByRole('button', { name: buttonName });
        const isVisible = await button.isVisible().catch(() => false);

        if (!isVisible) {
            throw new Error(
                `ICEPAY simulator: button "${buttonName}" not found on page "${this.page.url()}". ` +
                `The ICEPAY test page layout may have changed.`
            );
        }

        await button.click();
    }

    async cancelByGoingBack(): Promise<void> {
        // TODO: capture live selectors against https://checkout.icepay.com/checkout/{key}
        // The cancel path navigates back twice via ICEPAY's own UI controls:
        //   1. Click "back" on the method-specific payment page
        //      -> lands on the all-payment-methods page at checkout.icepay.com
        //   2. Click "back" on the all-payment-methods page
        //      -> browser returns to WooCommerce order-pay:
        //         /checkout/order-pay/{id}/?pay_for_order=true&key=wc_order_...
        // No webhook fires; the order stays "pending".
        // Best-guess selectors (replace with live-verified ones in tasks 005/006):
        const backControl = this.page.getByRole('link', { name: /back/i });

        const isBackVisible = await backControl.isVisible().catch(() => false);

        if (!isBackVisible) {
            const backButton = this.page.getByRole('button', { name: /back/i });
            const isButtonVisible = await backButton.isVisible().catch(() => false);

            if (!isButtonVisible) {
                throw new Error(
                    `ICEPAY simulator: no "back" control found on page "${this.page.url()}". ` +
                    `The back link/button selector needs to be updated with live ICEPAY page selectors.`
                );
            }

            await backButton.click();
        } else {
            await backControl.click();
        }

        await this.page.waitForURL(ICEPAY_CHECKOUT_URL_PATTERN, { timeout: ICEPAY_NAVIGATE_TIMEOUT_MS });

        const secondBackControl = this.page.getByRole('link', { name: /back/i });
        const isSecondBackVisible = await secondBackControl.isVisible().catch(() => false);

        if (!isSecondBackVisible) {
            const secondBackButton = this.page.getByRole('button', { name: /back/i });
            const isButtonVisible = await secondBackButton.isVisible().catch(() => false);

            if (!isButtonVisible) {
                throw new Error(
                    `ICEPAY simulator: no "back" control found on payment-methods page "${this.page.url()}". ` +
                    `The back link/button selector needs to be updated with live ICEPAY page selectors.`
                );
            }

            await secondBackButton.click();
        } else {
            await secondBackControl.click();
        }

        await this.page.waitForURL(/\/checkout\/order-pay\//, { timeout: ICEPAY_NAVIGATE_TIMEOUT_MS });
    }
}
