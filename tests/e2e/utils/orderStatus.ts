import { type Page, expect } from '@playwright/test';
import { BASE_URL, ICEPAY_WEBHOOK_TIMEOUT_MS, ICEPAY_E2E_TOKEN } from './env';

const POLL_INTERVAL_MS = 1000;

const withToken = (params: Record<string, string>): Record<string, string> =>
    ICEPAY_E2E_TOKEN !== '' ? { ...params, token: ICEPAY_E2E_TOKEN } : params;

const buildStatusUrl = (params: Record<string, string>): string => {
    const query = new URLSearchParams(withToken({
        icepay_test_helper: 'order-status',
        ...params,
    }));
    return `${BASE_URL}/?${query.toString()}`;
};

const buildHomeUrlEndpoint = (): string => {
    const query = new URLSearchParams(withToken({
        icepay_test_helper: 'home-url',
    }));
    return `${BASE_URL}/?${query.toString()}`;
};

export const readHomeUrl = async (page: Page): Promise<string> => {
    const response = await page.request.get(buildHomeUrlEndpoint());
    const body = await response.json() as { home_url?: string; error?: string };

    if (body.error !== undefined) {
        throw new Error(`home-url endpoint returned error: ${body.error}`);
    }

    if (body.home_url === undefined) {
        throw new Error('home-url endpoint returned no home_url field');
    }

    return body.home_url;
};

export const assertBaseUrlMatchesHomeUrl = async (page: Page): Promise<void> => {
    const homeUrl = await readHomeUrl(page);

    const baseHost = new URL(BASE_URL).host;
    const homeHost = new URL(homeUrl).host;

    if (baseHost !== homeHost) {
        throw new Error(
            `webhook will never arrive: BASE_URL must equal WordPress home_url. ` +
            `BASE_URL host is "${baseHost}" but WordPress home_url host is "${homeHost}".`
        );
    }
};

export const resolveOrderIdFromUrl = (url: string): string => {
    const parsed = new URL(url);

    const pathMatch = parsed.pathname.match(/\/order-(?:received|pay)\/(\d+)\//);
    if (pathMatch !== null) {
        return pathMatch[1];
    }

    const orderKey = parsed.searchParams.get('key');
    if (orderKey !== null && orderKey !== '') {
        return orderKey;
    }

    throw new Error(
        `Could not resolve order id from URL: "${url}". ` +
        `Expected path matching /order-received/{id}/ or /order-pay/{id}/ or a ?key= param.`
    );
};

export const waitForReturnAndResolveOrderId = async (
    page: Page,
    timeoutMs: number = 30000,
): Promise<string> => {
    const baseHost = new URL(BASE_URL).host;

    await page.waitForURL(
        (url) => url.hostname === baseHost && /\/order-(?:received|pay)\/\d+\//.test(url.pathname),
        { timeout: timeoutMs },
    );

    return resolveOrderIdFromUrl(page.url());
};

const fetchOrderStatus = async (page: Page, params: Record<string, string>): Promise<string> => {
    const response = await page.request.get(buildStatusUrl(params));
    const body = await response.json() as { status?: string; error?: string };

    if (body.error !== undefined) {
        throw new Error(`order-status endpoint returned error: ${body.error}`);
    }

    if (body.status === undefined) {
        throw new Error('order-status endpoint returned no status field');
    }

    return body.status;
};

export const pollOrderStatus = async (
    page: Page,
    orderId: string,
    expectedStatus: string,
    timeoutMs: number = ICEPAY_WEBHOOK_TIMEOUT_MS,
): Promise<void> => {
    const deadline = Date.now() + timeoutMs;
    const isOrderKey = orderId.startsWith('wc_order_');
    const params: Record<string, string> = isOrderKey ? { order_key: orderId } : { order_id: orderId };

    while (Date.now() < deadline) {
        const status = await fetchOrderStatus(page, params);

        if (status === expectedStatus) {
            return;
        }

        await new Promise<void>((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
    }

    const finalStatus = await fetchOrderStatus(page, params);
    expect(finalStatus).toBe(expectedStatus);

    throw new Error(
        `Order status did not reach "${expectedStatus}" within ${timeoutMs}ms. ` +
        `Final status was "${finalStatus}". Order id/key: "${orderId}".`
    );
};
