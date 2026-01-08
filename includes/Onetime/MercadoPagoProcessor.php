<?php

namespace MercadoPagoFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\Framework\Support\Arr;

class MercadoPagoProcessor
{
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $mpFormData = Arr::get(App::request()->all(), 'mp_form_data', '');
        if (empty($mpFormData)) {
            return new \WP_Error(
                'mercado_pago_form_data_missing',
                __('Mercado Pago form data is missing', 'mercado-pago-for-fluent-cart'),
                ['response' => $mpFormData]
            );
        }

        $mpFormData = json_decode($mpFormData, true);

        $ipAddress = AddressHelper::getIpAddress();

        if (is_wp_error($mpFormData)) {
            return $mpFormData;
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Format amount based on currency
        $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);
        $payerInfo = MercadoPagoHelper::formatPayerInfo($fcCustomer, $order->billing_address);

        $paymentData = [
            'x-idempotency-key' => $transaction->uuid,
            'additional_info' => [
                'ip-address' => $ipAddress,
                'payer' => [
                    'first_name' => $fcCustomer->first_name,
                    'last_name' => $fcCustomer->last_name,
                ]
            ],
            'payer' => [
                'email' => $fcCustomer->email,
                'first_name' => $fcCustomer->first_name,
                'last_name' => $fcCustomer->last_name

            ],
            'transaction_amount' => MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency),
            'token' => Arr::get($mpFormData, 'token', ''),
            'installments' => Arr::get($mpFormData, 'installments', 1),
            'payment_method_id' => Arr::get($mpFormData, 'payment_method_id', ''),
        ];


        $paymentData = apply_filters('fluent_cart/mercadopago/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);


        $result = MercadoPagoAPI::createMercadoPagoObject('payments', $paymentData);

        if (is_wp_error($paymentData)) {

        return [
            'status'       => 'success',
            'nextAction'   => 'mercado_pago',
            'actionName'   => 'custom',
            'message'      => __('Please complete your payment', 'mercado-pago-for-fluent-cart'),
            'data'         => [
                'payment_data'     => $paymentData,
                'intent'           => 'onetime',
                'transaction_hash' => $transaction->uuid,
                'amount'           => $amount,
                'currency'         => $transaction->currency,
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
    }
}

