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
use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;

class MercadoPagoSubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $paymentInstance->order->billing_address;

        $mpFormData = Arr::get(App::request()->all(), 'mp_form_data', '');

        $mpFormData = json_decode($mpFormData, true) ?? [];

        $mpSelectedPaymentMethod = sanitize_text_field(Arr::get(App::request()->all(), 'mp_selected_payment_method', ''));

        $planData = [];

        $frequency = $this->mapIntervalFrequency($subscription->billing_interval);
        $frequencyType = $this->mapIntervalType($subscription->billing_interval);

        $billingPeriod = apply_filters('fluent_cart/mercadopago/subscription_billing_period', [
            'interval_unit' => $frequencyType,
            'interval_frequency' => $frequency,
        ], [
            'subscription_interval' => $subscription->billing_interval,
            'payment_method' => 'mercado_pago',
        ]);

        $autoRecurringData = [
            'frequency'           => Arr::get($billingPeriod, 'interval_frequency'),
            'frequency_type'      => Arr::get($billingPeriod, 'interval_unit'),
            'transaction_amount'  => MercadoPagoHelper::formatAmount($subscription->recurring_total, $transaction->currency),
            'currency_id'         => strtoupper($transaction->currency),
        ];

        $billTimes = $subscription->billing_times;
        if ($billTimes && $billTimes > 0) {
            $autoRecurringData['repetitions'] = $billTimes;
        }

        $billingDay = (new MercadoPagoSettingsBase())->getBillingDay();

        if ($billingDay && $billingDay > 0 && $billingDay <=28) {
            $autoRecurringData['billing_day'] = $billingDay;
        }

        // trialing data
        $trialDays = $subscription->trial_days;
        if ($trialDays && $trialDays > 0) {
            $autoRecurringData['free_trial'] = [
                'frequency'      => (int) $trialDays,
                'frequency_type' => 'days',
            ];
        }

        if ($order->type == 'renewal') {

            $requiredBillTimes = $subscription->getRequiredBillTimes();
            $trialDays = $subscription->getReactivationTrialDays();
            $recurringAmount = $subscription->getCurrentRenewalAmount();

            if ($requiredBillTimes === -1) {
                return new \WP_Error('already_completed', __('Invalid bill times for the subscription.', 'fluent-cart'));
            }

            if ($requiredBillTimes > 0) {
                $autoRecurringData['repetitions'] = $requiredBillTimes;
            }

            if ($trialDays > 0) {
                $autoRecurringData['free_trial'] = [
                    'frequency'      => (int) $trialDays,
                    'frequency_type' => 'days',
                ];
            }

            $autoRecurringData['transaction_amount'] = MercadoPagoHelper::formatAmount($recurringAmount, $transaction->currency);

            $planData = [
                'reason' => __('Reactivation of subscription ', 'mercado-pago-for-fluent-cart') . $this->getPlanName($order) ?: $subscription->item_name,
                'auto_recurring' => $autoRecurringData,
                'back_url' => $transaction->getReceiptPageUrl()
            ];

            $plan = $this->getOrCreatePreApprovalPlan($paymentInstance, $planData);
        } else {

            $planData = [
                'reason' => __('New subscription ', 'mercado-pago-for-fluent-cart') . $this->getPlanName($order) ?: $subscription->item_name,
                'auto_recurring' => $autoRecurringData,
                'back_url' => $transaction->getReceiptPageUrl(),
            ];

            $plan = $this->getOrCreatePreApprovalPlan($paymentInstance, $planData);
        }


        if (is_wp_error($plan)) {
            return $plan;
        }

        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'id'),
        ]);

        $init_point = Arr::get($plan, 'init_point');

        if ($init_point) {
            return [
                'status' => 'success',
                'nextAction' => 'mercado_pago',
                'actionName' => 'custom',
                'message' => __('Please complete your subscription payment', 'mercado-pago-for-fluent-cart'),
                'data' => [
                    'payment_data' => $plan,
                    'intent' => 'subscription',
                    'transaction_hash' => $transaction->uuid,
                    'redirect_url' => $init_point,
                ]
            ];
        }

        return [
            'status' => 'failed',
            'message' => __('Failed to create subscription plan', 'mercado-pago-for-fluent-cart'),
        ];

        // // end of the flow, as I am using the plan's init_point and redirecting

        // $mercadoPagoCustomer = $this->getOrCreateCustomer($fcCustomer, $mpFormData, $billingAddress);

        // if (is_wp_error($mercadoPagoCustomer)) {
        //     return $mercadoPagoCustomer;
        // }

        // // create card token if not exists
        // $cardToken = $this->getOrCreateCard($fcCustomer, $mercadoPagoCustomer, $mpFormData);

        // if (is_wp_error($cardToken)) {
        //     return $cardToken;
        // }

        // $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);

        // $paymentData = [
        //     'preapproval_plan_id' => Arr::get($plan, 'id'),
        //     'external_reference'  => $subscription->uuid,
        //     'payer_email'         => Arr::get($mpFormData, 'payer.email', $fcCustomer->email),
        //     'back_url'            => Arr::get($paymentArgs, 'success_url'),
        //     'card_token_id'       => $cardToken,
        //     'status'              => 'pending',
            
        // ];


        // $paymentData = apply_filters('fluent_cart/mercadopago/subscription_payment_args', $paymentData, [
        //     'order'        => $order,
        //     'transaction'  => $transaction,
        //     'subscription' => $subscription
        // ]);


        // // create subscription
        // $mpSubscription = MercadoPagoAPI::createMercadoPagoObject('preapproval', $paymentData);

        // if (is_wp_error($mpSubscription)) {
        //     return $mpSubscription;
        // }

        // $subscription->update([
        //     'vendor_subscription_id' => Arr::get($mpSubscription, 'id'),
        // ]);
        
        // return [
        //     'status'       => 'success',
        //     'nextAction'   => 'mercado_pago',
        //     'actionName'   => 'custom',
        //     'message'      => __('Please complete your subscription payment', 'mercado-pago-for-fluent-cart'),
        //     'data'         => [
        //         'payment_data'      => $paymentData,
        //         'intent'            => 'subscription',
        //         'transaction_hash'  => $transaction->uuid,
        //         'amount'            => $amount,
        //         'currency'          => $transaction->currency,
        //         'plan_id'           => Arr::get($plan, 'id'),
        //     ]
        // ];
    }

    private function getOrCreateCustomer($fcCustomer, $mpFormData, $billingAddress = [])
    {
        $email = Arr::get($mpFormData, 'payer.email', $fcCustomer->email);

        // if exists
        $existingMercadoPagoCustomerId = $fcCustomer->getMeta('mercado_pago_customer_id', false);

        if ($existingMercadoPagoCustomerId) {
            $existingMercadoPagoCustomer = MercadoPagoAPI::getMercadoPagoObject('v1/customers/' . $existingMercadoPagoCustomerId);
            if (!is_wp_error($existingMercadoPagoCustomer) && Arr::get($existingMercadoPagoCustomer, 'id')) {
                return $existingMercadoPagoCustomer;
            }
        }

        $customerData = [
            'email' => $email,
            'first_name' => Arr::get($mpFormData, 'payer.first_name', $fcCustomer->first_name),
            'last_name' => Arr::get($mpFormData, 'payer.last_name', $fcCustomer->last_name),
        ];

        if (Arr::get($mpFormData, 'payer.identification') && Arr::get($mpFormData, 'payer.identification.number')) {
            $customerData['identification'] = [
                'type' => Arr::get($mpFormData, 'payer.identification.type'),
                'number' => Arr::get($mpFormData, 'payer.identification.number'),
            ];
        }

        if (Arr::get($mpFormData, 'payer.address') ) {
            $customerData['address'] = [
                'street_name' => Arr::get($mpFormData, 'payer.address.street_name', Arr::get($billingAddress, 'address_1')),
                'street_number' => Arr::get($mpFormData, 'payer.address.street_number', ''),
                'zip_code' => Arr::get($mpFormData, 'payer.address.zip_code'),
                'city' => Arr::get($mpFormData, 'payer.address.city')
            ];
        }

        $customer = MercadoPagoAPI::createMercadoPagoObject('v1/customers', $customerData);

        if (is_wp_error($customer)) {
            return $customer;
        }

        $fcCustomer->updateMeta('mercado_pago_customer_id', Arr::get($customer, 'id'));

        return $customer;
    }

    private function getOrCreateCard($fcCustomer, $mercadoPagoCustomer, $mpFormData)
    {
        $token = Arr::get($mpFormData, 'token');
        $cardId = $fcCustomer->getMeta('mercado_pago_card_id', false);

        if ($cardId) {
            $mercadoPagoCard = MercadoPagoAPI::getMercadoPagoObject('v1/customers/' . Arr::get($mercadoPagoCustomer, 'id') . '/cards/' . $cardId);
        }

        $mercadoPagoCustomerId = Arr::get($mercadoPagoCustomer, 'id');
        $cardData = [
            'token' => $token,
        ];

        $mercadoPagoCard = MercadoPagoAPI::createMercadoPagoObject('v1/customers/' . $mercadoPagoCustomerId . '/cards', $cardData);

        
        if (is_wp_error($mercadoPagoCard)) {
            return $mercadoPagoCard;
        }

        $fcCustomer->updateMeta('mercado_pago_card_token_id', Arr::get($mercadoPagoCard, 'id'));

        return Arr::get($mercadoPagoCard, 'id');
    }   

    public function getOrCreatePreApprovalPlan(PaymentInstance $paymentInstance, $planData = [])
    {

        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
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

 
    private function getPlanName($order)
    {
        $items = $order->order_items->filter(function($item) {
            return $item->payment_type == 'subscription';
        });

        $subscriptionItem = $items[0];
        return $subscriptionItem->post_title . ' - ' . $subscriptionItem->title;
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
        $order = $subscriptionModel->order;

        if (!$order) {
            return $subscriptionModel;
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;

        if (empty($vendorSubscriptionId)) {
            return $subscriptionModel;
        }

        // Get subscription details from Mercado Pago
        $subscriptionDetails = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $vendorSubscriptionId);

        if (is_wp_error($subscriptionDetails)) {
            return $subscriptionModel;
        }

        $subscriptionTransactions = [];
        $queryParams = [];

        do {
            $transactionsResponse = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $vendorSubscriptionId . '/transactions', $queryParams);
            
            if (is_wp_error($transactionsResponse)) {
                break;
            }
            
            $subscriptionTransactions = array_merge($subscriptionTransactions, Arr::get($transactionsResponse, 'data', []));

            $hasMore = Arr::get($transactionsResponse, 'meta.pagination.has_more', false);
            
            if ($hasMore) {
                $next = Arr::get($transactionsResponse, 'meta.pagination.next');
                if ($next) {
                    $queryString = wp_parse_url($next, PHP_URL_QUERY);
                    wp_parse_str($queryString, $params);
                    $queryParams['after'] = $params['after'] ?? null;
                } else {
                    $hasMore = false;
                }
            }
        } while ($hasMore);

        $subscriptionUpdateData = MercadoPagoHelper::getSubscriptionUpdateData($subscriptionDetails, $subscriptionModel);

        $newPayment = false;

        if (!empty($subscriptionTransactions)) {

            $subscriptionTransactions = array_reverse($subscriptionTransactions);
            
            foreach ($subscriptionTransactions as $mpTransaction) {
                $amount = Arr::get($mpTransaction, 'amount');
                $vendorChargeId = Arr::get($mpTransaction, 'id');
                $mpStatus = Arr::get($mpTransaction, 'status');
                
                if (!in_array($mpStatus, ['approved', 'processed'])) {
                    continue;
                }

                $existingTransaction = OrderTransaction::query()
                    ->where('vendor_charge_id', $vendorChargeId)
                    ->first();

                if ($existingTransaction) {
                    continue;
                }

                $pendingTransaction = OrderTransaction::query()
                    ->where('subscription_id', $subscriptionModel->id)
                    ->where('status', Status::TRANSACTION_PENDING)
                    ->where(function($query) {
                        $query->whereNull('vendor_charge_id')
                              ->orWhere('vendor_charge_id', '');
                    })
                    ->first();

                if ($pendingTransaction) {
                    $pendingTransaction->update([
                        'vendor_charge_id' => $vendorChargeId,
                        'status'           => Status::TRANSACTION_SUCCEEDED,
                        'total'            => \FluentCart\App\Helpers\Helper::toCent($amount),
                    ]);
                    
                    (new \FluentCart\App\Helpers\StatusHelper($pendingTransaction->order))->syncOrderStatuses($pendingTransaction);
                    continue;
                }

                $transactionData = [
                    'order_id'         => $order->id,
                    'subscription_id'  => $subscriptionModel->id,
                    'vendor_charge_id' => $vendorChargeId,
                    'status'           => Status::TRANSACTION_SUCCEEDED,
                    'total'            => \FluentCart\App\Helpers\Helper::toCent($amount),
                    'currency'         => $subscriptionModel->currency ?? $order->currency,
                    'meta'             => [
                        'mercado_pago_transaction' => $mpTransaction,
                    ],
                ];
                
                SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                $newPayment = true;
            }
        }

        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        }

        return $subscriptionModel;
    }

    public function confirmSubscriptionAfterChargeSucceeded(Subscription $subscription, $billingInfo = [], $vendorSubscriptionId = '')
    {
        $order = $subscription->order;

        if (!$order) {
            return $subscription;
        }

        if (empty($vendorSubscriptionId)) {
            $vendorSubscriptionId = $subscription->vendor_subscription_id;
        }

        if (empty($vendorSubscriptionId)) {
            return $subscription;
        }

        $response = MercadoPagoAPI::getMercadoPagoObject('preapproval/' . $vendorSubscriptionId);

        if (is_wp_error($response)) {
            return $subscription;
        }

        $nextBillingDate = Arr::get($response, 'next_payment_date');
        if ($nextBillingDate) {
            $nextBillingDate = DateTime::anyTimeToGmt($nextBillingDate)->format('Y-m-d H:i:s');
        }

        $status = MercadoPagoHelper::mapSubscriptionStatus(Arr::get($response, 'status'));
        $billCount = OrderTransaction::query()
            ->where('subscription_id', $subscription->id)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->where('status', Status::TRANSACTION_SUCCEEDED)
            ->count();

        $oldStatus = $subscription->status;

        if (Arr::get($response, 'id')) {
            $updateData = [
                'status'                 => $status,
                'vendor_customer_id'     => Arr::get($response, 'payer_id'),
                'current_payment_method' => 'mercado_pago',
                'vendor_subscription_id' => Arr::get($response, 'id'),
                'bill_count'             => $billCount,
            ];

            if ($nextBillingDate) {
                $updateData['next_billing_date'] = $nextBillingDate;
            }

            $subscription->update($updateData);
        }

        if (!empty($billingInfo)) {
            $subscription->updateMeta('active_payment_method', $billingInfo);
        }

        if ($oldStatus !== $subscription->status && in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            (new SubscriptionActivated($subscription, $order, $order->customer))->dispatch();
        }

        return $subscription;
    }

    private function mapIntervalFrequency($fluentcartInterval)
    {
        $intervalMap = [
            'daily'   => 1,
            'weekly'  => 7,
            'monthly' => 1,
            'yearly'  => 12,
            'quarterly' => 3,
            'half_yearly' => 6,
        ];

        return $intervalMap[$fluentcartInterval] ?? 1;

    }

    private function mapIntervalType($fluentcartInterval)
    {
        $intervalMap = [
            'daily'   => 'days',
            'weekly'  => 'days', // Mercado Pago doesn't have weeks, use months
            'monthly' => 'months',
            'yearly'  => 'months',
            'quarterly' => 'months',
            'half_yearly' => 'months',
        ];

        return $intervalMap[$fluentcartInterval] ?? 'months';
    }
}
