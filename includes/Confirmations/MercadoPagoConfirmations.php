<?php

namespace MercadoPagoFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use MercadoPagoFluentCart\API\MercadoPagoAPI;


if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoConfirmations
{
    public function init()
    {
        // Register AJAX handlers for payment confirmation (after payment is processed)
        add_action('wp_ajax_nopriv_fluent_cart_confirm_mercadopago_payment_onetime', [$this, 'confirmMercadoPagoSinglePayment']);
        add_action('wp_ajax_fluent_cart_confirm_mercadopago_payment_onetime', [$this, 'confirmMercadoPagoSinglePayment']);

    }

    public function confirmMercadoPagoSinglePayment()
    {
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                $this->confirmationFailed(400);
            }
        } else {
           $this->confirmationFailed(400);
        }

        $paymentId = isset($_REQUEST['payment_id']) ? sanitize_text_field(wp_unslash($_REQUEST['payment_id'])) : '';
        $transactionHash = isset($_REQUEST['transaction_hash']) ? sanitize_text_field(wp_unslash($_REQUEST['transaction_hash'])) : '';

        if (empty($paymentId) || empty($transactionHash)) {
           $this->confirmationFailed(400);
        }

        $transactionModel = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'mercado_pago')
            ->first();

        
        if (!$transactionModel || ($transactionModel->vendor_charge_id && $transactionModel->vendor_charge_id !== $paymentId)) {
            $this->confirmationFailed(404);
        }

        // Get payment details from Mercado Pago
        $payment = \MercadoPagoFluentCart\API\MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $paymentId);


        if (is_wp_error($payment)) {
           $this->confirmationFailed(400);
        }

        $paymentStatus = Arr::get($payment, 'status');

        if ($paymentStatus === 'approved' || $paymentStatus === 'authorized') {
            $billingInfo = [
                'type'                => Arr::get($payment, 'payment_type_id', 'card'),
                'last4'               => Arr::get($payment, 'card.last_four_digits'),
                'brand'               => Arr::get($payment, 'payment_method_id'),
                'payment_method_id'   => Arr::get($payment, 'payment_method_id'),
                'payment_method_type' => Arr::get($payment, 'payment_type_id'),
            ];

            $this->confirmPaymentSuccessByCharge($transactionModel, [
                'vendor_charge_id' => $paymentId,
                'charge'           => $payment,
                'billing_info'     => $billingInfo
            ]);

            wp_send_json(
                [
                    'redirect_url' => $transactionModel->getReceiptPageUrl(),
                    'order'        => [
                        'uuid' => $transactionModel->order->uuid,
                    ],
                    'message'      => __('Payment confirmed successfully. Redirecting...!', 'mercado-pago-for-fluent-cart')
                ], 200
            );
        }

        if ($paymentStatus === 'pending') {
            wp_send_json([
                'status'  => 'pending',
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'message' => __('Payment not approved/authorized yet', 'mercado-pago-for-fluent-cart')
            ], 200);
        }

        $this->confirmationFailed(400);
    }

    public function confirmPaymentSuccessByCharge(OrderTransaction $transactionModel, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'charge');
        $subscriptionData = Arr::get($args, 'subscription_data', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            return;
        }

        $amount = Arr::get($transactionData, 'transaction_amount', 0) * 100;
        $currency = strtoupper(Arr::get($transactionData, 'currency_id'));

        // Update transaction
        $transactionUpdateData = array_filter([
            'order_id'             => $order->id,
            'total'                => $amount,
            'currency'             => $currency,
            'status'               => Status::TRANSACTION_SUCCEEDED,
            'payment_method'       => 'mercado_pago',
            'card_last_4'          => Arr::get($billingInfo, 'last4', ''),
            'card_brand'           => Arr::get($billingInfo, 'brand', ''),
            'payment_method_type'  => Arr::get($billingInfo, 'payment_method_type', ''),
            'vendor_charge_id'     => $vendorChargeId,
            'meta'                 => array_merge($transactionModel->meta ?? [], $billingInfo)
        ]);

        $transactionModel->fill($transactionUpdateData);
        $transactionModel->save();

        fluent_cart_add_log(__('Mercado Pago Payment Confirmation', 'mercado-pago-for-fluent-cart'), __('Payment confirmation received from Mercado Pago. Payment ID:', 'mercado-pago-for-fluent-cart') . ' ' . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);
        
        (new StatusHelper($order))->syncOrderStatuses($transactionModel);

        return $order;
    }

    public function confirmationFailed($code = 422)
    {
        wp_send_json([
            'status'  => 'failed',
            'message' => __('Payment confirmation failed', 'mercado-pago-for-fluent-cart')
        ], $code);
    }
}