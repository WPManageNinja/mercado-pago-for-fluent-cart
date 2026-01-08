<?php

namespace MercadoPagoFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoHelper
{
    
    public static function checkCurrencySupport($currency = '')
    {
        $supportedCurrencies = self::getSupportedCurrencies();
        $currentCurrency = strtoupper($currency) ?? CurrencySettings::get('currency');

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


    public static function formatAmount($amount, $currency)
    {
        $zeroDecimalCurrencies = ['CLP'];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (float) round($amount);
        }

        return (float) number_format($amount / 100, 2, '.', '');
    }

 
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

  
    public static function getSubscriptionUpdateData($subscriptionDetails, $subscriptionModel)
    {
        $status = self::getFctSubscriptionStatus(Arr::get($subscriptionDetails, 'status'));
        
        $updateData = [
            'status' => $status,
        ];

        // Update next billing date if available
        $nextPaymentDate = Arr::get($subscriptionDetails, 'next_payment_date');
        if ($nextPaymentDate) {
            $updateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

     
        $payerId = Arr::get($subscriptionDetails, 'payer_id');
        if ($payerId) {
            $updateData['vendor_customer_id'] = $payerId;
        }

        return $updateData;
    }

    public static function formatPayerInfo($fcCustomer, $billingAddress)
    {
       $payerInfo = [
        'first_name' => $fcCustomer->first_name,
        'last_name'  => $fcCustomer->last_name,
       ];


        if ($billingAddress) {
            $payerInfo['address'] = [
                'zip_code'     => $billingAddress->postal_code ?? '',
                'street_name'  => $billingAddress->address_line_1 ?? '',
                'city'         => $billingAddress->city ?? '',
                'state'        => $billingAddress->state ?? '',
                'country'      => $billingAddress->country ?? '',
                'street_number' => '',
            ];
        }
       

       return $payerInfo;
    }
}

