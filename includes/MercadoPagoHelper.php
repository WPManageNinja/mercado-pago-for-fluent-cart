<?php
/**
 * Mercado Pago Helper Class
 *
 * @package MercadoPagoFluentCart
 * @since 1.0.0
 */

namespace MercadoPagoFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class MercadoPagoHelper
{
    /**
     * Check if currency is supported by Mercado Pago
     */
    public static function checkCurrencySupport()
    {
        $supportedCurrencies = self::getSupportedCurrencies();
        $currentCurrency = fluent_cart_get_currency();

        if (!in_array($currentCurrency, $supportedCurrencies)) {
            wp_send_json([
                'status' => 'failed',
                'message' => sprintf(
                    __('Mercado Pago does not support %s currency. Supported currencies are: %s', 'mercado-pago-for-fluent-cart'),
                    $currentCurrency,
                    implode(', ', $supportedCurrencies)
                )
            ], 400);
        }

        return true;
    }

    /**
     * Get list of supported currencies
     */
    public static function getSupportedCurrencies()
    {
        return [
            'ARS', // Argentina Peso
            'BRL', // Brazilian Real
            'CLP', // Chilean Peso
            'MXN', // Mexican Peso
            'COP', // Colombian Peso
            'PEN', // Peruvian Sol
            'UYU', // Uruguayan Peso
            'USD', // US Dollar
        ];
    }

    /**
     * Get FluentCart subscription status from Mercado Pago status
     */
    public static function getFctSubscriptionStatus($mercadoPagoStatus)
    {
        $statusMap = [
            'authorized' => Status::SUBSCRIPTION_ACTIVE,
            'paused'     => Status::SUBSCRIPTION_PAUSED,
            'cancelled'  => Status::SUBSCRIPTION_CANCELED,
            'pending'    => Status::SUBSCRIPTION_PENDING,
        ];

        return $statusMap[$mercadoPagoStatus] ?? Status::SUBSCRIPTION_PENDING;
    }

    /**
     * Get order from transaction hash
     */
    public static function getOrderFromTransactionHash($transactionHash)
    {
        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'mercado_pago')
            ->first();

        if ($transaction) {
            return Order::query()->where('id', $transaction->order_id)->first();
        }

        return null;
    }

    /**
     * Format amount for Mercado Pago (some currencies require cents, others don't)
     */
    public static function formatAmount($amount, $currency)
    {
        // Currencies that don't use decimal points (0 decimal places)
        $zeroDecimalCurrencies = ['CLP'];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (float) round($amount);
        }

        // Default: 2 decimal places
        return (float) number_format($amount, 2, '.', '');
    }

    /**
     * Get payment type from Mercado Pago payment method
     */
    public static function getPaymentType($paymentTypeId)
    {
        $typeMap = [
            'credit_card' => 'card',
            'debit_card'  => 'card',
            'ticket'      => 'boleto',
            'bank_transfer' => 'bank_transfer',
            'account_money' => 'mercado_pago_wallet',
            'pix'         => 'pix',
        ];

        return $typeMap[$paymentTypeId] ?? $paymentTypeId;
    }
}

