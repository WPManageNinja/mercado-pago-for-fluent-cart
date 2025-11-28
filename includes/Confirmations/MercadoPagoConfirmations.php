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
    exit; // Direct access not allowed.
}

class MercadoPagoConfirmations
{
    public function init()
    {
        // One-time payment handlers
        add_action('wp_ajax_nopriv_fluent_cart_create_mercadopago_payment', [$this, 'createMercadoPagoPayment']);
        add_action('wp_ajax_fluent_cart_create_mercadopago_payment', [$this, 'createMercadoPagoPayment']);
        
        add_action('wp_ajax_nopriv_fluent_cart_confirm_mercadopago_payment', [$this, 'confirmMercadoPagoPayment']);
        add_action('wp_ajax_fluent_cart_confirm_mercadopago_payment', [$this, 'confirmMercadoPagoPayment']);
        
        // Subscription handlers
        add_action('wp_ajax_nopriv_fluent_cart_create_mercadopago_subscription', [$this, 'createMercadoPagoSubscription']);
        add_action('wp_ajax_fluent_cart_create_mercadopago_subscription', [$this, 'createMercadoPagoSubscription']);
        
        add_action('wp_ajax_nopriv_fluent_cart_confirm_mercadopago_subscription', [$this, 'confirmMercadoPagoSubscription']);
        add_action('wp_ajax_fluent_cart_confirm_mercadopago_subscription', [$this, 'confirmMercadoPagoSubscription']);
    }

    /**
     * Create payment via Mercado Pago API (called from frontend)
     */
    public function createMercadoPagoPayment()
    {
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                wp_send_json([
                    'error' => true,
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                ], 400);
            }
        } else {
            wp_send_json([
                'error' => true,
                'message' => 'Nonce is required for security verification.',
            ], 400);
        }

        if (!isset($_REQUEST['payment_data'])) {
            wp_send_json([
                'error' => true,
                'message' => 'Payment data is required.',
            ], 400);
        }

        $paymentData = json_decode(wp_unslash($_REQUEST['payment_data']), true);

        if (!$paymentData) {
            wp_send_json([
                'error' => true,
                'message' => 'Invalid payment data format.',
            ], 400);
        }

        // Create payment via Mercado Pago API
        $payment = MercadoPagoAPI::createMercadoPagoObject('v1/payments', $paymentData);

        if (is_wp_error($payment)) {
            wp_send_json([
                'error' => true,
                'message' => $payment->get_error_message(),
            ], 500);
        }

        $paymentId = Arr::get($payment, 'id');
        $status = Arr::get($payment, 'status');

        if (!$paymentId) {
            wp_send_json([
                'error' => true,
                'message' => __('Failed to create payment.', 'mercado-pago-for-fluent-cart'),
            ], 500);
        }

        wp_send_json([
            'error' => false,
            'payment_id' => $paymentId,
            'status' => $status,
        ], 200);
    }


    public function confirmMercadoPagoPayment()
    {
        
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                wp_send_json([
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                    'status' => 'failed'
                ], 400);
            }
        } else {
            wp_send_json([
                'message' => 'Nonce is required for security verification.',
                'status' => 'failed'
            ], 400);
        }
        

        if (!isset($_REQUEST['payment_id'])) {
            wp_send_json([
                'message' => 'Payment ID is required to confirm the payment.',
                'status' => 'failed'
            ], 400);
        }

        $mercadoPagoPaymentId = sanitize_text_field(wp_unslash($_REQUEST['payment_id']) ?? '');
        
        $mercadoPagoPayment = MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $mercadoPagoPaymentId);

        if (is_wp_error($mercadoPagoPayment)) {
            wp_send_json([
                'message' => $mercadoPagoPayment->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');

        $transactionModel = null;

        if ($externalReference) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }

  
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'mercado-pago-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }

        $paymentStatus = Arr::get($mercadoPagoPayment, 'status');
        
        if ($paymentStatus !== 'approved') {
            wp_send_json([
                'message' => sprintf(
                    __('Payment status is %s. Please wait for approval.', 'mercado-pago-for-fluent-cart'),
                    $paymentStatus
                ),
                'status' => 'pending'
            ], 400);
        }

        $billingInfo = [
            'type'                => Arr::get($mercadoPagoPayment, 'payment_type_id', 'card'),
            'last4'               => Arr::get($mercadoPagoPayment, 'card.last_four_digits'),
            'brand'               => Arr::get($mercadoPagoPayment, 'card.first_six_digits'),
            'payment_method_id'   => Arr::get($mercadoPagoPayment, 'payment_method_id'),
            'payment_method_type' => Arr::get($mercadoPagoPayment, 'payment_type_id'),
        ];

        $this->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $mercadoPagoPaymentId,
            'charge'           => $mercadoPagoPayment,
            'billing_info'     => $billingInfo
        ]);

        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'mercado-pago-for-fluent-cart'),
            'status' => 'success'
        ], 200);
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

        $amount = Arr::get($transactionData, 'transaction_amount', 0);
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

        if ($order->type == status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transactionModel->subscription_id)->first();

            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transactionModel, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        return (new StatusHelper($order))->syncOrderStatuses($transactionModel);
    }

    public function createMercadoPagoSubscription()
    {
        // Verify nonce
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                wp_send_json([
                    'error' => true,
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                ], 400);
            }
        } else {
            wp_send_json([
                'error' => true,
                'message' => 'Nonce is required for security verification.',
            ], 400);
        }

        if (!isset($_REQUEST['subscription_data'])) {
            wp_send_json([
                'error' => true,
                'message' => 'Subscription data is required.',
            ], 400);
        }

        $subscriptionData = json_decode(wp_unslash($_REQUEST['subscription_data']), true);

        if (!$subscriptionData) {
            wp_send_json([
                'error' => true,
                'message' => 'Invalid subscription data format.',
            ], 400);
        }

        // Create preapproval (subscription) via Mercado Pago API
        $preapproval = MercadoPagoAPI::createMercadoPagoObject('preapproval', $subscriptionData);

        if (is_wp_error($preapproval)) {
            wp_send_json([
                'error' => true,
                'message' => $preapproval->get_error_message(),
            ], 500);
        }

        $subscriptionId = Arr::get($preapproval, 'id');
        $status = Arr::get($preapproval, 'status');

        if (!$subscriptionId) {
            wp_send_json([
                'error' => true,
                'message' => __('Failed to create subscription.', 'mercado-pago-for-fluent-cart'),
            ], 500);
        }

        wp_send_json([
            'error' => false,
            'subscription_id' => $subscriptionId,
            'status' => $status,
        ], 200);
    }

    public function confirmMercadoPagoSubscription()
    {
        // Verify nonce
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                wp_send_json([
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                    'status' => 'failed'
                ], 400);
            }
        } else {
            wp_send_json([
                'message' => 'Nonce is required for security verification.',
                'status' => 'failed'
            ], 400);
        }

        if (!isset($_REQUEST['subscription_id'])) {
            wp_send_json([
                'message' => 'Subscription ID is required to confirm the subscription.',
                'status' => 'failed'
            ], 400);
        }

        $mercadoPagoSubscriptionId = sanitize_text_field(wp_unslash($_REQUEST['subscription_id']) ?? '');
        $transactionHash = sanitize_text_field(wp_unslash($_REQUEST['transaction_hash']) ?? '');
        

        $mercadoPagoSubscription = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $mercadoPagoSubscriptionId);

        if (is_wp_error($mercadoPagoSubscription)) {
            wp_send_json([
                'message' => $mercadoPagoSubscription->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        $externalReference = Arr::get($mercadoPagoSubscription, 'external_reference', '');


        $transactionModel = null;

        if ($externalReference) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel && $transactionHash) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }


        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Subscription already confirmed. Redirecting...!', 'mercado-pago-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }

        $subscriptionStatus = Arr::get($mercadoPagoSubscription, 'status');
        

        $subscriptionModel = Subscription::query()
            ->where('id', $transactionModel->subscription_id)
            ->first();

        if (!$subscriptionModel) {
            wp_send_json([
                'message' => 'Subscription not found.',
                'status' => 'failed'
            ], 404);
        }


        $subscriptionModel->update([
            'vendor_subscription_id' => $mercadoPagoSubscriptionId,
            'status' => \MercadoPagoFluentCart\MercadoPagoHelper::getFctSubscriptionStatus($subscriptionStatus),
        ]);


        $transactionModel->update([
            'status' => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $mercadoPagoSubscriptionId,
        ]);


        $order = $transactionModel->order;
        (new StatusHelper($order))->syncOrderStatuses($transactionModel);

        fluent_cart_add_log(__('Mercado Pago Subscription Confirmation', 'mercado-pago-for-fluent-cart'), __('Subscription confirmed. ID:', 'mercado-pago-for-fluent-cart') . ' ' . $mercadoPagoSubscriptionId, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);

        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Subscription confirmed successfully. Redirecting...!', 'mercado-pago-for-fluent-cart'),
            'status' => 'success'
        ], 200);
    }
}

