import { test, expect, type Page } from '@playwright/test';
import { IcepaySimulatorPage } from '../pages/IcepaySimulatorPage';
import { BASE_URL, ICEPAY_E2E_TOKEN } from '../utils/env';
import {
    BLOCKS_RADIO_SELECTOR,
    WEBHOOK_STATUS_MAP,
} from '../utils/selectors';
import { addFirstProductToCart } from '../utils/shop';
import { resolveOrderIdFromUrl, pollOrderStatus, waitForReturnAndResolveOrderId } from '../utils/orderStatus';

const ICEPAY_CHECKOUT_HOST = 'checkout.icepay.com';

// The default WooCommerce Checkout page on this site uses the shortcode, so the
// test-helper provides a dedicated page that renders the Checkout block.
const resolveBlocksCheckoutPermalink = async (page: Page): Promise<string> => {
    const query = new URLSearchParams({
        icepay_test_helper: 'ensure-blocks-checkout',
        token: ICEPAY_E2E_TOKEN,
    });
    const response = await page.request.get(`${BASE_URL}/?${query.toString()}`);
    const body = await response.json() as { permalink?: string; error?: string };

    if (body.permalink === undefined) {
        throw new Error(`ensure-blocks-checkout endpoint failed: ${body.error ?? 'no permalink'}`);
    }

    return body.permalink;
};

const fillBlocksBillingNL = async (page: Page): Promise<void> => {
    // Wait for the Blocks checkout to finish hydrating before filling.
    await page.locator('#billing-first_name').waitFor({ state: 'visible', timeout: 30000 });

    await page.fill('#email', 'jan.janssen@example.nl');
    await page.selectOption('#billing-country', 'NL');
    await page.fill('#billing-first_name', 'Jan');
    await page.fill('#billing-last_name', 'Janssen');
    await page.fill('#billing-address_1', 'Teststraat 1');
    await page.fill('#billing-city', 'Amsterdam');
    await page.fill('#billing-postcode', '1043 DS');
    await page.fill('#billing-phone', '0612345678');
};

const addProductAndGoToBlocksCheckout = async (page: Page): Promise<void> => {
    await addFirstProductToCart(page);
    const permalink = await resolveBlocksCheckoutPermalink(page);
    await page.goto(permalink);
};

const selectIdealAndPlaceBlocksOrder = async (page: Page): Promise<void> => {
    const radio = page.locator(BLOCKS_RADIO_SELECTOR);
    await radio.waitFor({ state: 'visible', timeout: 10000 });
    await radio.check();

    const placeOrderButton = page.getByRole('button', { name: /place order/i });
    await placeOrderButton.waitFor({ state: 'visible', timeout: 10000 });
    await placeOrderButton.click();

    await page.waitForURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST, { timeout: 20000 });
};

test('it pays with iDEAL via blocks checkout and the order becomes processing', async ({ page }) => {
    await addProductAndGoToBlocksCheckout(page);
    await fillBlocksBillingNL(page);
    await selectIdealAndPlaceBlocksOrder(page);

    const simulator = new IcepaySimulatorPage(page);
    await simulator.selectOutcome('completed');

    const orderId = await waitForReturnAndResolveOrderId(page);
    await pollOrderStatus(page, orderId, WEBHOOK_STATUS_MAP.completed);
});

test('it returns to the order-pay page and leaves the order pending when backing out at ICEPAY via blocks checkout', async ({ page }) => {
    await addProductAndGoToBlocksCheckout(page);
    await fillBlocksBillingNL(page);
    await selectIdealAndPlaceBlocksOrder(page);

    const simulator = new IcepaySimulatorPage(page);
    await simulator.cancelByGoingBack();

    await expect(page).toHaveURL(/\/checkout\/order-pay\/\d+\/\?pay_for_order=true&key=wc_order_/);

    const orderId = resolveOrderIdFromUrl(page.url());
    await pollOrderStatus(page, orderId, 'pending');
});

test('it lets the payment expire via blocks checkout and the order becomes cancelled', async ({ page }) => {
    await addProductAndGoToBlocksCheckout(page);
    await fillBlocksBillingNL(page);
    await selectIdealAndPlaceBlocksOrder(page);

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

    await addProductAndGoToBlocksCheckout(page);
    await fillBlocksBillingNL(page);

    const radio = page.locator(BLOCKS_RADIO_SELECTOR);
    await radio.waitFor({ state: 'visible', timeout: 10000 });
    await radio.check();

    const placeOrderButton = page.getByRole('button', { name: /place order/i });
    await placeOrderButton.waitFor({ state: 'visible', timeout: 10000 });
    await placeOrderButton.click();

    const errorNotice = page.locator('.wc-block-store-notices, .wc-block-components-notice-banner, [role="alert"]');
    await expect(errorNotice.first()).toBeVisible({ timeout: 10000 });

    await expect(page).not.toHaveURL((url) => url.hostname === ICEPAY_CHECKOUT_HOST);
});
