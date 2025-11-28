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
        add_action('fluent_cart/payments/mercado_pago/webhook_payment_updated', [$this, 'handlePaymentUpdate'], 10, 1);
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_preapproval', [$this, 'handleSubscriptionUpdate'], 10, 1);
        add_action('fluent_cart/payments/mercado_pago/webhook_subscription_authorized_payment', [$this, 'handleSubscriptionPayment'], 10, 1);
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

    
    private function fetchResource($type, $resourceId)
    {
        $endpoint = '';
        
        switch ($type) {
            case 'payment':
                $endpoint = 'v1/payments/' . $resourceId;
                break;
            case 'subscription.preapproval':
            case 'subscription_preapproval':
                $endpoint = 'preapproval/' . $resourceId;
                break;
            case 'subscription.authorized_payment':
            case 'subscription_authorized_payment':
                $endpoint = 'authorized_payments/' . $resourceId;
                break;
            default:
                return new \WP_Error('unknown_type', 'Unknown webhook type');
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
                'payment_note' => 'Payment ' . $paymentStatus . ' via webhook',
            ]);

            do_action('fluent_cart/payment_failed', $transactionModel->order, $transactionModel);
            
            $this->sendResponse(200, 'Payment failure recorded');
        }

        $this->sendResponse(200, 'Payment status updated');
    }

    
    public function handleSubscriptionUpdate($data)
    {
        $mercadoPagoSubscription = Arr::get($data, 'payload');
        
        $order = Arr::get($data, 'order');

        $subscriptionModel = Subscription::query()
            ->where('parent_order_id', $order->id)
            ->first();

        
        if (!$subscriptionModel) {
            $this->sendResponse(200, 'No subscription found for the order, skipping subscription update.');
        }

        $status = MercadoPagoHelper::getFctSubscriptionStatus(Arr::get($mercadoPagoSubscription, 'status'));

        $updateData = [
            'vendor_subscription_id' => Arr::get($mercadoPagoSubscription, 'id'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($mercadoPagoSubscription, 'payer_id'),
            'next_billing_date'      => Arr::get($mercadoPagoSubscription, 'next_payment_date') ? 
                DateTime::anyTimeToGmt(Arr::get($mercadoPagoSubscription, 'next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];

        $subscriptionModel->update($updateData);

        fluent_cart_add_log(__('Mercado Pago Subscription Updated', 'mercado-pago-for-fluent-cart'), 'Subscription updated from Mercado Pago. ID: ' . Arr::get($mercadoPagoSubscription, 'id'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        $this->sendResponse(200, 'Subscription updated successfully');
    }

    
    public function handleSubscriptionPayment($data)
    {
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

        // Resync subscription from remote to handle renewal
        $subscriptionModel->reSyncFromRemote();

        $this->sendResponse(200, 'Subscription payment processed');
    }

    public function getFluentCartOrder($resource, $type)
    {
        $order = null;

        // Try to get external reference from payment
        if ($type === 'payment') {
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

        // Try to get from subscription
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

