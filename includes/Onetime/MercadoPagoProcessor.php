<?php

namespace MercadoPagoFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;
use FluentCart\Framework\Support\Arr;

class MercadoPagoProcessor
{
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Format amount based on currency
        $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);

        $paymentData = [
            'transaction_amount' => $amount,
            'description'        => sprintf(__('Order #%s', 'mercado-pago-for-fluent-cart'), $order->id),
            'payment_method_id'  => 'pix', // Will be determined by frontend
            'payer'              => [
                'email'      => $fcCustomer->email,
                'first_name' => $fcCustomer->first_name,
                'last_name'  => $fcCustomer->last_name,
                'address' => [
                    'zip_code'     => $order->billing_address->postal_code ?? '',
                    'street_name'  => $order->billing_address->address_line_1 ?? '',
                    'street_number' => '',
                ]
            ],
            'notification_url'   => $this->getWebhookUrl(),
            'external_reference' => $transaction->uuid,
            'metadata'           => [
                'order_id'         => $order->id,
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];


        $paymentData = apply_filters('fluent_cart/mercadopago/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);


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

