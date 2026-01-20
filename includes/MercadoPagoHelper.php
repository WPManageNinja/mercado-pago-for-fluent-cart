<?php

namespace MercadoPagoFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoHelper
{
    
    public static function checkCurrencySupport($currency = '')
    {
        $supportedCurrencies = self::getSupportedCurrencies();
        $currentCurrency = strtoupper($currency) ?? CurrencySettings::get('currency');

        if (!in_array($currentCurrency, $supportedCurrencies)) {
            wp_send_json([
                'status' => 'failed',
                'message' => __('Mercado Pago does not support currency', 'mercado-pago-for-fluent-cart') . ' ' . $currentCurrency,
            ], 400);
        }

        return true;
    }


    public static function getSupportedCurrencies()
    {
        return [
            'ARS', // Argentina Peso
            'BRL', // Brazilian Real
            'CLP', // Chilean Peso
            'MXN', // Mexican Peso
            'COP', // Colombian Peso
            'PEN', // Peruvian Sol
            'UYU', // Uruguayan Peso
            'USD', // US Dollar
        ];
    }

    public static function getFctSubscriptionStatus($mercadoPagoStatus)
    {
        $statusMap = [
            'authorized' => Status::SUBSCRIPTION_ACTIVE,
            'paused'     => Status::SUBSCRIPTION_PAUSED,
            'cancelled'  => Status::SUBSCRIPTION_CANCELED,
            'pending'    => Status::SUBSCRIPTION_PENDING,
        ];

        return $statusMap[$mercadoPagoStatus] ?? Status::SUBSCRIPTION_PENDING;
    }


    public static function getOrderFromTransactionHash($transactionHash)
    {
        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'mercado_pago')
            ->first();

        if ($transaction) {
            return Order::query()->where('id', $transaction->order_id)->first();
        }

        return null;
    }


    public static function formatAmount($amount, $currency)
    {
        $zeroDecimalCurrencies = ['CLP'];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (float) round($amount);
        }

        return (float) number_format($amount / 100, 2, '.', '');
    }

 
    public static function getPaymentType($paymentTypeId)
    {
        $typeMap = [
            'credit_card' => 'card',
            'debit_card'  => 'card',
            'ticket'      => 'boleto',
            'bank_transfer' => 'bank_transfer',
            'account_money' => 'mercado_pago_wallet',
            'pix'         => 'pix',
        ];

        return $typeMap[$paymentTypeId] ?? $paymentTypeId;
    }

  
    public static function getSubscriptionUpdateData($subscriptionDetails, $subscriptionModel)
    {
        $status = self::getFctSubscriptionStatus(Arr::get($subscriptionDetails, 'status'));
        
        $updateData = [
            'status' => $status,
        ];

        // Update next billing date if available
        $nextPaymentDate = Arr::get($subscriptionDetails, 'next_payment_date');
        if ($nextPaymentDate) {
            $updateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

     
        $payerId = Arr::get($subscriptionDetails, 'payer_id');
        if ($payerId) {
            $updateData['vendor_customer_id'] = $payerId;
        }

        return $updateData;
    }

    public static function formatPayerInfo($fcCustomer, $billingAddress, $mpFormData)
    {
       $payerInfo = [
        'email' => Arr::get($mpFormData, 'payer.email', $fcCustomer->email),
        'first_name' => Arr::get($mpFormData, 'payer.first_name', $fcCustomer->first_name),
        'last_name'  => Arr::get($mpFormData, 'payer.last_name', $fcCustomer->last_name),
       ];

        if ($billingAddress) {
            // federal unit is the state of the address , and it should
            $payerInfo['address'] = [
                'zip_code'     => Arr::get($mpFormData, 'payer.address.zip_code', $billingAddress->postcode ?? ''),
                'street_name'  => Arr::get($mpFormData, 'payer.address.street_name', $billingAddress->address_1 ?? ''),
                'city'         => Arr::get($mpFormData, 'payer.address.city', $billingAddress->city ?? ''),
                'street_number' => Arr::get($mpFormData, 'payer.address.street_number', ''),
                'neighborhood' => Arr::get($mpFormData, 'payer.address.neighborhood', ''),
                'federal_unit' => Arr::get($mpFormData, 'payer.address.federal_unit', ''),
            ];

            if (Arr::get($mpFormData, 'payment_method_id') === 'bolbradesco' && Arr::get($mpFormData, 'payer.address.federal_unit', '')) {
                $payerInfo['address']['federal_unit'] = self::resolveBrazilianUF(Arr::get($mpFormData, 'payer.address.federal_unit', '')) ?? Arr::get($mpFormData, 'payer.address.federal_unit', '');
            }

        }

        if (Arr::get($mpFormData, key: 'payer.identification')) {
            $payerInfo['identification'] = [
                'type' => Arr::get($mpFormData, 'payer.identification.type', ''),
                'number' => Arr::get($mpFormData, 'payer.identification.number', ''),
            ];
        }
       

       return $payerInfo;
    }

    public static function resolveBrazilianUF(?string $input): ?string
    {
        if (!$input) {
            return null;
        }
        
        // Case 1: already a valid UF code
        if (preg_match('/^[a-z]{2}$/', $input)) {
            return strtoupper($input);
        }

        $BRAZIL_UF_MAP = [
            'Acre' => 'AC',
            'Alagoas' => 'AL',
            'Amapá' => 'AP',
            'Amazonas' => 'AM',
            'Bahia' => 'BA',
            'Ceará' => 'CE',
            'Distrito Federal' => 'DF',
            'Espírito Santo' => 'ES',
            'Goiás' => 'GO',
            'Maranhão' => 'MA',
            'Mato Grosso' => 'MT',
            'Mato Grosso do Sul' => 'MS',
            'Minas Gerais' => 'MG',
            'Pará' => 'PA',
            'Paraíba' => 'PB',
            'Paraná' => 'PR',
            'Pernambuco' => 'PE',
            'Piauí' => 'PI',
            'Rio de Janeiro' => 'RJ',
            'Rio Grande do Norte' => 'RN',
            'Rio Grande do Sul' => 'RS',
            'Rondônia' => 'RO',
            'Roraima' => 'RR',
            'Santa Catarina' => 'SC',
            'São Paulo' => 'SP',
            'Sergipe' => 'SE',
            'Tocantins' => 'TO',
        ];

        // Case 2: full state name
        return $BRAZIL_UF_MAP[$input] ?? null;
    }

    public static function determineLocale($currency = '')
    {

        if (!empty($currency)) {
            $currency = strtoupper($currency);
        }

        $localesMap = [
            'BRL' => 'pt-BR',
            'ARS' => 'es-AR',
            'CLP' => 'es-CL',
            'MXN' => 'es-MX',
            'COP' => 'es-CO',
            'PEN' => 'es-PE',
            'UYU' => 'es-UY',
            'USD' => 'en-US',
        ];

        return $localesMap[$currency] ?? determine_locale();

    }
}

