<?php

declare(strict_types=1);

namespace Icepay\WooCommerce;

class Order
{
    /** Order meta key storing the last ICEPAY financialStatus ('uncleared'|'cleared') received via webhook. */
    public const FINANCIAL_STATUS_META_KEY = '_icepay_financial_status';

    public static function getFinancialStatus(\WC_Order $order): ?string
    {
        $status = $order->get_meta(self::FINANCIAL_STATUS_META_KEY);

        return $status !== '' ? $status : null;
    }

    public static function setFinancialStatus(\WC_Order $order, string $financialStatus): void
    {
        $order->update_meta_data(self::FINANCIAL_STATUS_META_KEY, $financialStatus);
    }

    public static function FindOrderByKey(string $key): \WC_Order|null
    {
        $orders = wc_get_orders([
            'limit'    => 1,
            'meta_key' => 'icepay-payment-key',
            'meta_value' => $key,
            'meta_compare' => '=',
        ]);

        $orderId = current($orders) ? current($orders)->get_id() : false;

        if (!empty($orderId)) {
            $order = wc_get_order($orderId);
        }

        if (!empty($order) && $order->get_status() !== 'trash') {
            return $order;
        }

        return null;
    }
}
