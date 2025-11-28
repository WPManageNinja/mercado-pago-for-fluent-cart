<?php

namespace MercadoPagoFluentCart\Subscriptions;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Services\Payments\PaymentInstance;
use MercadoPagoFluentCart\API\MercadoPagoAPI;
use MercadoPagoFluentCart\MercadoPagoHelper;
use FluentCart\Framework\Support\Arr;

class MercadoPagoSubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription(PaymentInstance $paymentInstance, $args = [])
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Note: Mercado Pago subscriptions require creating a preapproval plan first
        // For now, we'll return a simple implementation
        // Full subscription support can be added later

        return new \WP_Error(
            'subscription_not_implemented',
            __('Mercado Pago subscription support is coming soon. Please use one-time payments for now.', 'mercado-pago-for-fluent-cart')
        );

        // TODO: Implement full subscription logic
        // 1. Create preapproval plan
        // 2. Create subscription with plan
        // 3. Handle subscription authorization
        // 4. Store subscription details
    }

    public function cancelSubscription($subscription, $args = [])
    {
        $vendorSubscriptionId = $subscription->vendor_subscription_id;
        
        if (empty($vendorSubscriptionId)) {
            return new \WP_Error('no_vendor_id', 'No vendor subscription ID found');
        }

        // Cancel preapproval
        $result = MercadoPagoAPI::updateMercadoPagoObject(
            'preapproval/' . $vendorSubscriptionId,
            ['status' => 'cancelled']
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'status' => 'success',
            'message' => __('Subscription cancelled successfully', 'mercado-pago-for-fluent-cart')
        ];
    }

    private function mapInterval($fluentcartInterval)
    {
        $intervalMap = [
            'day'   => 'days',
            'week'  => 'weeks',
            'month' => 'months',
            'year'  => 'years'
        ];

        return $intervalMap[$fluentcartInterval] ?? 'months';
    }
}

