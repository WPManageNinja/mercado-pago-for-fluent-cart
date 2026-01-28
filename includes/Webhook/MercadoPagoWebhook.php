<?php

namespace MercadoPagoFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;
use MercadoPagoFluentCart\Confirmations\MercadoPagoConfirmations;
use MercadoPagoFluentCart\MercadoPagoHelper;
use MercadoPagoFluentCart\Refund\MercadoPagoRefund;
use MercadoPagoFluentCart\API\MercadoPagoAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MercadoPagoWebhook
{
    public function init()
    {
        // Payment webhooks (type: payment) - for one-time payment confirmation
        add_action('fluent_cart/payments/mercado_pago/webhook_payment_approved', [$this, 'processPaymentApproved'], 10, 1);
        
        // Orders webhooks (type: orders) - for one-time payment confirmation (Checkout Transparente, Point, QR Code)
        // add_action('fluent_cart/payments/mercado_pago/webhook_orders_approved   ', [$this, 'processOrdersApproved'], 10, 1);

        add_action('fluent_cart/payments/mercado_pago/webhook_payment_updated', [$this, 'processPaymentUpdated'], 10, 1);

        // Subscription webhooks - subscription_preapproval for creation/status changes, subscription_authorized_payment for recurring payments
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_preapproval', [$this, 'processSubscriptionUpdate'], 10, 1);
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_authorized_payment', [$this, 'processSubscriptionPayment'], 10, 1);
        
        add_action('fluent_cart/payments/mercado_pago/webhook_payment_refunded', [$this, 'processPaymentRefunded'], 10, 1);
    }

    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();
        if (is_wp_error($payload)) {
            http_response_code(400);
            exit('Not valid payload');
        }

        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON payload');
        }

        if (!$this->verifySignature($data)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        // Mercado Pago sends notification type and ID
        $action = Arr::get($data, 'action');
        $resourceId = Arr::get($data, 'data.id');
        $type = Arr::get($data, 'type');

        if (!$action || !$resourceId) {
            http_response_code(404);
            exit('Missing required webhook data');
        }

        $resource = $this->fetchResource($type, $resourceId);

        $order = $this->getFluentCartOrder($data, $resource);

        if (!$order) {
            http_response_code(200);
            exit('Order not found');
        }

        // Determine event name based on type
        // For subscription webhooks, use the type (subscription_preapproval, subscription_authorized_payment)
        // For payment webhooks, use action (payment_approved, payment_updated, etc.)
        $event = str_replace('.', '_', $action);
        
        // For subscription-related webhooks, use the type as the event
        $subscriptionTypes = ['subscription_preapproval', 'subscription.preapproval', 'subscription_authorized_payment', 'subscription.authorized_payment'];
        if (in_array($type, $subscriptionTypes)) {
            $event = str_replace('.', '_', $type);
        }

        if (has_action('fluent_cart/payments/mercado_pago/webhook_' . $event)) {
            do_action('fluent_cart/payments/mercado_pago/webhook_' . $event, [
                'payload' => $data,
                'order'   => $order,
                'resource' => $resource
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        http_response_code(200);
        exit('Webhook not handled');
    }


    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');
        
        // Check payload size (max 1MB)
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }
        
        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }
        
        return $input;
    }

    /**
     * Verify webhook signature according to Mercado Pago documentation
     * https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications/webhooks
     */
    private function verifySignature($payload)
    {
        $xSignature = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
        $xRequestId = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));

        $queryParams = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $dataID = $queryParams['data_id'] ?? ($queryParams['data.id'] ?? '');

        if (!$xSignature || !$xRequestId || !$dataID) {
            return false;
        }

        $ts = $hash = null;
        foreach (explode(',', $xSignature) as $part) {
            [$key, $value] = array_map('trim', explode('=', $part, 2) + [null, null]);
            if ($key === 'ts') $ts = $value;
            elseif ($key === 'v1') $hash = $value;
            if ($ts !== null && $hash !== null) break;
        }

        if ($ts === null || $hash === null) {
            return false;
        }

        $secret = (new MercadoPagoSettingsBase())->getWebhookSecretKey('current');
        if (!$secret) {
            fluent_cart_add_log(
                __('Mercado Pago Webhook Verification', 'mercado-pago-for-fluent-cart'),
                __('Webhook secret is missing. Verification failed.', 'mercado-pago-for-fluent-cart'),
                'error',
                [
                    'log_type' => 'payment'
                ]
            );
            return false;
        }
        $manifest = "id:$dataID;request-id:$xRequestId;ts:$ts;";

        return hash_hmac('sha256', $manifest, $secret) === $hash;

    }
    
    private function fetchResource($type, $resourceId)
    {
        $endpoint = '';
        
        switch ($type) {
            case 'payment':
                // One-time payment confirmation and refunds
                $endpoint = 'v1/payments/' . $resourceId;
                break;
            case 'orders':
                // One-time payment for Checkout Transparente, Point, and QR Code
                $endpoint = 'v1/orders/' . $resourceId;
                break;
            case 'subscription.preapproval':
            case 'subscription_preapproval':
                // Subscription creation and cancellation
                $endpoint = 'preapproval/' . $resourceId;
                break;
            case 'subscription.authorized_payment':
            case 'subscription_authorized_payment':
                // Recurring subscription payment received
                $endpoint = 'authorized_payments/' . $resourceId;
                break;
            default:
                // Ignore other webhook types (plan updates, etc.)
                return new \WP_Error('ignored_webhook_type', 'Webhook type not needed: ' . $type);
        }

        return MercadoPagoAPI::getMercadoPagoObject($endpoint);
    }

    public function processPaymentUpdated($data)
    {
        $mercadoPagoPayment = Arr::get($data, 'resource');

        $mercadoPagoPaymentId = Arr::get($mercadoPagoPayment, 'id');
        $paymentStatus = Arr::get($mercadoPagoPayment, 'status');

        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');

        $transactionModel = null;


        if ($externalReference && !$transactionModel) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }


        if (!$transactionModel) {
            $transactionModel = OrderTransaction::query()
                ->where('vendor_charge_id', $mercadoPagoPaymentId)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }
    

        if (!$transactionModel) {
            $this->sendResponse(404, 'Transaction not found for the provided reference.');
        }

        if ($paymentStatus === 'approved') {
            $this->processPaymentApproved($data);
        }

        if ($paymentStatus === 'rejected') {
            $this->processPaymentFailed($data);
        }

        if ($paymentStatus === 'cancelled') {
            $this->processPaymentFailed($data);
        }

        if ($paymentStatus === 'refunded') {
            $this->processPaymentRefunded($data);
        }

        if ($paymentStatus === 'expired') {
            $this->processPaymentFailed($data);
        }

        $this->sendResponse(200, 'Payment status updated');
        
    }

    public function processPaymentFailed($data)
    {
        $mercadoPagoPayment = Arr::get($data, 'resource');
        $mercadoPagoPaymentId = Arr::get($mercadoPagoPayment, 'id');
        $paymentStatus = Arr::get($mercadoPagoPayment, 'status');
        $order = Arr::get($data, 'order');
        
        $transactionModel = null;

        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');
        if ($externalReference) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            $transactionModel = OrderTransaction::query()
                ->where('vendor_charge_id', $mercadoPagoPaymentId)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            $this->sendResponse(404, 'Transaction not found for the provided reference.');
        }

        $oldStatus = $order->payment_status;

        $transactionModel->update([
            'status' => Status::TRANSACTION_FAILED,
        ]);

        $order->update([
            'payment_status' => Status::PAYMENT_FAILED,
        ]);

        (new OrderPaymentFailed($order, $transactionModel, $oldStatus, Status::PAYMENT_FAILED))->dispatch();
        
        $this->sendResponse(200, 'Payment expired');
    }



    public function processPaymentApproved($data)
    {
        $mercadoPagoPayment = Arr::get($data, 'resource');
        $mercadoPagoPaymentId = Arr::get($mercadoPagoPayment, 'id');
        $paymentStatus = Arr::get($mercadoPagoPayment, 'status');

        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');

        $transactionModel = null;

        if ($externalReference) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            $transactionModel = OrderTransaction::query()
                ->where('vendor_charge_id', $mercadoPagoPaymentId)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            $this->sendResponse(404, 'Transaction not found for the provided reference.');
        }

        if ($transactionModel->status == Status::TRANSACTION_SUCCEEDED && $paymentStatus == 'approved') {
            $this->sendResponse(200, 'Payment already confirmed.');
        }

        $billingInfo = [
            'type'                => Arr::get( $mercadoPagoPayment, 'payment_type_id'),
            'last4'               => Arr::get($mercadoPagoPayment, 'card.last_four_digits'),
            'brand'               => Arr::get( $mercadoPagoPayment,  'payment_method_id'),
            'payment_method_id'   => Arr::get( $mercadoPagoPayment,  'payment_method_id'),
            'payment_method_type' => Arr::get( $mercadoPagoPayment,  'payment_type_id'),
        ];


        (new MercadoPagoConfirmations())->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $mercadoPagoPaymentId,
            'charge'           => $mercadoPagoPayment,
            'billing_info'     => $billingInfo
        ]);

        $this->sendResponse(200, 'Payment confirmed successfully');

    }
    
    public function processSubscriptionUpdate($data)
    {
        // Handles subscription creation, activation, cancellation, and pause
        $mercadoPagoSubscription = Arr::get($data, 'resource');
        $vendorSubscriptionId = Arr::get($mercadoPagoSubscription, 'id');
        $preapprovalPlanId = Arr::get($mercadoPagoSubscription, 'preapproval_plan_id');
        $payerEmail = Arr::get($mercadoPagoSubscription, 'payer_email');
        $externalReference = Arr::get($mercadoPagoSubscription, 'external_reference');
        
        $order = Arr::get($data, 'order');

        // Strategy 1: Find by order (if order was found via getFluentCartOrder)
        $subscriptionModel = null;
        if ($order) {
            $subscriptionModel = Subscription::query()
                ->where('parent_order_id', $order->id)
                ->first();
        }

        // Strategy 2: Find by vendor_subscription_id (if already linked)
        if (!$subscriptionModel) {
            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $vendorSubscriptionId)
                ->first();
        }

        // Strategy 3: Find by external_reference (if subscription was created with it via API)
        if (!$subscriptionModel && $externalReference) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $externalReference)
                ->first();
        }

        // Strategy 4: Find by vendor_plan_id (preapproval_plan_id)
        // Note: external_reference is NOT inherited from plan to subscription when user
        // subscribes via init_point, so we need to match by plan ID
        if (!$subscriptionModel && $preapprovalPlanId) {
            $subscriptionModel = Subscription::query()
                ->where('vendor_plan_id', $preapprovalPlanId)
                ->where('current_payment_method', 'mercado_pago')
                ->whereNull('vendor_subscription_id') // Not yet linked
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$subscriptionModel) {
            $this->sendResponse(200, 'No subscription found for the order, skipping subscription update.');
        }

        $oldStatus = $subscriptionModel->status;
        $mercadoPagoStatus = Arr::get($mercadoPagoSubscription, 'status');
        $status = MercadoPagoHelper::getFctSubscriptionStatus($mercadoPagoStatus);

        $updateData = [
            'vendor_subscription_id' => Arr::get($mercadoPagoSubscription, 'id'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($mercadoPagoSubscription, 'payer_id'),
            'current_payment_method' => 'mercado_pago',
        ];

        // Update next billing date if available
        $nextPaymentDate = Arr::get($mercadoPagoSubscription, 'next_payment_date');
        if ($nextPaymentDate) {
            $updateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

        $subscriptionModel->update($updateData);

        // Store payment method info if available
        $cardId = Arr::get($mercadoPagoSubscription, 'card_id');
        $payerId = Arr::get($mercadoPagoSubscription, 'payer_id');
        
        if ($cardId && $payerId) {
            // Fetch card details from Mercado Pago
            $card = MercadoPagoAPI::getMercadoPagoObject('v1/customers/' . $payerId . '/cards/' . $cardId);
            
            if (!is_wp_error($card) && Arr::get($card, 'id')) {
                $billingInfo = [
                    'type'             => 'card',
                    'last4'            => Arr::get($card, 'last_four_digits'),
                    'brand'            => Arr::get($card, 'payment_method.name'),
                    'payment_type_id'  => Arr::get($card, 'payment_method.payment_type_id'),
                    'expiration_month' => Arr::get($card, 'expiration_month'),
                    'expiration_year'  => Arr::get($card, 'expiration_year'),
                    'card_id'          => $cardId,
                ];
                $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
            } else {
                $billingInfo = [
                    'type'    => 'card',
                    'card_id' => $cardId,
                ];
                $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
            }
        }

        fluent_cart_add_log(__('Mercado Pago Subscription Updated', 'mercado-pago-for-fluent-cart'), 'Subscription status changed to ' . $mercadoPagoStatus . '. Mercado Pago Subscription ID: ' . Arr::get($mercadoPagoSubscription, 'id'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        // If this is the first activation (subscription was pending and now authorized)
        // We need to confirm the initial transaction and sync the order
        if ($oldStatus !== $status && in_array($status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            // Update the initial transaction if it's still pending
            $initialTransaction = OrderTransaction::query()
                ->where('order_id', $order->id)
                ->where('subscription_id', $subscriptionModel->id)
                ->where('status', Status::TRANSACTION_PENDING)
                ->first();

            if ($initialTransaction) {
                // Try to find the first payment/invoice for this subscription
                $this->syncInitialSubscriptionPayment($subscriptionModel, $initialTransaction, $mercadoPagoSubscription);
            }

            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

        $this->sendResponse(200, 'Subscription updated successfully');
    }

    /**
     * Sync the initial subscription payment from Mercado Pago
     * 
     * Handles different scenarios:
     * 1. Trial subscription (no initial charge) - transaction.total may be 0 or just signup_fee
     * 2. No trial subscription - first payment should match recurring amount
     * 3. Signup fee - Note: Mercado Pago preapproval_plan doesn't support separate initial fees
     */
    private function syncInitialSubscriptionPayment($subscriptionModel, $transaction, $mercadoPagoSubscription)
    {
        $vendorSubscriptionId = Arr::get($mercadoPagoSubscription, 'id');
        $mercadoPagoStatus = Arr::get($mercadoPagoSubscription, 'status');
        $hasTrial = $subscriptionModel->trial_days && $subscriptionModel->trial_days > 0;
        $signupFee = (int) $subscriptionModel->signup_fee;
        
        // Fetch authorized payments (invoices) for this subscription
        $authorizedPayments = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $vendorSubscriptionId . '/transactions', [
            'offset' => 0,
            'limit' => 1
        ]);

        $payments = [];
        if (!is_wp_error($authorizedPayments)) {
            $payments = Arr::get($authorizedPayments, 'data', []);
        }

        // Determine how to handle the transaction based on scenario
        if (!empty($payments)) {
            // Payment exists - use the actual payment data
            $firstPayment = $payments[0];
            $paymentId = Arr::get($firstPayment, 'id');
            $paymentStatus = Arr::get($firstPayment, 'status');
            $paymentAmount = Arr::get($firstPayment, 'amount', 0);
            
            if (in_array($paymentStatus, ['approved', 'processed'])) {
                $updateData = [
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'vendor_charge_id' => $paymentId,
                    'meta'             => array_merge($transaction->meta ?? [], [
                        'mercado_pago_authorized_payment' => $firstPayment,
                        'mercado_pago_amount_charged'     => $paymentAmount,
                    ])
                ];
                
                // If the charged amount differs from transaction total (e.g., signup fee not charged)
                // Log this for transparency but still mark as succeeded
                if ($transaction->total > 0 && abs(\FluentCart\App\Helpers\Helper::toCent($paymentAmount) - $transaction->total) > 100) {
                    $updateData['meta']['amount_discrepancy_note'] = sprintf(
                        'Expected: %s, Charged by Mercado Pago: %s. Note: Signup fees may not be collected via preapproval_plan.',
                        $transaction->total / 100,
                        $paymentAmount
                    );
                }
                
                $transaction->update($updateData);
            }
        } elseif ($hasTrial && $mercadoPagoStatus === 'authorized') {
            // Trial subscription - no payment expected yet
            // Mark transaction as succeeded if it's a $0 transaction or has only signup fee
            // Note: Mercado Pago preapproval_plan doesn't charge signup fees separately
            $updateData = [
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id' => $vendorSubscriptionId . '_trial_authorized',
                'meta'             => array_merge($transaction->meta ?? [], [
                    'trial_authorization' => true,
                    'mercado_pago_status' => $mercadoPagoStatus,
                ])
            ];
            
            // If there's a signup fee that won't be collected, note this
            if ($signupFee > 0) {
                $updateData['meta']['signup_fee_note'] = sprintf(
                    'Signup fee of %s was not collected. Mercado Pago preapproval_plan does not support initial fees.',
                    $signupFee / 100
                );
                
                // Adjust transaction total to 0 since nothing was actually charged
                $updateData['total'] = 0;
                
                fluent_cart_add_log(
                    __('Mercado Pago Signup Fee Not Collected', 'mercado-pago-for-fluent-cart'),
                    sprintf('Subscription %s has a signup fee of %s that could not be collected via Mercado Pago preapproval_plan.', $subscriptionModel->uuid, $signupFee / 100),
                    'warning',
                    ['module_name' => 'subscription', 'module_id' => $subscriptionModel->id]
                );
            }
            
            $transaction->update($updateData);
        } elseif ($mercadoPagoStatus === 'authorized') {
            // Non-trial subscription authorized but no payment found yet
            // This shouldn't happen normally - log and mark as succeeded
            $transaction->update([
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id' => $vendorSubscriptionId . '_initial',
                'meta'             => array_merge($transaction->meta ?? [], [
                    'mercado_pago_status'  => $mercadoPagoStatus,
                    'no_payment_found'     => true,
                ])
            ]);
        } else {
            // Subscription not yet authorized - don't mark as succeeded
            return;
        }

        (new \FluentCart\App\Helpers\StatusHelper($transaction->order))->syncOrderStatuses($transaction);
    }

    public function processSubscriptionPayment($data)
    {
        // Handles recurring subscription payment received (subscription_authorized_payment webhook)
        $authorizedPayment = Arr::get($data, 'resource');
        $subscriptionId = Arr::get($authorizedPayment, 'preapproval_id');
        $externalReference = Arr::get($authorizedPayment, 'external_reference');
        $paymentId = Arr::get($authorizedPayment, 'id');
        $paymentStatus = Arr::get($authorizedPayment, 'status');

        if (!$subscriptionId) {
            $this->sendResponse(404, 'No subscription ID found in payment');
        }

        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $subscriptionId)
            ->first();
        
        if (!$subscriptionModel) {
            if ($externalReference) {
                $subscriptionModel = Subscription::query()
                        ->where('uuid', $externalReference)
                        ->first();
            }
        }

        if (!$subscriptionModel) {
            $this->sendResponse(404, 'Subscription not found');
        }

        // Only process approved/processed payments
        if (!in_array($paymentStatus, ['approved', 'processed'])) {
            fluent_cart_add_log(__('Mercado Pago Subscription Payment Skipped', 'mercado-pago-for-fluent-cart'), 'Payment status is ' . $paymentStatus . ', skipping. Payment ID: ' . $paymentId, 'info', [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id
            ]);
            $this->sendResponse(200, 'Payment status not approved, skipping');
        }

        // Check if this payment has already been recorded
        $existingTransaction = OrderTransaction::query()
            ->where('vendor_charge_id', $paymentId)
            ->first();

        if ($existingTransaction) {
            $this->sendResponse(200, 'Payment already recorded');
        }

        // Get the parent order for creating renewal
        $parentOrder = $subscriptionModel->order;
        
        if (!$parentOrder) {
            $this->sendResponse(404, 'Parent order not found for subscription');
        }

        // Check for existing transactions
        // Note: For trial subscriptions, initial transaction might be marked as succeeded with $0 and 
        // vendor_charge_id like "{subscription_id}_trial_authorized"
        
        // Check if there's a pending initial transaction
        $initialTransaction = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('status', Status::TRANSACTION_PENDING)
            ->first();

        if ($initialTransaction) {
            // Update the pending initial transaction with actual payment
            $amount = Arr::get($authorizedPayment, 'amount', 0);
            $initialTransaction->update([
                'status'           => Status::TRANSACTION_SUCCEEDED,
                'vendor_charge_id' => $paymentId,
                'total'            => \FluentCart\App\Helpers\Helper::toCent($amount),
                'meta'             => array_merge($initialTransaction->meta ?? [], [
                    'mercado_pago_authorized_payment' => $authorizedPayment
                ])
            ]);

            // Update subscription status if needed
            $subscriptionModel->update([
                'status'     => Status::SUBSCRIPTION_ACTIVE,
                'bill_count' => 1,
            ]);

            (new \FluentCart\App\Helpers\StatusHelper($initialTransaction->order))->syncOrderStatuses($initialTransaction);
            
            fluent_cart_add_log(__('Mercado Pago Initial Subscription Payment', 'mercado-pago-for-fluent-cart'), 'Initial payment confirmed. Payment ID: ' . $paymentId, 'info', [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id
            ]);

            $this->sendResponse(200, 'Initial subscription payment confirmed');
        }

        // Check if this is the first actual payment after a trial (trial was marked as $0 succeeded)
        $trialAuthTransaction = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->where('vendor_charge_id', 'LIKE', '%_trial_authorized')
            ->first();

        if ($trialAuthTransaction) {
            // This is the first real payment after trial - update the trial transaction
            $amount = Arr::get($authorizedPayment, 'amount', 0);
            $trialAuthTransaction->update([
                'vendor_charge_id' => $paymentId,
                'total'            => \FluentCart\App\Helpers\Helper::toCent($amount),
                'meta'             => array_merge($trialAuthTransaction->meta ?? [], [
                    'mercado_pago_authorized_payment' => $authorizedPayment,
                    'post_trial_first_payment'        => true,
                ])
            ]);

            // Update subscription 
            $subscriptionModel->update([
                'status'     => Status::SUBSCRIPTION_ACTIVE,
                'bill_count' => 1,
            ]);

            (new \FluentCart\App\Helpers\StatusHelper($trialAuthTransaction->order))->syncOrderStatuses($trialAuthTransaction);
            
            fluent_cart_add_log(__('Mercado Pago Post-Trial First Payment', 'mercado-pago-for-fluent-cart'), 'First payment after trial confirmed. Payment ID: ' . $paymentId, 'info', [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id
            ]);

            $this->sendResponse(200, 'Post-trial first payment confirmed');
        }

        // Count actual successful payments (excluding trial authorizations)
        $existingPaymentsCount = OrderTransaction::query()
            ->where('subscription_id', $subscriptionModel->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->where('vendor_charge_id', 'NOT LIKE', '%_trial_authorized')
            ->where('vendor_charge_id', 'NOT LIKE', '%_initial')
            ->where('total', '>', 0)
            ->count();

        // This is a renewal payment - record it
        $amount = Arr::get($authorizedPayment, 'amount', 0);
        $currency = $subscriptionModel->currency ?? $parentOrder->currency;

        // Get subscription details for next billing date
        $mercadoPagoSubscription = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $subscriptionId);
        
        $subscriptionUpdateData = [];
        if (!is_wp_error($mercadoPagoSubscription)) {
            $subscriptionUpdateData = MercadoPagoHelper::getSubscriptionUpdateData($mercadoPagoSubscription, $subscriptionModel);
        }

        $transactionData = [
            'order_id'         => $parentOrder->id,
            'subscription_id'  => $subscriptionModel->id,
            'vendor_charge_id' => $paymentId,
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => \FluentCart\App\Helpers\Helper::toCent($amount),
            'currency'         => $currency,
            'meta'             => [
                'mercado_pago_authorized_payment' => $authorizedPayment,
            ],
        ];

        \FluentCart\App\Modules\Subscriptions\Services\SubscriptionService::recordRenewalPayment(
            $transactionData,
            $subscriptionModel,
            $subscriptionUpdateData
        );

        fluent_cart_add_log(__('Mercado Pago Subscription Renewal Payment', 'mercado-pago-for-fluent-cart'), 'Renewal payment recorded. Payment ID: ' . $paymentId . '. Amount: ' . $amount, 'info', [
            'module_name' => 'subscription',
            'module_id'   => $subscriptionModel->id
        ]);

        $this->sendResponse(200, 'Subscription renewal payment processed');
    }

    public function processPaymentRefunded($data)
    {
        $mercadoPagoPayment = Arr::get($data, 'resource');
        $order = Arr::get($data, 'order');
        
        $refunds = Arr::get($mercadoPagoPayment, 'refunds', []);
        
        if (empty($refunds)) {
            $this->sendResponse(404, 'No refunds found in payment data.');
        }
        
        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');

        $parentTransaction = OrderTransaction::query()
            ->where('uuid', $externalReference)
            ->where('payment_method', 'mercado_pago')
            ->first();
        
        if (!$parentTransaction) {
            $this->sendResponse(404, 'Parent transaction not found, skipping refund processing.');
        }
        
        $currentCreatedRefund = null;
        
        // Process each refund (Mercado Pago can have multiple refunds for one payment)
        foreach ($refunds as $refund) {
            $refundId = Arr::get($refund, 'id');
            $refundAmount = Arr::get($refund, 'amount'); // This is in decimal format from MercadoPago
            $refundStatus = Arr::get($refund, 'status');
            
            // Only process approved refunds
            if ($refundStatus !== 'approved') {
                continue;
            }
        
            $refundAmountInCents = \FluentCart\App\Helpers\Helper::toCent($refundAmount);
            
            // Prepare refund data matching Paystack pattern
            $refundData = [
                'order_id'           => $order->id,
                'order_type'         => $parentTransaction->order_type,
                'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
                'status'             => Status::TRANSACTION_REFUNDED,
                'payment_method'     => 'mercado_pago',
                'payment_method_type' => $parentTransaction->payment_method_type,
                'payment_mode'       => $parentTransaction->payment_mode,
                'vendor_charge_id'   => $refundId,
                'total'              => $refundAmountInCents,
                'currency'           => $parentTransaction->currency,
                'subscription_id'    => $parentTransaction->subscription_id,
                'meta'               => [
                    'parent_id'          => $parentTransaction->id,
                    'refund_description' => Arr::get($refund, 'metadata.reason', ''),
                    'refund_source'      => 'webhook',
                    'mercadopago_refund_id' => $refundId
                ]
            ];
            
            $syncedRefund = MercadoPagoRefund::createOrUpdateIpnRefund($refundData, $parentTransaction);
            
            if ($syncedRefund && $syncedRefund->wasRecentlyCreated) {
                $currentCreatedRefund = $syncedRefund;
            }
        }
        
        if ($currentCreatedRefund) {
            (new OrderRefund($order, $currentCreatedRefund))->dispatch();
            
            fluent_cart_add_log(__('Mercado Pago Refund Processed', 'mercado-pago-for-fluent-cart'), 'Refund received from Mercado Pago webhook for payment ID: ' . Arr::get($mercadoPagoPayment, 'id'), 'info', [
                'module_name' => 'order',
                'module_id'   => $order->id
            ]);
        }
        
        $this->sendResponse(200, 'Refund processed successfully');
    }

    public function getFluentCartOrder($data, $resource)
    {
        $order = null;
        $type = Arr::get($data, 'type');

        if (is_wp_error($resource)) {
            return [];
        }
        
        if (in_array($type, ['payment', 'orders'])) {
            $externalReference = Arr::get($resource, 'external_reference');
            
            if ($externalReference) {
                $transaction = OrderTransaction::query()
                    ->where('uuid', $externalReference)
                    ->where('payment_method', 'mercado_pago')
                    ->first();
                
                if ($transaction) {
                    $order = Order::query()->where('id', $transaction->order_id)->first();
                }
            }
        }

        if (!$order && in_array($type, ['subscription.preapproval', 'subscription_preapproval'])) {
            $vendorSubscriptionId = Arr::get($resource, 'id');
            $preapprovalPlanId = Arr::get($resource, 'preapproval_plan_id');
            $payerEmail = Arr::get($resource, 'payer_email');
            
            // Strategy 1: Find by vendor_subscription_id (if already stored)
            if ($vendorSubscriptionId) {
                $subscription = Subscription::query()
                    ->where('vendor_subscription_id', $vendorSubscriptionId)
                    ->first();
                
                if ($subscription) {
                    $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                }
            }
            
            // Strategy 2: Find by external_reference (if subscription was created with it)
            if (!$order) {
                $externalReference = Arr::get($resource, 'external_reference');
                if ($externalReference) {
                    $subscription = Subscription::query()
                        ->where('uuid', $externalReference)
                        ->first();
                    
                    if ($subscription) {
                        $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                    }
                }
            }
            
            // Strategy 3: Find by preapproval_plan_id (vendor_plan_id) + payer_email
            // This is needed because external_reference is NOT inherited from plan to subscription
            if (!$order && $preapprovalPlanId && $payerEmail) {
                $subscription = Subscription::query()
                    ->where('vendor_plan_id', $preapprovalPlanId)
                    ->where('current_payment_method', 'mercado_pago')
                    ->whereHas('order', function($query) use ($payerEmail) {
                        $query->whereHas('customer', function($q) use ($payerEmail) {
                            $q->where('email', $payerEmail);
                        });
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($subscription) {
                    $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                }
            }

            // Strategy 4: Find by preapproval_plan_id only (if there's only one subscription for this plan)
            if (!$order && $preapprovalPlanId) {
                $subscriptions = Subscription::query()
                    ->where('vendor_plan_id', $preapprovalPlanId)
                    ->where('current_payment_method', 'mercado_pago')
                    ->get();
                
                if ($subscriptions->count() === 1) {
                    $subscription = $subscriptions->first();
                    $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                }
            }
        }

        if (!$order && in_array($type, ['subscription.authorized_payment', 'subscription_authorized_payment'])) {
            $preapprovalId = Arr::get($resource, 'preapproval_id');
            
            if ($preapprovalId) {
                $subscription = Subscription::query()
                    ->where('vendor_subscription_id', $preapprovalId)
                    ->first();
                
                if ($subscription) {
                    $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                }
            }
        }

        return $order;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
        ]);

        exit;
    }
}
