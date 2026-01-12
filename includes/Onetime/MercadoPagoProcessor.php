<?php

namespace MercadoPagoFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
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
            'transaction_amount' => Arr::get($mpFormData, 'transaction_amount', 0),
            'token' => Arr::get($mpFormData, 'token', ''),
            'description' => __('Fct Order #', 'mercado-pago-for-fluent-cart') . $order->uuid,
            'installments' => Arr::get($mpFormData, 'installments', 1),
            'payment_method_id' => Arr::get($mpFormData, 'payment_method_id', ''),
            'issuer_id' => Arr::get($mpFormData, 'issuer_id', ''),
            'payer' => [
                'email' => $fcCustomer->email,
                'first_name' => $fcCustomer->first_name,
                'last_name' => $fcCustomer->last_name
            ],
            'external_reference' => $transaction->uuid,
            'metadata' => [
                'order_hash' => $order->uuid,
                'transaction_hash' => $transaction->uuid,
            ],
            // 'notification_url' => $this->getWebhookUrl(),
        ];

        if (Arr::get($mpFormData, 'payer.identification')) {
            $paymentData['payer']['identification'] = Arr::get($mpFormData, 'payer.identification', []);
        }


        $paymentData = apply_filters('fluent_cart/mercadopago/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);


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

        if (!in_array(Arr::get($result, 'status'), ['approved', 'authorized'])) {
            
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
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
    }
}

