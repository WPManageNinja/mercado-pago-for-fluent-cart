<?php

namespace MercadoPagoFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\DateTime\DateTime;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;
use MercadoPagoFluentCart\Confirmations\MercadoPagoConfirmations;
use MercadoPagoFluentCart\MercadoPagoHelper;
use MercadoPagoFluentCart\Subscriptions\MercadoPagoSubscriptions;
use MercadoPagoFluentCart\Refund\MercadoPagoRefund;
use MercadoPagoFluentCart\API\MercadoPagoAPI;

class MercadoPagoWebhook
{
    public function init()
    {
        // Payment webhooks (type: payment) - for one-time payment confirmation
        add_action('fluent_cart/payments/mercado_pago/webhook_payment', [$this, 'handlePaymentUpdate'], 10, 1);
        
        // Orders webhooks (type: orders) - for one-time payment confirmation (Checkout Transparente, Point, QR Code)
        add_action('fluent_cart/payments/mercado_pago/webhook_orders', [$this, 'handlePaymentUpdate'], 10, 1);
        
        // Subscription webhooks
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_preapproval', [$this, 'handleSubscriptionUpdate'], 10, 1); // Create/cancel subscription
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_authorized_payment', [$this, 'handleSubscriptionPayment'], 10, 1); // Recurring payment received
        
        // Refund webhooks (manually triggered from handlePaymentUpdate when refunds detected)
        add_action('fluent_cart/payments/mercado_pago/webhook_payment_refunded', [$this, 'handleRefundProcessed'], 10, 1);
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

        // Verify webhook signature (x-signature header)
        if (!$this->verifySignature($data)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        // Mercado Pago sends notification type and ID
        $type = Arr::get($data, 'type');
        $resourceId = Arr::get($data, 'data.id');

        if (!$type || !$resourceId) {
            http_response_code(400);
            exit('Missing required webhook data');
        }

        // Fetch the actual resource from Mercado Pago
        $resource = $this->fetchResource($type, $resourceId);
        
        if (is_wp_error($resource)) {
            http_response_code(500);
            exit('Failed to fetch resource');
        }

        $order = $this->getFluentCartOrder($resource, $type);

        if (!$order) {
            http_response_code(404);
            exit('Order not found');
        }

        // Convert type to event name
        $event = str_replace('.', '_', $type);

        if (has_action('fluent_cart/payments/mercado_pago/webhook_' . $event)) {
            do_action('fluent_cart/payments/mercado_pago/webhook_' . $event, [
                'payload' => $resource,
                'order'   => $order
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
    private function verifySignature($data)
    {
        // Get x-signature and x-request-id headers
        $xSignature = isset($_SERVER['HTTP_X_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SIGNATURE'])) : '';
        $xRequestId = isset($_SERVER['HTTP_X_REQUEST_ID']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REQUEST_ID'])) : '';

        if (empty($xSignature)) {
            fluent_cart_add_log('Mercado Pago Webhook Verification', 'Missing x-signature header', 'warning', ['log_type' => 'webhook']);
            return false;
        }

        // Extract data.id from query params (it comes as a query parameter in the webhook URL)
        $dataId = isset($_GET['data.id']) ? sanitize_text_field(wp_unslash($_GET['data.id'])) : Arr::get($data, 'data.id', '');
        
        // If data.id is alphanumeric, convert to lowercase as per Mercado Pago docs
        if (!empty($dataId) && ctype_alnum($dataId)) {
            $dataId = strtolower($dataId);
        }

        // Parse x-signature to extract ts and v1
        $parts = explode(',', $xSignature);
        $ts = null;
        $hash = null;

        foreach ($parts as $part) {
            $keyValue = explode('=', $part, 2);
            if (count($keyValue) == 2) {
                $key = trim($keyValue[0]);
                $value = trim($keyValue[1]);
                
                if ($key === 'ts') {
                    $ts = $value;
                } elseif ($key === 'v1') {
                    $hash = $value;
                }
            }
        }

        if (empty($ts) || empty($hash)) {
            fluent_cart_add_log('Mercado Pago Webhook Verification', 'Invalid x-signature format', 'warning', ['log_type' => 'webhook']);
            return false;
        }

        // Get secret key from settings
        $secret = $this->getSecretKey();
        
        if (empty($secret)) {
            fluent_cart_add_log('Mercado Pago Webhook Verification', 'Secret key not configured', 'error', ['log_type' => 'webhook']);
            return false;
        }

        // Build manifest string according to Mercado Pago template
        // Template: id:[data.id_url];request-id:[x-request-id_header];ts:[ts_header];
        $manifestParts = [];
        
        if (!empty($dataId)) {
            $manifestParts[] = "id:{$dataId}";
        }
        
        if (!empty($xRequestId)) {
            $manifestParts[] = "request-id:{$xRequestId}";
        }
        
        if (!empty($ts)) {
            $manifestParts[] = "ts:{$ts}";
        }
        
        $manifest = implode(';', $manifestParts) . ';';

        // Calculate HMAC-SHA256
        $calculatedHash = hash_hmac('sha256', $manifest, $secret);

        // Compare hashes
        if (hash_equals($calculatedHash, $hash)) {
            fluent_cart_add_log('Mercado Pago Webhook Verification', 'Signature verified successfully', 'info', ['log_type' => 'webhook']);
            return true;
        }

        fluent_cart_add_log('Mercado Pago Webhook Verification', 'Signature verification failed', 'warning', [
            'log_type' => 'webhook',
            'manifest' => $manifest,
            'expected_hash' => $hash,
            'calculated_hash' => $calculatedHash
        ]);

        return false;
    }

    /**
     * Get secret key for webhook signature verification
     */
    private function getSecretKey()
    {
        $settings = new MercadoPagoSettingsBase();
        $paymentMode = $settings->get('payment_mode');
        
        if (empty($paymentMode)) {
            $paymentMode = 'test';
        }
        
        // Get the encrypted secret from settings
        $encryptedSecret = $settings->get($paymentMode . '_webhook_secret');
        
        if (empty($encryptedSecret)) {
            return '';
        }
        
        // Decrypt the secret key
        try {
            $secretKey = \FluentCart\App\Helpers\Helper::decryptKey($encryptedSecret);
            return $secretKey;
        } catch (\Exception $e) {
            fluent_cart_add_log('Mercado Pago Webhook', 'Failed to decrypt webhook secret: ' . $e->getMessage(), 'error', ['log_type' => 'webhook']);
            return '';
        }
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


    public function handlePaymentUpdate($data)
    {
       $mercadoPagoPayment = Arr::get($data, 'payload');
       $mercadoPagoPaymentId = Arr::get($mercadoPagoPayment, 'id');
       $paymentStatus = Arr::get($mercadoPagoPayment, 'status');

       $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');

        // Find the transaction by UUID
        $transactionModel = null;

        if ($externalReference) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $externalReference)
                ->where('payment_method', 'mercado_pago')
                ->first();
        }

        if (!$transactionModel) {
            $this->sendResponse(404, 'Transaction not found for the provided reference.');
        }

        // Check if payment has refunds and trigger refund handling
        $refunds = Arr::get($mercadoPagoPayment, 'refunds', []);
        if (!empty($refunds)) {
            // Trigger refund processing
            do_action('fluent_cart/payments/mercado_pago/webhook_payment_refunded', $data);
            return;
        }

        // Check if already processed
        if ($transactionModel->status == Status::TRANSACTION_SUCCEEDED && $paymentStatus === 'approved') {
            $this->sendResponse(200, 'Payment already confirmed.');
        }

        // Handle different payment statuses
        if ($paymentStatus === 'approved') {
            $billingInfo = [
                'type'                => Arr::get($mercadoPagoPayment, 'payment_type_id', 'card'),
                'last4'               => Arr::get($mercadoPagoPayment, 'card.last_four_digits'),
                'brand'               => Arr::get($mercadoPagoPayment, 'card.first_six_digits'),
                'payment_method_id'   => Arr::get($mercadoPagoPayment, 'payment_method_id'),
                'payment_method_type' => Arr::get($mercadoPagoPayment, 'payment_type_id'),
            ];

            (new MercadoPagoConfirmations())->confirmPaymentSuccessByCharge($transactionModel, [
                'vendor_charge_id' => $mercadoPagoPaymentId,
                'charge'           => $mercadoPagoPayment,
                'billing_info'     => $billingInfo
            ]);

            $this->sendResponse(200, 'Payment confirmed successfully');
        } elseif (in_array($paymentStatus, ['rejected', 'cancelled'])) {
            $transactionModel->update([
                'status'       => Status::TRANSACTION_FAILED,
                'meta'         => array_merge($transactionModel->meta ?? [], [
                    'mercado_pago_response' => $mercadoPagoPayment
                ])
            ]);

            do_action('fluent_cart/payment_failed', $transactionModel->order, $transactionModel);
            
            $this->sendResponse(200, 'Payment failure recorded');
        }

        $this->sendResponse(200, 'Payment status updated');
    }

    
    public function handleSubscriptionUpdate($data)
    {
        // Handles subscription creation, cancellation, and pause
        $mercadoPagoSubscription = Arr::get($data, 'payload');
        
        $order = Arr::get($data, 'order');

        $subscriptionModel = Subscription::query()
            ->where('parent_order_id', $order->id)
            ->first();

        
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
            'next_billing_date'      => Arr::get($mercadoPagoSubscription, 'next_payment_date') ? 
                DateTime::anyTimeToGmt(Arr::get($mercadoPagoSubscription, 'next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];

        $subscriptionModel->update($updateData);

        // Store payment method info if available
        $cardInfo = Arr::get($mercadoPagoSubscription, 'card_id');
        if ($cardInfo) {
            $billingInfo = [
                'type'              => 'card',
                'payment_method_id' => $cardInfo,
            ];
            $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        }

        fluent_cart_add_log(__('Mercado Pago Subscription Updated', 'mercado-pago-for-fluent-cart'), 'Subscription status changed to ' . $mercadoPagoStatus . '. ID: ' . Arr::get($mercadoPagoSubscription, 'id'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        // Trigger subscription activated event if status changed to active
        if ($oldStatus != $status && in_array($status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

        $this->sendResponse(200, 'Subscription updated successfully');
    }

    
    public function handleSubscriptionPayment($data)
    {
        // Handles recurring subscription payment received
        $payment = Arr::get($data, 'payload');
        $subscriptionId = Arr::get($payment, 'preapproval_id');

        if (!$subscriptionId) {
            $this->sendResponse(400, 'No subscription ID found in payment');
        }

        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $subscriptionId)
            ->first();

        if (!$subscriptionModel) {
            $this->sendResponse(404, 'Subscription not found');
        }

        // Resync subscription from remote to handle renewal payment
        $subscriptionModel->reSyncFromRemote();

        $this->sendResponse(200, 'Subscription payment processed');
    }

    public function handleRefundProcessed($data)
    {
        $mercadoPagoPayment = Arr::get($data, 'payload');
        $order = Arr::get($data, 'order');
        
        // Get refunds from payment object
        $refunds = Arr::get($mercadoPagoPayment, 'refunds', []);
        
        if (empty($refunds)) {
            $this->sendResponse(200, 'No refunds found in payment data.');
        }
        
        $externalReference = Arr::get($mercadoPagoPayment, 'external_reference', '');
        
        // Find the parent transaction by UUID
        $parentTransaction = OrderTransaction::query()
            ->where('uuid', $externalReference)
            ->where('payment_method', 'mercado_pago')
            ->first();
        
        if (!$parentTransaction) {
            $this->sendResponse(200, 'Parent transaction not found, skipping refund processing.');
        }
        
        $currentCreatedRefund = null;
        
        // Process each refund (Mercado Pago can have multiple refunds for one payment)
        foreach ($refunds as $refund) {
            $refundId = Arr::get($refund, 'id');
            $refundAmount = Arr::get($refund, 'amount');
            $refundStatus = Arr::get($refund, 'status');
            
            // Only process approved refunds
            if ($refundStatus !== 'approved') {
                continue;
            }
            
            // Prepare refund data matching Paystack pattern
            $refundData = [
                'order_id'           => $order->id,
                'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
                'status'             => Status::TRANSACTION_REFUNDED,
                'payment_method'     => 'mercado_pago',
                'payment_mode'       => $parentTransaction->payment_mode,
                'vendor_charge_id'   => $refundId,
                'total'              => $refundAmount,
                'currency'           => $parentTransaction->currency,
                'meta'               => [
                    'parent_id'          => $parentTransaction->id,
                    'refund_description' => Arr::get($refund, 'metadata.reason', ''),
                    'refund_source'      => 'webhook'
                ]
            ];
            
            $syncedRefund = (new MercadoPagoRefund())->createOrUpdateIpnRefund($refundData, $parentTransaction);
            
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

    public function getFluentCartOrder($resource, $type)
    {
        $order = null;

        // Try to get external reference from payment or orders (one-time payments)
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

        // Try to get from subscription (create/cancel subscription)
        if (!$order && in_array($type, ['subscription.preapproval', 'subscription_preapproval'])) {
            $subscriptionId = Arr::get($resource, 'id');
            
            if ($subscriptionId) {
                $subscription = Subscription::query()
                    ->where('vendor_subscription_id', $subscriptionId)
                    ->first();
                
                if ($subscription) {
                    $order = Order::query()->where('id', $subscription->parent_order_id)->first();
                }
            }
        }

        // Try to get from subscription authorized payment (recurring payment received)
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

