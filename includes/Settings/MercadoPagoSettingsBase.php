<?php

namespace MercadoPagoFluentCart\Settings;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

if (!defined('ABSPATH')) {
    exit;
}


class MercadoPagoSettingsBase extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_mercado_pago';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('mercadopago_fc/mercadopago_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'          => 'no',
            'test_public_key'    => '',
            'test_access_token'  => '',
            'live_public_key'    => '',
            'live_access_token'  => '',
            'test_webhook_secret' => '',
            'live_webhook_secret' => '',
            'checkout_type'      => 'modal',
            'payment_mode'       => 'test',
            'enable_wallet_support' => false,
            'boleto_payment_enabled' => true,
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getMode()
    {
        return (new StoreSettings)->get('order_mode');
    }

    public function getAccessToken($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $accessToken = $this->get('test_access_token');
        } else {
            $accessToken = $this->get('live_access_token');
        }

        return Helper::decryptKey($accessToken);
    }

    public function getWebhookSecretKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            $webhookSecretKey = $this->get('test_webhook_secret');
        } else {
            $webhookSecretKey = $this->get('live_webhook_secret');
        }

        return $webhookSecretKey;
    }

    public function getPublicKey($mode = 'current')
    {
        if ($mode == 'current' || !$mode) {
            $mode = $this->getMode();
        }

        if ($mode === 'test') {
            return $this->get('test_public_key');
        } else {
            return $this->get('live_public_key');
        }
    }
}

