<?php

namespace MercadoPagoFluentCart\Subscriptions;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
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

    public function cancel($subscription, $args = [])
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

    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        $subscription = $subscriptionModel->subscription;
        $order = $subscriptionModel->order;
        $transaction = $subscriptionModel->transaction;
        $fcCustomer = $subscriptionModel->order->customer;

        // Get subscription details from Mercado Pago
        $subscriptionDetails = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $subscriptionModel->vendor_subscription_id);

        $subscriptionTransactions = [];

        $hasMore = false;
        $next = null;

        do {
            $transactions = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $subscriptionModel->vendor_subscription_id . '/transactions');
            if (is_wp_error($transactions)) {
                break;
            }
            $subscriptionTransactions = [...$subscriptionTransactions, ...Arr::get($transactions, 'data', [])];

            $hasMore = Arr::get($transactions, 'meta.pagination.has_more');
            $next = Arr::get($transactions, 'meta.pagination.next');
            $queryString = parse_url($next, PHP_URL_QUERY);
            parse_str($queryString, $params);
            $after = $params['after'];
            $queryParams['after'] = $after;
        } while ($hasMore);

        $subscriptionUpdateData = MercadoPagoHelper::getSubscriptionUpdateData($subscriptionDetails, $subscriptionModel);

        $newPayment = false;

        if (!empty($subscriptionTransactions)) {
            $subscriptionTransactions = array_reverse($subscriptionTransactions);
            foreach ($subscriptionTransactions as $transaction) {
                $amount = Arr::get($transaction, 'amount');
                $vendorChargeId = Arr::get($transaction, 'id');
                $status = Arr::get($transaction, 'status');
                
                $transactionModel = null;
                $transactionModel = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();

                if ($transactionModel) {
                    continue;
                }

                if (!$transactionModel) {
                    $transactionModel = OrderTransaction::query()->where('vendor_charge_id', '')->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)->first();

                    if ($transactionModel) {
                        $transactionModel->update([
                            'vendor_charge_id' => $vendorChargeId,
                            'status' => Status::TRANSACTION_SUCCEEDED,
                        ]);
                        continue;
                    }

                    // record renewal payment
                    $transactionData = [
                        'order_id' => $order->id,
                        'subscription_id' => $subscriptionModel->id,
                        'vendor_charge_id' => $vendorChargeId,
                        'status' => Status::TRANSACTION_SUCCEEDED,
                        'total' => $amount,
                        'meta' => [
                            'mercado_pago_transaction' => Arr::get($transaction, 'transaction_details', []),
                        ],
                    ];
                    SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                    $newPayment = true;
                }

            }
        }

        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } 

        return $subscriptionModel;
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

