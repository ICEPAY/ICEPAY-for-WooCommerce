import { type Page } from '@playwright/test';
import { BASE_URL } from './env';

const SHOP_PATH = '/shop';
const CART_PATH = '/cart';
const CHECKOUT_PATH = '/checkout';

export const addFirstProductToCart = async (page: Page): Promise<void> => {
    await page.goto(`${BASE_URL}${SHOP_PATH}`);

    const addToCartButton = page.locator('.add_to_cart_button').first();
    await addToCartButton.waitFor({ state: 'visible' });
    await addToCartButton.click();

    await page.waitForSelector('.added_to_cart, .woocommerce-message', { timeout: 10000 });
};

export const goToCheckout = async (page: Page): Promise<void> => {
    await page.goto(`${BASE_URL}${CHECKOUT_PATH}`);
};

export const addProductAndGoToCheckout = async (page: Page): Promise<void> => {
    await addFirstProductToCart(page);
    await goToCheckout(page);
};

export const addProductBySlugAndGoToCheckout = async (page: Page, productSlug: string): Promise<void> => {
    await page.goto(`${BASE_URL}/?add-to-cart=${productSlug}`);
    await goToCheckout(page);
};

export const emptyCart = async (page: Page): Promise<void> => {
    await page.goto(`${BASE_URL}${CART_PATH}`);

    const removeLinks = page.locator('.remove');
    const count = await removeLinks.count();

    for (let i = 0; i < count; i++) {
        await removeLinks.first().click();
        await page.waitForSelector('.cart-empty, .woocommerce-info', { timeout: 5000 }).catch(() => undefined);
    }
};
