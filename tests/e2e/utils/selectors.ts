export const GATEWAY_ID = 'icepay-ideal' as const;

export const GATEWAY_TITLE = 'iDEAL | Wero' as const;

export const BLOCKS_RADIO_SELECTOR = '#radio-control-wc-payment-method-options-icepay-ideal' as const;

export const CLASSIC_RADIO_SELECTOR = '#payment_method_icepay-ideal' as const;

export const CLASSIC_PLACE_ORDER_SELECTOR = '#place_order' as const;

export const WEBHOOK_STATUS_MAP = {
    completed: 'processing',
    cancelled: 'cancelled',
    expired: 'cancelled',
} as const;

export type IcepayOutcome = keyof typeof WEBHOOK_STATUS_MAP;

export type WcStatusSlug = typeof WEBHOOK_STATUS_MAP[IcepayOutcome];
