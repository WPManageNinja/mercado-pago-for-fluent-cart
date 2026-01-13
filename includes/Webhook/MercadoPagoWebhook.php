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
        add_action('fluent_cart/payments/mercado_pago/webhook_payment_approved', [$this, 'handlePaymentApproved'], 10, 1);
        
        // Orders webhooks (type: orders) - for one-time payment confirmation (Checkout Transparente, Point, QR Code)
        add_action('fluent_cart/payments/mercado_pago/webhook_orders_approved   ', [$this, 'handleOrdersApproved'], 10, 1);
        
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


        if (!$this->verifySignature($data)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        // Mercado Pago sends notification type and ID
        $action = Arr::get($data, 'action');
        $resourceId = Arr::get($data, 'data.id');

        if (!$action || !$resourceId) {
            http_response_code(400);
            exit('Missing required webhook data');
        }


        $order = $this->getFluentCartOrder($data, $action);

        if (!$order) {
            http_response_code(404);
            exit('Order not found');
        }

        // Convert type to event name
        $event = str_replace('.', '_', $action);

        if (has_action('fluent_cart/payments/mercado_pago/webhook_' . $event)) {
            do_action('fluent_cart/payments/mercado_pago/webhook_' . $event, [
                'payload' => $data,
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
    private function verifySignature($payload)
    {
        $headerSignature = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
        if (!$headerSignature) {
            return false;
        }

        // Parse signature, expecting format: ts=TIMESTAMP,v1=SIGNATURE
        $signatureParts = [];
        foreach (explode(',', $headerSignature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) == 2) {
                $signatureParts[$pair[0]] = $pair[1];
            }
        }
        if (empty($signatureParts['ts']) || empty($signatureParts['v1'])) {
            return false;
        }
        $signatureTimestamp = $signatureParts['ts'];
        $signatureHash      = $signatureParts['v1'];

        $secretKey = (new MercadoPagoSettingsBase())->getWebhookSecretKey('current');
        if (!$secretKey) {
            return false;
        }

        $computedSignature = hash_hmac('sha512', $payload, $secretKey);

        return hash_equals($signatureHash, $computedSignature);
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


    public function handlePaymentApproved($data)
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
            $refundAmount = Arr::get($refund, 'amount'); // This is in decimal format from MercadoPago
            $refundStatus = Arr::get($refund, 'status');
            
            // Only process approved refunds
            if ($refundStatus !== 'approved') {
                continue;
            }
            
            // Convert decimal amount to cents (FluentCart stores amounts in cents)
            // MercadoPago sends amounts in decimal format (e.g., 10.50)
            // FluentCart stores in cents (e.g., 1050)
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

    public function getFluentCartOrder($data, $action)
    {
        $order = null;
        $type = explode('.', $action)[0];
        $id = Arr::get($data, 'data.id');

        if ($type == 'payment') {
            $resource =  MercadoPagoAPI::getMercadoPagoObject('v1/payments/' . $id);
        } else if ($type == 'orders') {
            $resource =  MercadoPagoAPI::getMercadoPagoObject('v1/orders/' . $id);
        } else if ($type == 'subscription.preapproval') {
            $resource =  MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $id);
        } else if ($type == 'subscription.authorized_payment') {
            $resource =  MercadoPagoAPI::getMercadoPagoObject('authorized_payments/' . $id);
        } else {
            return null;
        }

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

