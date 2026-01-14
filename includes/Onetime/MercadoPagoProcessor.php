<?php

namespace MercadoPagoFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\Framework\Support\Arr;

class MercadoPagoProcessor
{
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $mpFormData = Arr::get(App::request()->all(), 'mp_form_data', '');
        $mpSelectedPaymentMethod = sanitize_text_field(Arr::get(App::request()->all(), 'mp_selected_payment_method', ''));


        $mpFormData = json_decode($mpFormData, true) ?? [];

        $ipAddress = AddressHelper::getIpAddress();

        if (is_wp_error($mpFormData)) {
            return $mpFormData;
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Format amount based on currency
        $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);
        $payerInfo = MercadoPagoHelper::formatPayerInfo($fcCustomer, $order->billing_address, $mpFormData);

        $paymentData = [
            'transaction_amount' => Arr::get($mpFormData, 'transaction_amount', 0),
            'token' => Arr::get($mpFormData, 'token', ''),
            'description' => __('Fct Order #', 'mercado-pago-for-fluent-cart') . $order->uuid,
            'installments' => Arr::get($mpFormData, 'installments', 1),
            'payment_method_id' => Arr::get($mpFormData, 'payment_method_id', ''),
            'issuer_id' => Arr::get($mpFormData, 'issuer_id', ''),
            'payer' => $payerInfo,
            'external_reference' => $transaction->uuid,
            'metadata' => [
                'order_hash' => $order->uuid,
                'transaction_hash' => $transaction->uuid,
            ],
            'notification_url' => $this->getWebhookUrl(),
            'callback_url' => $transaction->getReceiptPageUrl(),
        ];

        if (Arr::get($payerInfo, 'address')) {
            $paymentData['payer']['address'] = Arr::get($payerInfo, 'address', []);
        }


        $paymentData = apply_filters('fluent_cart/mercadopago/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // unset every empty value from $paymentData
        $paymentData = array_filter($paymentData, function($value) {
            return !empty($value);
        });

        $result = MercadoPagoAPI::createMercadoPagoObject('v1/payments', $paymentData);

        if (is_wp_error($result)) {
            return [
                'status' => 'failed',
                'message' => $result->get_error_message(),
                'data' => [
                    'payment_data' => $paymentData,
                    'intent' => 'onetime',
                    'transaction_hash' => $transaction->uuid,
                    'amount' => $amount,
                    'currency' => $transaction->currency,
                ]
            ];
        }


        if (!in_array(Arr::get($result, 'status'), ['approved', 'authorized', 'pending'])) {
            
            return [
                'status' => 'failed',
                'message' => __('Payment failed', 'mercado-pago-for-fluent-cart'),
                'data' => [
                    'payment_data' => $paymentData,
                    'intent' => 'onetime',
                    'transaction_hash' => $transaction->uuid,
                    'amount' => $amount,
                    'currency' => $transaction->currency,
                ]
            ];
        }

        // update transaction status  + vendor charge id
        $transaction->update([
            'vendor_charge_id' => Arr::get($result, 'id', ''),
        ]);


        return [
            'status'       => 'success',
            'nextAction'   => 'mercado_pago',
            'actionName'   => 'custom',
            'message'      => __('Please complete your payment', 'mercado-pago-for-fluent-cart'),
            'data'         => [
                'payment' => $result,
                'order_data' => [
                    'order_hash' => $order->uuid,
                    'transaction_hash' => $transaction->uuid,
                ],
                'mode' => 'onetime',
                'receipt_page_url' => $transaction->getReceiptPageUrl(),
                'redirect_url' => Arr::get($result, 'transaction_details.external_resource_url', ''), // we might remove this later
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
    }
}

