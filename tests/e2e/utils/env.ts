export const BASE_URL: string = process.env.BASE_URL ?? 'https://icepay.test';

export const ICEPAY_WEBHOOK_TIMEOUT_MS: number = process.env.ICEPAY_WEBHOOK_TIMEOUT_MS !== undefined
    ? parseInt(process.env.ICEPAY_WEBHOOK_TIMEOUT_MS, 10)
    : 30000;

export const ICEPAY_E2E_TOKEN: string = process.env.ICEPAY_E2E_TOKEN ?? '';

export const WP_ADMIN_USER: string = process.env.WP_ADMIN_USER ?? 'admin';

export const WP_ADMIN_PASSWORD: string = process.env.WP_ADMIN_PASSWORD ?? 'admin';
