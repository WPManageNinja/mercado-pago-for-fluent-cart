<?php

namespace MercadoPagoFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\DateTime\DateTime;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\Subscriptions\MercadoPagoSubscriptions;
use MercadoPagoFluentCart\Helpers\MercadoPagoHelper;


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

        // add_action('wp_ajax_nopriv_fluent_cart_confirm_mercadopago_subscription', [$this, 'confirmMercadoPagoSubscription']);
        // add_action('wp_ajax_fluent_cart_confirm_mercadopago_subscription', [$this, 'confirmMercadoPagoSubscription']);

        // confirm on redirect
        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPaymentOnReturn']);
        
    }

    public function maybeConfirmPaymentOnReturn($args = [])
    {
        $isReceipt = Arr::get($args, 'is_receipt', 'no');

        if ($isReceipt == 'yes') {
            return;
        }

        $orderHash = Arr::get($args, 'order_hash');
        $transactionHash = Arr::get($args, 'transaction_hash');

        if (empty($orderHash) || empty($transactionHash)) {
            return;
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'mercado_pago')
            ->first();

        if (!$transaction) {
            return;
        }
    
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        if ($transaction->subscription_id) {
            // Handle subscription confirmation on return
            $this->confirmMercadoPagoSubscriptionOrder($transaction);
        } else {
            // Handle one-time payment confirmation on return
            if (empty($transaction->vendor_charge_id)) {
                // No payment ID stored yet - can't confirm
                return;
            }

            $payment = MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $transaction->vendor_charge_id);
            
            if (is_wp_error($payment)) {
                return;
            }

            $paymentStatus = Arr::get($payment, 'status');
            
            if (!in_array($paymentStatus, ['approved', 'authorized'])) {
                return;
            }

            $billingInfo = [
                'type'                => Arr::get($payment, 'payment_type_id', 'card'),
                'last4'               => Arr::get($payment, 'card.last_four_digits'),
                'brand'               => Arr::get($payment, 'payment_method_id'),
                'payment_method_id'   => Arr::get($payment, 'payment_method_id'),
                'payment_method_type' => Arr::get($payment, 'payment_type_id'),
            ];
            
            $this->confirmPaymentSuccessByCharge($transaction, [
                'vendor_charge_id' => $transaction->vendor_charge_id,
                'charge'           => $payment,
                'billing_info'     => $billingInfo
            ]);
        }
    }

    public function confirmMercadoPagoSubscriptionOrder(OrderTransaction $transaction)
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $subscriptionModel = Subscription::query()
            ->where('id', $transaction->subscription_id)
            ->first();

        if (!$subscriptionModel) {
            return;
        }

        // First, try to find subscription by vendor_plan_id (preapproval_plan_id)
        $vendorPlanId = $subscriptionModel->vendor_plan_id;

        if (!$vendorPlanId) {
            // Fallback to meta if not in main field
            $vendorPlanId = $subscriptionModel->getMeta('vendor_plan_id', false);
        }

        if (!$vendorPlanId) {
            return;
        }

        // Search for subscriptions created from this plan in Mercado Pago
        $mercadoPagoSearchResult = MercadoPagoAPI::getMercadoPagoObject('preapproval/search', [
            'preapproval_plan_id' => $vendorPlanId
        ]);

        if (is_wp_error($mercadoPagoSearchResult)) {
            return;
        }

        $mercadoPagoSubscriptions = Arr::get($mercadoPagoSearchResult, 'results', []);

        if (empty($mercadoPagoSubscriptions)) {
            return;
        }

        // Find the subscription whose external_reference matches our subscription uuid
        $mercadoPagoSubscription = null;
        foreach ($mercadoPagoSubscriptions as $mpSub) {
            if (Arr::get($mpSub, 'external_reference') === $subscriptionModel->uuid) {
                $mercadoPagoSubscription = $mpSub;
                break;
            }
        }

        if (!$mercadoPagoSubscription) {
            return;
        }

        $vendorSubscriptionId = Arr::get($mercadoPagoSubscription, 'id');
        $mercadoPagoStatus = Arr::get($mercadoPagoSubscription, 'status');

        // Update subscription with vendor subscription ID
        $subscriptionUpdateData = [
            'vendor_subscription_id' => $vendorSubscriptionId,
            'vendor_customer_id'     => Arr::get($mercadoPagoSubscription, 'payer_id'),
            'status'                 => \MercadoPagoFluentCart\MercadoPagoHelper::getFctSubscriptionStatus($mercadoPagoStatus),
            'current_payment_method' => 'mercado_pago',
        ];

        // Update next billing date if available
        $nextPaymentDate = Arr::get($mercadoPagoSubscription, 'next_payment_date');
        if ($nextPaymentDate) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

        $subscriptionModel->update($subscriptionUpdateData);

        // Get card info if available
        $cardId = Arr::get($mercadoPagoSubscription, 'card_id');
        $payerId = Arr::get($mercadoPagoSubscription, 'payer_id');
        $billingInfo = [];

        if ($cardId && $payerId) {
            $card = MercadoPagoAPI::getMercadoPagoObject('v1/customers/' . $payerId . '/cards/' . $cardId);

            if (!is_wp_error($card) && Arr::get($card, 'id')) {
                $billingInfo = [
                    'type'             => 'card',
                    'last4'            => Arr::get($card, 'last_four_digits'),
                    'brand'            => Arr::get($card, 'payment_method.name'),
                    'payment_type_id'  => Arr::get($card, 'payment_method.payment_type_id'),
                    'expiration_month' => Arr::get($card, 'expiration_month'),
                    'expiration_year'  => Arr::get($card, 'expiration_year'),
                    'card_id'          => Arr::get($card, 'id'),
                ];
            }
        }

        if (!empty($billingInfo)) {
            $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        }

        // Check if subscription has trial or signup fee
        $hasTrial = $subscriptionModel->trial_days && $subscriptionModel->trial_days > 0;
        $signupFee = (int) $subscriptionModel->signup_fee;

        // Try to find the first authorized payment for this subscription
        $authorizedPayments = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $vendorSubscriptionId . '/transactions', [
            'offset' => 0,
            'limit' => 1
        ]);

        $paymentId = null;
        $paymentData = [];

        if (!is_wp_error($authorizedPayments)) {
            $payments = Arr::get($authorizedPayments, 'data', []);
            if (!empty($payments)) {
                $firstPayment = $payments[0];
                $paymentId = Arr::get($firstPayment, 'id');
                $paymentStatus = Arr::get($firstPayment, 'status');
                
                if (in_array($paymentStatus, ['approved', 'processed'])) {
                    $paymentData = $firstPayment;
                }
            }
        }

        // If we found a payment, use it
        if ($paymentId && !empty($paymentData)) {
            $this->confirmPaymentSuccessByCharge($transaction, [
                'vendor_charge_id'  => $paymentId,
                'charge'            => [
                    'transaction_amount' => Arr::get($paymentData, 'amount', $subscriptionModel->recurring_total / 100),
                    'currency_id'        => $subscriptionModel->currency,
                ],
                'billing_info'      => $billingInfo,
                'subscription_data' => ['vendor_subscription_id' => $vendorSubscriptionId]
            ]);
        } elseif ($hasTrial && in_array($mercadoPagoStatus, ['authorized', 'pending'])) {
            // Trial subscription - no payment expected yet
            $updateData = [
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id' => $vendorSubscriptionId . '_trial_authorized',
                'meta'             => array_merge($transaction->meta ?? [], [
                    'trial_authorization' => true,
                    'mercado_pago_status' => $mercadoPagoStatus,
                ])
            ];

            // If there's a signup fee that won't be collected, note this and adjust total
            if ($signupFee > 0) {
                $updateData['meta']['signup_fee_note'] = sprintf(
                    'Signup fee of %s was not collected. Mercado Pago preapproval_plan does not support initial fees.',
                    $signupFee / 100
                );
                $updateData['total'] = 0;
                
                fluent_cart_add_log(
                    __('Mercado Pago Signup Fee Not Collected', 'mercado-pago-for-fluent-cart'),
                    sprintf('Subscription %s has a signup fee of %s that could not be collected via Mercado Pago preapproval_plan.', $subscriptionModel->uuid, $signupFee / 100),
                    'warning',
                    ['module_name' => 'subscription', 'module_id' => $subscriptionModel->id]
                );
            }

            $transaction->update($updateData);
            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        } elseif (in_array($mercadoPagoStatus, ['authorized', 'pending'])) {
            // Non-trial subscription authorized but no payment found yet
            $transaction->update([
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id' => $vendorSubscriptionId . '_initial',
                'meta'             => array_merge($transaction->meta ?? [], [
                    'mercado_pago_status' => $mercadoPagoStatus,
                ])
            ]);

            (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
        }

        return $subscriptionModel;
    }



    /**
     * Process one-time payment via REST API (called from Payment Brick)
     */
    public function processPaymentViaAPI(\WP_REST_Request $request)
    {
        $formData = $request->get_json_params();
        $transactionId = Arr::get($formData, 'transaction_id');

        if (!$transactionId) {
            return new \WP_REST_Response([
                'error' => true,
                'message' => __('Transaction ID is required', 'mercado-pago-for-fluent-cart')
            ], 400);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionId)
            ->where('payment_method', 'mercado_pago')
            ->first();

        if (!$transaction) {
            return new \WP_REST_Response([
                'error' => true,
                'message' => __('Transaction not found', 'mercado-pago-for-fluent-cart')
            ], 404);
        }

        // Prepare payment data for Mercado Pago
        $paymentData = [
            'transaction_amount' => floatval($transaction->total),
            'token'              => Arr::get($formData, 'token'),
            'description'        => 'Order #' . $transaction->order_id,
            'installments'       => intval(Arr::get($formData, 'installments', 1)),
            'payment_method_id'  => Arr::get($formData, 'payment_method_id'),
            'issuer_id'          => Arr::get($formData, 'issuer_id', ''),
            'payer'              => [
                'email' => Arr::get($formData, 'payer.email'),
                'identification' => [
                    'type'   => Arr::get($formData, 'payer.identification.type'),
                    'number' => Arr::get($formData, 'payer.identification.number'),
                ],
            ],
            'external_reference' => $transactionId,
        ];

        // Create payment in Mercado Pago
        $payment = MercadoPagoAPI::createMercadoPagoObject('v1/payments', $paymentData);

        if (is_wp_error($payment)) {
            return new \WP_REST_Response([
                'error'   => true,
                'message' => $payment->get_error_message()
            ], 400);
        }

        return new \WP_REST_Response($payment, 200);
    }

    public function createSubscriptionViaAPI(\WP_REST_Request $request)
    {
        $formData = $request->get_json_params();
        $transactionId = Arr::get($formData, 'transaction_id');
        $subscriptionUuid = Arr::get($formData, 'subscription_id');

        if (!$transactionId || !$subscriptionUuid) {
            return new \WP_REST_Response([
                'error' => true,
                'message' => __('Transaction ID and Subscription ID are required', 'mercado-pago-for-fluent-cart')
            ], 400);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionId)
            ->where('payment_method', 'mercado_pago')
            ->first();

        $subscription = Subscription::query()
            ->where('uuid', $subscriptionUuid)
            ->first();

        if (!$transaction || !$subscription) {
            return new \WP_REST_Response([
                'error' => true,
                'message' => __('Transaction or subscription not found', 'mercado-pago-for-fluent-cart')
            ], 404);
        }

        // Create card token first
        $cardTokenData = [
            'card_number'      => Arr::get($formData, 'card_number'),
            'security_code'    => Arr::get($formData, 'security_code'),
            'expiration_month' => Arr::get($formData, 'expiration_month'),
            'expiration_year'  => Arr::get($formData, 'expiration_year'),
            'cardholder'       => [
                'name'           => Arr::get($formData, 'cardholder.name'),
                'identification' => [
                    'type'   => Arr::get($formData, 'payer.identification.type'),
                    'number' => Arr::get($formData, 'payer.identification.number'),
                ],
            ],
        ];

        $cardToken = MercadoPagoAPI::createMercadoPagoObject('v1/card_tokens', $cardTokenData);

        if (is_wp_error($cardToken)) {
            return new \WP_REST_Response([
                'error'   => true,
                'message' => $cardToken->get_error_message()
            ], 400);
        }

        // Create subscription in Mercado Pago
        $subscriptionData = [
            'reason'              => $subscription->plan_name,
            'auto_recurring'      => [
                'frequency'           => 1,
                'frequency_type'      => $this->getFrequencyType($subscription->billing_interval),
                'transaction_amount'  => floatval($subscription->recurring_amount),
                'currency_id'         => strtoupper($transaction->currency),
            ],
            'back_url'            => site_url(),
            'payer_email'         => Arr::get($formData, 'payer.email'),
            'card_token_id'       => Arr::get($cardToken, 'id'),
            'status'              => 'authorized',
            'external_reference'  => $subscriptionUuid,
        ];

        $mercadoPagoSubscription = MercadoPagoAPI::createMercadoPagoObject('preapproval', $subscriptionData);

        if (is_wp_error($mercadoPagoSubscription)) {
            return new \WP_REST_Response([
                'error'   => true,
                'message' => $mercadoPagoSubscription->get_error_message()
            ], 400);
        }

        return new \WP_REST_Response($mercadoPagoSubscription, 200);
    }


    private function getFrequencyType($interval)
    {
        $map = [
            'day'   => 'days',
            'week'  => 'months', // Mercado Pago doesn't have weeks, use months
            'month' => 'months',
            'year'  => 'months',
        ];

        return $map[$interval] ?? 'months';
    }

    public function confirmMercadoPagoSinglePayment()
    {
        if (isset($_REQUEST['mercadopago_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
                wp_send_json([
                    'status'  => 'failed',
                    'message' => __('Invalid nonce', 'mercado-pago-for-fluent-cart')
                ], 400);
            }
        } else {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Nonce is required for security verification.', 'mercado-pago-for-fluent-cart')
            ], 400);
        }

        $paymentId = isset($_REQUEST['payment_id']) ? sanitize_text_field(wp_unslash($_REQUEST['payment_id'])) : '';
        $transactionHash = isset($_REQUEST['transaction_hash']) ? sanitize_text_field(wp_unslash($_REQUEST['transaction_hash'])) : '';

        if (empty($paymentId) || empty($transactionHash)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Invalid payment confirmation request', 'mercado-pago-for-fluent-cart')
            ], 422);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'mercado_pago')
            ->first();

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found', 'mercado-pago-for-fluent-cart')
            ], 404);
        }

        // Get payment details from Mercado Pago
        $payment = \MercadoPagoFluentCart\API\MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $paymentId);

        if (is_wp_error($payment)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $payment->get_error_message()
            ], 422);
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

            $this->confirmPaymentSuccessByCharge($transaction, [
                'vendor_charge_id' => $paymentId,
                'charge'           => $payment,
                'billing_info'     => $billingInfo
            ]);

            wp_send_json(
                [
                    'redirect_url' => $transaction->getReceiptPageUrl(),
                    'order'        => [
                        'uuid' => $transaction->order->uuid,
                    ],
                    'message'      => __('Payment confirmed successfully. Redirecting...!', 'mercado-pago-for-fluent-cart')
                ], 200
            );
        }

        if ($paymentStatus === 'pending' || $paymentStatus === 'in_process') {
            wp_send_json([
                'status'  => 'pending',
                'redirect_url' => $transaction->getReceiptPageUrl(),
                'message' => __('Payment not approved/authorized yet, maybe in process or pending, please check back later.', 'mercado-pago-for-fluent-cart')
            ], 200);
        }

        wp_send_json([
            'status'  => 'failed',
            'message' => __('Payment confirmation failed', 'mercado-pago-for-fluent-cart')
        ], 422);
    }

 
    // public function confirmMercadoPagoSubscription()
    // {
    //     if (isset($_REQUEST['mercadopago_fct_nonce'])) {
    //         $nonce = sanitize_text_field(wp_unslash($_REQUEST['mercadopago_fct_nonce']));
    //         if (!wp_verify_nonce($nonce, 'mercadopago_fct_nonce')) {
    //             wp_send_json([
    //                 'status'  => 'failed',
    //                 'message' => __('Invalid nonce', 'mercado-pago-for-fluent-cart')
    //             ], 400);
    //         }
    //     }
    // }

    public function confirmPaymentSuccessByCharge(OrderTransaction $transactionModel, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'charge');
        $subscriptionData = Arr::get($args, 'subscription_data', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            // return;
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

        if ($order->type == status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transactionModel->subscription_id)->first();

            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // No subscription found for this renewal order. Something is wrong.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transactionModel, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);


        } else {

            $subscription = Subscription::query()->where('id', $transactionModel->subscription_id)->first();

            if ($subscription && !in_array($subscription->status, Status::getValidableSubscriptionStatuses())) {
                (new MercadoPagoSubscriptions())->confirmSubscriptionAfterChargeSucceeded($subscription, $billingInfo, Arr::get($subscriptionData, 'vendor_subscription_id'));
            }

            (new StatusHelper($order))->syncOrderStatuses($transactionModel);
        }

        return $order;
    }

    public function confirmSubscriptionPaymentSuccess(OrderTransaction $transactionModel, $args = [])
    {
        $vendorSubscriptionId = Arr::get($args, 'vendor_subscription_id');
        $subscriptionData = Arr::get($args, 'subscription');

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();
        if (!$order) {
            return;
        }

        $subscription = Subscription::query()->where('parent_order_id', $order->id)->first();
        if (!$subscription) {
            return;
        }

        $subscriptionStatus = Arr::get($subscriptionData, 'status');
        $amount = Arr::get($subscriptionData, 'auto_recurring.transaction_amount', 0);
        $currency = strtoupper(Arr::get($subscriptionData, 'auto_recurring.currency_id'));

        // Update transaction
        $transactionUpdateData = array_filter([
            'order_id'             => $order->id,
            'total'                => $amount,
            'currency'             => $currency,
            'status'               => Status::TRANSACTION_SUCCEEDED,
            'payment_method'       => 'mercado_pago',
            'vendor_charge_id'     => $vendorSubscriptionId,
            'meta'                 => array_merge($transactionModel->meta ?? [], ['subscription_data' => $subscriptionData])
        ]);

        $transactionModel->fill($transactionUpdateData);
        $transactionModel->save();

        // Update subscription
        $subscription->update([
            'vendor_subscription_id' => $vendorSubscriptionId,
            'status'                 => \MercadoPagoFluentCart\MercadoPagoHelper::getFctSubscriptionStatus($subscriptionStatus),
            'vendor_customer_id'     => Arr::get($subscriptionData, 'payer_id'),
        ]);

        fluent_cart_add_log(__('Mercado Pago Subscription Confirmation', 'mercado-pago-for-fluent-cart'), __('Subscription confirmation received from Mercado Pago. Subscription ID:', 'mercado-pago-for-fluent-cart') . ' ' . $vendorSubscriptionId, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);

        return (new StatusHelper($order))->syncOrderStatuses($transactionModel);
    }

    public function createMercadoPagoSubscription()
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

        if (empty($_REQUEST['subscription_data'])) {
            wp_send_json([
                'error' => true,
                'message' => __('Subscription data is required.', 'mercado-pago-for-fluent-cart'),
            ], 400);
        }

        $subscriptionData = json_decode(sanitize_text_field(wp_unslash($_REQUEST['subscription_data'])), true);

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
}