<?php

namespace MercadoPagoFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;

if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoHelper
{
    
    const SITE_CURRENCIES = [
        'MLA' => 'ARS',
        'MLB' => 'BRL',
        'MLC' => 'CLP',
        'MCO' => 'COP',
        'MLM' => 'MXN',
        'MPE' => 'PEN',
        'MLU' => 'UYU',
        'MEC' => 'USD',
    ];

    private static $accountCurrencies = [];

    public static function checkCurrencySupport($currency = '')
    {
        $currentCurrency = strtoupper((string) ($currency ?: CurrencySettings::get('currency')));

        if ($currentCurrency !== self::getAccountCurrency()) {
            wp_send_json([
                'status' => 'failed',
                'message' => __('Mercado Pago does not support currency', 'mercado-pago-for-fluent-cart') . ' ' . $currentCurrency,
            ], 400);
        }

        return true;
    }

    /**
     * Mercado Pago charges in the account's own currency whatever the store sends,
     * so this is the only currency the gateway can accept.
     */
    public static function getAccountCurrency($mode = '')
    {
        $settings = new MercadoPagoSettingsBase();
        $mode = $mode ?: $settings->getMode();
        $key = $mode . '_account_currency';

        if (array_key_exists($mode, self::$accountCurrencies)) {
            return self::$accountCurrencies[$mode];
        }

        $currency = $settings->get($key);
        if (!is_string($currency) || !$currency) {
            $currency = self::fetchAccountCurrency($settings->getAccessToken($mode));
            if ($currency) {
                $stored = (array) $settings->getCachedSettings();
                $stored[$key] = $currency;
                fluent_cart_update_option($settings->methodHandler, $stored);
                BaseGatewaySettings::$allSettings[$settings->methodHandler] = $stored;
            }
        }

        return self::$accountCurrencies[$mode] = $currency ?: null;
    }

    public static function fetchAccountCurrency($accessToken)
    {
        if (!$accessToken) {
            return null;
        }

        $response = wp_remote_get('https://api.mercadopago.com/users/me', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $siteId = Arr::get((array) json_decode(wp_remote_retrieve_body($response), true), 'site_id');

        return Arr::get(self::SITE_CURRENCIES, (string) $siteId);
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
            $payerInfo['address'] = [
                'zip_code'     => Arr::get($mpFormData, 'payer.address.zip_code', $billingAddress->postcode ?? ''),
                'street_name'  => Arr::get($mpFormData, 'payer.address.street_name', $billingAddress->address_1 ?? ''),
                'city'         => Arr::get($mpFormData, 'payer.address.city', $billingAddress->city ?? ''),
                'street_number' => Arr::get($mpFormData, 'payer.address.street_number', ''),
                'neighborhood' => Arr::get($mpFormData, 'payer.address.neighborhood', ''),
                'federal_unit' => Arr::get($mpFormData, 'payer.address.federal_unit', ''),
            ];

            if (Arr::get($mpFormData, 'payment_method_id') === 'bolbradesco' && Arr::get($mpFormData, 'payer.address.federal_unit', '')) {
                $payerInfo['address']['federal_unit'] = self::resolveBrazilianUF(Arr::get($mpFormData, 'payer.address.federal_unit', ''));
            }
        }

        if (Arr::get($mpFormData, 'payer.identification')) {
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
        
        $input = trim($input);

        $ufs = [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
        ];

        if (in_array($input, $ufs)) {
            return $input;
        }


        $input = strtolower($input);


        $BRAZIL_UF_MAP = [
            'acre' => 'AC',
            'alagoas' => 'AL',
            'amapá' => 'AP',
            'amazonas' => 'AM',
            'bahia' => 'BA',
            'ceará' => 'CE',
            'distrito federal' => 'DF',
            'espírito santo' => 'ES',
            'goiás' => 'GO',
            'maranhão' => 'MA',
            'mato grosso' => 'MT',
            'mato grosso do sul' => 'MS',
            'minas gerais' => 'MG',
            'pará' => 'PA',
            'paraíba' => 'PB',
            'paraná' => 'PR',
            'pernambuco' => 'PE',
            'piauí' => 'PI',
            'rio de janeiro' => 'RJ',
            'rio grande do norte' => 'RN',
            'rio grande do sul' => 'RS',
            'rondônia' => 'RO',
            'roraima' => 'RR',
            'santa catarina' => 'SC',
            'são paulo' => 'SP',
            'sergipe' => 'SE',
            'tocantins' => 'TO',
        ];

        if (isset($BRAZIL_UF_MAP[$input])) {
            return $BRAZIL_UF_MAP[$input];
        }

        return null;
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
            'UYU' => 'es-UY'
        ];

        return $localesMap[$currency] ?? determine_locale();

    }
}