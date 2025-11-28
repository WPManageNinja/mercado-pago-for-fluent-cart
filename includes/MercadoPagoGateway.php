<?php

namespace MercadoPagoFluentCart;


use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;
use MercadoPagoFluentCart\Subscriptions\MercadoPagoSubscriptions;
use MercadoPagoFluentCart\Refund\MercadoPagoRefund;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class MercadoPagoGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'mercado_pago';
    private $addonSlug = 'mercado-pago-for-fluent-cart';
    private $addonFile = 'mercado-pago-for-fluent-cart/mercado-pago-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions'
    ];

    public function __construct()
    {
        parent::__construct(
            new MercadoPagoSettingsBase(),
            new MercadoPagoSubscriptions()
        );
    }

    public function meta(): array
    {
        $logo = MERCADOPAGO_FCT_PLUGIN_URL . 'assets/images/mercado-pago-logo.svg';
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);
        
        return [
            'title'              => __('Mercado Pago', 'mercado-pago-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Mercado Pago',
            'admin_title'        => 'Mercado Pago',
            'description'        => __('Pay securely with Mercado Pago - Card, Pix, Boleto, and more', 'mercado-pago-for-fluent-cart'),
            'logo'               => $logo,
            'tag' => 'beta',
            'icon'               => $logo,
            'brand_color'        => '#009EE3',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'is_addon'           => true,
            'addon_source'       => [
                'type' => 'github',
                'link' => 'https://github.com/WPManageNinja/mercado-pago-for-fluent-cart/releases/latest',
                'slug' => $this->addonSlug,
                'file' => $this->addonFile
            ],
            'addon_status'       => $addonStatus,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Initialize IPN handler
        (new Webhook\MercadoPagoWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/mercado_pago_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\MercadoPagoConfirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            return (new Subscriptions\MercadoPagoSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Onetime\MercadoPagoProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        MercadoPagoHelper::checkCurrencySupport();

        $publicKey = (new Settings\MercadoPagoSettingsBase())->getPublicKey();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'mercado-pago-for-fluent-cart'),
            'payment_args' => [
                'public_key' => $publicKey

            ],
        ], 200);
    }


    public function handleIPN(): void
    {
        (new Webhook\MercadoPagoWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'mercadopago-sdk-js',
                'src'    => 'https://sdk.mercadopago.com/js/v2',
            ],
            [
                'handle' => 'mercadopago-fluent-cart-checkout-handler',
                'src'    => MERCADOPAGO_FCT_PLUGIN_URL . 'assets/mercado-pago-checkout.js',
                'version' => MERCADOPAGO_FCT_VERSION,
                'deps'   => ['mercadopago-sdk-js']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_mercadopago_data' => [
                'public_key' => $this->settings->getPublicKey(),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'mercado-pago-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'mercado-pago-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'mercado-pago-for-fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'mercado-pago-for-fluent-cart'),
                    'Available payment methods on Checkout' => __('Available payment methods on Checkout', 'mercado-pago-for-fluent-cart'),
                    'Cards' => __('Cards', 'mercado-pago-for-fluent-cart'),
                    'Pix' => __('Pix', 'mercado-pago-for-fluent-cart'),
                    'Boleto' => __('Boleto', 'mercado-pago-for-fluent-cart'),
                    'Something went wrong' => __('Something went wrong', 'mercado-pago-for-fluent-cart'),
                ],
                'nonce' => wp_create_nonce('mercadopago_fct_nonce')
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return 'https://www.mercadopago.com/activities';
        }

        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === status::TRANSACTION_REFUNDED) {
            return 'https://www.mercadopago.com/activities';
        }

        return 'https://www.mercadopago.com/activities';
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        if (!$subscription || !$subscription->vendor_subscription_id) {
            return 'https://www.mercadopago.com/subscriptions';
        }

        return 'https://www.mercadopago.com/subscriptions/' . $subscription->vendor_subscription_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'mercadopago_refund_error',
                __('Refund amount is required.', 'mercado-pago-for-fluent-cart')
            );
        }

        return (new MercadoPagoRefund())->processRemoteRefund($transaction, $amount, $args);

    }

    public function getWebhookInstructions(): string
    { 
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
        $configureLink = 'https://www.mercadopago.com/developers/panel/app';

        return sprintf(
            '<div>
                <p><b>%s</b><code class="copyable-content">%s</code></p>
                <p>%s</p>
            </div>',
            __('Webhook URL: ', 'mercado-pago-for-fluent-cart'),
            esc_html($webhook_url),
            sprintf(
                /* translators: %s: Mercado Pago Developer Settings link */
                __('Configure this webhook URL in your Mercado Pago Dashboard under Your integrations > Webhooks to receive payment notifications. You can access the <a href="%1$s" target="_blank">%2$s</a> here.', 'mercado-pago-for-fluent-cart'),
                esc_url($configureLink),
                __('Mercado Pago Developer Settings Page', 'mercado-pago-for-fluent-cart')
            )
        );

    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'mercado-pago-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'mercado-pago-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_public_key' => [
                                'value'       => '',
                                'label'       => __('Live Public Key', 'mercado-pago-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('APP_USR-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'live_access_token' => [
                                'value'       => '',
                                'label'       => __('Live Access Token', 'mercado-pago-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'mercado-pago-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_public_key' => [
                                'value'       => '',
                                'label'       => __('Test Public Key', 'mercado-pago-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('TEST-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'test_access_token' => [
                                'value'       => '',
                                'label'       => __('Test Access Token', 'mercado-pago-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('TEST-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'mercado-pago-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_access_token'] = Helper::encryptKey($data['test_access_token']);
        } else {
            $data['live_access_token'] = Helper::encryptKey($data['live_access_token']);
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('mercado_pago', new self());
    }
}

