<?php

namespace MercadoPagoFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
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

        // Get or create preapproval plan
        $plan = $this->getOrCreatePreapprovalPlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'id'),
        ]);

        $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);

        $paymentData = [
            'preapproval_plan_id' => Arr::get($plan, 'id'),
            'reason'              => sprintf(__('Subscription for Order #%s', 'mercado-pago-for-fluent-cart'), $order->id),
            'external_reference'  => $transaction->uuid,
            'payer_email'         => $fcCustomer->email,
            'back_url'            => $args['success_url'] ?? '',
            'status'              => 'pending',
            'metadata'            => [
                'order_id'          => $order->id,
                'order_hash'        => $order->uuid,
                'transaction_hash'  => $transaction->uuid,
                'subscription_hash' => $subscription->uuid,
                'customer_name'     => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];


        $paymentData = apply_filters('fluent_cart/mercadopago/subscription_payment_args', $paymentData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscription
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'mercado_pago',
            'actionName'   => 'custom',
            'message'      => __('Please complete your subscription payment', 'mercado-pago-for-fluent-cart'),
            'data'         => [
                'payment_data'      => $paymentData,
                'intent'            => 'subscription',
                'transaction_hash'  => $transaction->uuid,
                'amount'            => $amount,
                'currency'          => $transaction->currency,
                'plan_id'           => Arr::get($plan, 'id'),
            ]
        ];
    }

    public function getOrCreatePreapprovalPlan(PaymentInstance $paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;


        $existingPlanId = $subscription->vendor_plan_id;
        
        if ($existingPlanId) {
            $plan = MercadoPagoAPI::getMercadoPagoObject('preapproval_plan/' . $existingPlanId);
            
            if (!is_wp_error($plan) && Arr::get($plan, 'id')) {
                return $plan;
            }
        }

        // Create new plan
        $amount = MercadoPagoHelper::formatAmount($subscription->recurring_amount, $transaction->currency);
        
        // Map FluentCart interval to Mercado Pago frequency
        $frequency = $this->mapInterval($subscription->billing_interval);
        $frequencyType = $this->mapIntervalType($subscription->billing_interval);

        $billingPeriod = apply_filters('fluent_cart/mercadopago/subscription_billing_period', [
            'interval_unit' => $this->mapIntervalType($subscription->billing_interval),
            'interval_frequency' => $this->mapInterval($subscription->billing_interval),
        ], [
            'subscription_interval' => $subscription->billing_interval,
            'payment_method' => 'mercado_pago',
        ]);

        $planData = [
            'reason'              => $this->getPlanName($subscription, $order),
            'auto_recurring'      => [
                'frequency'           => Arr::get($billingPeriod, 'interval_frequency'),
                'frequency_type'      => Arr::get($billingPeriod, 'interval_unit'),
                'transaction_amount'  => $amount,
                'currency_id'         => strtoupper($transaction->currency),
            ],
            'back_url'            => $transaction->getSuccessUrl(),
            'external_reference'  => $subscription->uuid,
        ];


        if ($subscription->trial_days && $subscription->trial_days > 0) {
            $planData['auto_recurring']['free_trial'] = [
                'frequency'      => (int) $subscription->trial_days,
                'frequency_type' => 'days',
            ];
        }

        $planData = apply_filters('fluent_cart/mercadopago/preapproval_plan_args', $planData, [
            'subscription' => $subscription,
            'order'        => $order
        ]);


        $plan = MercadoPagoAPI::createMercadoPagoObject('preapproval_plan', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        return $plan;
    }

 
    private function getPlanName($subscription, $order)
    {
        $items = $subscription->subscription_items;
        
        if (!empty($items)) {
            $firstItem = $items[0];
            return $firstItem['post_title'] ?? sprintf(__('Subscription Plan #%s', 'mercado-pago-for-fluent-cart'), $subscription->id);
        }

        return sprintf(__('Subscription Plan #%s', 'mercado-pago-for-fluent-cart'), $subscription->id);
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
            $transactions = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $subscriptionModel->vendor_subscription_id . '/transactions', []);
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
                     $transactionModel = OrderTransaction::query()->where('vendor_charge_id', '')->where('status', Status::TRANSACTION_PENDING)->first();

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
        // This returns the frequency count, which is already in billing_interval_count
        return 1;
    }

    private function mapIntervalType($fluentcartInterval)
    {
        $intervalMap = [
            'day'   => 'days',
            'week'  => 'months', // Mercado Pago doesn't have weeks, use months
            'month' => 'months',
            'year'  => 'years'
        ];

        return $intervalMap[$fluentcartInterval] ?? 'months';
    }
}

