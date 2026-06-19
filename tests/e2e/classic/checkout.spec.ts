import { test, expect, type Page } from '@playwright/test';
import { IcepaySimulatorPage } from '../pages/IcepaySimulatorPage';
import { BASE_URL, ICEPAY_E2E_TOKEN } from '../utils/env';
import {
    CLASSIC_RADIO_SELECTOR,
    CLASSIC_PLACE_ORDER_SELECTOR,
    WEBHOOK_STATUS_MAP,
} from '../utils/selectors';
import { addFirstProductToCart } from '../utils/shop';
import { resolveOrderIdFromUrl, pollOrderStatus, waitForReturnAndResolveOrderId } from '../utils/orderStatus';

const ICEPAY_CHECKOUT_HOST = 'checkout.icepay.com';

const resolveClassicCheckoutPermalink = async (page: Page): Promise<string> => {
    const query = new URLSearchParams({
        icepay_test_helper: 'ensure-classic-checkout',
        token: ICEPAY_E2E_TOKEN,
    });
    const response = await page.request.get(`${BASE_URL}/?${query.toString()}`);
    const body = await response.json() as { permalink?: string; path?: string; error?: string };

    if (body.error !== undefined) {
        throw new Error(`ensure-classic-checkout endpoint returned error: ${body.error}`);
    }

    if (body.permalink === undefined) {
        throw new Error('ensure-classic-checkout endpoint returned no permalink field');
    }

    return body.permalink;
};

const fillClassicBillingNL = async (page: Page): Promise<void> => {
    await page.fill('#billing_first_name', 'Jan');
    await page.fill('#billing_last_name', 'Janssen');
    await page.selectOption('#billing_country', 'NL');
    await page.fill('#billing_address_1', 'Teststraat 1');
    await page.fill('#billing_city', 'Amsterdam');
    await page.fill('#billing_postcode', '1043 DS');
    await page.fill('#billing_phone', '0612345678');
    await page.fill('#billing_email', 'jan.janssen@example.nl');
};

// WooCommerce classic checkout overlays the form with .blockOverlay during the
// update_order_review AJAX (triggered by changing the country or payment method).
// Give the AJAX a moment to start, then wait until no overlay remains.
const waitForCheckoutSettled = async (page: Page): Promise<void> => {
    await page.waitForTimeout(1500);
    await expect(page.locator('.blockOverlay')).toHaveCount(0, { timeout: 20000 });
};

const selectIdealAndPlaceOrder = async (page: Page): Promise<void> => {
    await waitForCheckoutSettled(page);
    // The radio input is visually hidden behind its label, so click the label and
    // confirm the radio became checked.
    await page.locator('label[for="payment_method_icepay-ideal"]').click();
    await expect(page.locator(CLASSIC_RADIO_SELECTOR)).toBeChecked();
    await waitForCheckoutSettled(page);
    await page.locator(CLASSIC_PLACE_ORDER_SELECTOR).click();
};

const addProductAndGoToClassicCheckout = async (page: Page): Promise<void> => {
    await addFirstProductToCart(page);
    const permalink = await resolveClassicCheckoutPermalink(page);
    await page.goto(permalink);
};

test('it pays with iDEAL via classic checkout and the order becomes processing', async ({ page }) => {
    await addProductAndGoToClassicCheckout(page);
    await fillClassicBillingNL(page);
    await selectIdealAndPlaceOrder(page);

    await page.waitForURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST, { timeout: 20000 });

    const simulator = new IcepaySimulatorPage(page);
    await simulator.selectOutcome('completed');

    const orderId = await waitForReturnAndResolveOrderId(page);
    await pollOrderStatus(page, orderId, WEBHOOK_STATUS_MAP.completed);
});

test('it returns to the order-pay page and leaves the order pending when backing out at ICEPAY via classic checkout', async ({ page }) => {
    await addProductAndGoToClassicCheckout(page);
    await fillClassicBillingNL(page);
    await selectIdealAndPlaceOrder(page);

    await page.waitForURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST, { timeout: 20000 });

    const simulator = new IcepaySimulatorPage(page);
    await simulator.cancelByGoingBack();

    await expect(page).toHaveURL(/\/checkout\/order-pay\/\d+\/\?pay_for_order=true&key=wc_order_/);

    const orderId = resolveOrderIdFromUrl(page.url());
    await pollOrderStatus(page, orderId, 'pending');
});

test('it lets the payment expire via classic checkout and the order becomes cancelled', async ({ page }) => {
    await addProductAndGoToClassicCheckout(page);
    await fillClassicBillingNL(page);
    await selectIdealAndPlaceOrder(page);

    await page.waitForURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST, { timeout: 20000 });

    const simulator = new IcepaySimulatorPage(page);
    await simulator.selectOutcome('expired');

    const orderId = await waitForReturnAndResolveOrderId(page);
    await pollOrderStatus(page, orderId, WEBHOOK_STATUS_MAP.expired);
});

test('it shows an error and leaves the order unpaid when payment creation fails', async ({ page }) => {
    await page.context().addCookies([
        {
            name: 'icepay_force_create_failure',
            value: '1',
            url: BASE_URL,
        },
    ]);

    await addProductAndGoToClassicCheckout(page);
    await fillClassicBillingNL(page);
    await selectIdealAndPlaceOrder(page);

    await expect(page.locator('.woocommerce-error')).toBeVisible({ timeout: 10000 });
    await expect(page).not.toHaveURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST);
});
