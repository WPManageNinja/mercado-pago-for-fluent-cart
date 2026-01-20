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

        // Get or create preapproval plan
        $plan = $this->getOrCreatePreapprovalPlan($paymentInstance, $paymentArgs);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'id'),
        ]);

        $mercadoPagoCustomer = $this->getOrCreateCustomer($fcCustomer, $mpFormData, $billingAddress);

        if (is_wp_error($mercadoPagoCustomer)) {
            return $mercadoPagoCustomer;
        }

        // create card token if not exists
        $card = $this->getOrCreateCard($fcCustomer, $mercadoPagoCustomer, $mpFormData);


        if (is_wp_error($cardToken)) {
            return $cardToken;
        }

        $amount = MercadoPagoHelper::formatAmount($transaction->total, $transaction->currency);

        $paymentData = [
            'preapproval_plan_id' => Arr::get($plan, 'id'),
            'external_reference'  => $subscription->uuid,
            'payer_email'         => Arr::get($mpFormData, 'payer.email', $fcCustomer->email),
            'back_url'            => Arr::get($paymentArgs, 'success_url'),
            'card_token_id'       => $cardToken,
            'status'              => 'pending',
            
        ];


        $paymentData = apply_filters('fluent_cart/mercadopago/subscription_payment_args', $paymentData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscription
        ]);


        // create subscription
        $mpSubscription = MercadoPagoAPI::createMercadoPagoObject('preapproval', $paymentData);


        if (is_wp_error($subscription)) {
            return $subscription;
        }

        $subscription->update([
            'vendor_subscription_id' => Arr::get($subscription, 'id'),
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

    public function getOrCreatePreapprovalPlan(PaymentInstance $paymentInstance, $paymentArgs = [])
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

        $planData = [
            'reason'              => $this->getPlanName($order) ?: $subscription->item_name,
            'auto_recurring'      => $autoRecurringData,
            'transaction_amount'  => MercadoPagoHelper::formatAmount($subscription->recurring_total, $transaction->currency),
            'currency_id'         => strtoupper($transaction->currency),
            'back_url'            => Arr::get($paymentArgs, 'success_url'),
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
            $queryString = wp_parse_url($next, PHP_URL_QUERY);
            wp_parse_str($queryString, $params);
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

