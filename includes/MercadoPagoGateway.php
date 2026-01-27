<?php

namespace MercadoPagoFluentCart;


use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\PluginInstaller\PaymentAddonManager;
use MercadoPagoFluentCart\Settings\MercadoPagoSettingsBase;
use MercadoPagoFluentCart\Refund\MercadoPagoRefund;
use MercadoPagoFluentCart\API\MercadoPagoAPI;

if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'mercado_pago';
    private $addonSlug = 'mercado-pago-for-fluent-cart';
    private $addonFile = 'mercado-pago-for-fluent-cart/mercado-pago-for-fluent-cart.php';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(
            new MercadoPagoSettingsBase(),
        );
    }

    public function meta(): array
    {
        $logo = MERCADOPAGO_FCT_PLUGIN_URL . 'assets/images/mercado-pago-logo.svg';
        $addonStatus = PaymentAddonManager::getAddonStatus($this->addonSlug, $this->addonFile);

        return [
            'title' => __('Mercado Pago', 'mercado-pago-for-fluent-cart'),
            'route' => $this->methodSlug,
            'slug' => $this->methodSlug,
            'label' => 'Mercado Pago',
            'admin_title' => 'Mercado Pago',
            'description' => __('Pay securely with Mercado Pago - Card, Pix, Boleto, and more', 'mercado-pago-for-fluent-cart'),
            'logo' => $logo,
            'tag' => 'beta',
            'icon' => $logo,
            'brand_color' => '#009EE3',
            'status' => $this->settings->get('is_active') === 'yes',
            'upcoming' => false,
            'is_addon' => true,
            'addon_source' => [
                'type' => 'github',
                'link' => 'https://github.com/WPManageNinja/mercado-pago-for-fluent-cart/releases/latest',
                'slug' => $this->addonSlug,
                'file' => $this->addonFile
            ],
            'addon_status' => $addonStatus,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        (new Webhook\MercadoPagoWebhook())->init();

        add_filter('fluent_cart/payment_methods/mercado_pago_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\MercadoPagoConfirmations())->init();
        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'mercado_pago';
            return $methods;
        });
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url' => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            // not handling subscriptions for now
            // return (new Subscriptions\MercadoPagoSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);

            wp_send_json([
                'status' => 'failed',
                'message' => __('Subscriptions are not supported for Mercado Pago yet.', 'mercado-pago-for-fluent-cart')
            ], 422);
        }

        return (new Onetime\MercadoPagoProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        MercadoPagoHelper::checkCurrencySupport(CurrencySettings::get('currency'));

        $publicKey = (new Settings\MercadoPagoSettingsBase())->getPublicKey();

        if (empty($publicKey)) {
            wp_send_json([
                'status' => 'failed',
                'message' => __('No valid Public Key found!', 'mercado-pago-for-fluent-cart')
            ], 422);
        }

        $cart = CartHelper::getCart();
        $checkOutHelper = CartCheckoutHelper::make();
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $tax = $checkOutHelper->getCart()->checkout_data['tax_data'] ?? [];
        if (Arr::get($tax, 'tax_behavior', 0) == 1) {
            $totalPrice = $totalPrice + Arr::get($tax, 'tax_total', 0) + Arr::get($tax, 'shipping_tax', 0);
        }

        $items = $checkOutHelper->getItems();
        $hasSubscription = $this->validateSubscriptions($items);

        $paymentArgs['public_key'] = $publicKey;
        $paymentArgs['locale'] = MercadoPagoHelper::determineLocale(CurrencySettings::get('currency'));
        // $paymentArgs['boleto_payment_enabled'] = $this->settings->get('boleto_payment_enabled') == 'yes';

        $paymentDetails = [
            'mode' => 'payment',
            'amount' => MercadoPagoHelper::formatAmount($totalPrice, CurrencySettings::get('currency')),
            'currency' => strtoupper(CurrencySettings::get('currency')),
            'payer_email' => $cart->customer->email ?? ''
        ];

        if ($hasSubscription) {
            $paymentDetails['mode'] = 'subscription';
        }

        // wallet support is disabled for now
        // $preference = $this->createPreference($items, $shippingCharge, Arr::get($tax, 'tax_total', 0));


        // if (!is_wp_error($preference)) {
        //     $paymentArgs['preference_id'] = Arr::get($preference, 'id', '');
        // }

        wp_send_json([
            'status' => 'success',
            'message' => __('Order info retrieved!', 'mercado-pago-for-fluent-cart'),
            'payment_args' => $paymentArgs,
            'intent' => $paymentDetails,
        ], 200);

    }

    public function createPreference($cartItems, $shippingCharge = 0, $tax = 0)
    {
        $cart = CartHelper::getCart();
        $items = [];
        foreach ($cartItems as $item) {
            $items[] = [
                'title' => Arr::get($item, 'post_title', ''),
                'quantity' => Arr::get($item, 'quantity', 1),
                'unit_price' => MercadoPagoHelper::formatAmount(Arr::get($item, 'line_total', 0) / Arr::get($item, 'quantity', 1), CurrencySettings::get('currency')),
            ];
        }

        if ($shippingCharge > 0) {
            $items[] = [
                'title' => __('Shipping Charge', 'mercado-pago-for-fluent-cart'),
                'quantity' => 1,
                'unit_price' => MercadoPagoHelper::formatAmount($shippingCharge, CurrencySettings::get('currency')),
            ];
        }

        if ($tax > 0) {
            $items[] = [
                'title' => __('Tax', 'mercado-pago-for-fluent-cart'),
                'quantity' => 1,
                'unit_price' => MercadoPagoHelper::formatAmount($tax, CurrencySettings::get('currency')),
            ];
        }



        return MercadoPagoAPI::createMercadoPagoObject('/checkout/preferences', [
            'items' => $items,
            // 'auto_return' => 'approved',
            // 'back_urls' => [
            //     'success' => $this->getSuccessUrl($paymentInstance->transaction),
            //     'failure' => $this->getCancelUrl(),
            //     'pending' => $this->getSuccessUrl($paymentInstance->transaction),
            // ],
            'notification_url' => self::webhookUrl(),
            'external_reference' => $cart->cart_hash,
        ]);
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
                'src' => 'https://sdk.mercadopago.com/js/v2',
            ],
            [
                'handle' => 'mercadopago-fluent-cart-checkout-handler',
                'src' => MERCADOPAGO_FCT_PLUGIN_URL . 'assets/mercado-pago-checkout.js',
                'version' => MERCADOPAGO_FCT_VERSION,
                'deps' => ['mercadopago-sdk-js']
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'mercadopago-fluent-cart-checkout-styles',
                'src' => MERCADOPAGO_FCT_PLUGIN_URL . 'assets/mercado-pago-checkout.css',
                'version' => MERCADOPAGO_FCT_VERSION,
            ]
        ];
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
                    'Confirm After Payment' => __('Confirm After Payment', 'mercado-pago-for-fluent-cart'),
                    'An error occurred while loading Mercado Pago.' => __('An error occurred while loading Mercado Pago.', 'mercado-pago-for-fluent-cart'),
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

        $suffixMap = [
            'BRL' => 'br',
            'MXN' => 'mx',
            'COP' => 'co',
            'CLP' => 'cl',
            'ARS' => 'ar',
            'PEN' => 'pe',
            'VE' => 've',
            'UYU' => 'uy',
        ];
        $suffix = Arr::get($suffixMap, $transaction->currency, '');

        if ($suffix) {
            $domain = 'https://www.mercadopago.com.' . $suffix;
        } else {
            $domain = 'https://www.mercadopago.com';
        }

        if (!$transaction) {
            return $domain . '/activities';
        }

        $suffix = strtolower($transaction->currency);
        if ($transaction->status === status::TRANSACTION_REFUNDED) {
            return $domain . '/activities';
        }

        return $domain . '/activities';
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        $suffixMap = [
            'BRL' => 'br',
            'MXN' => 'mx',
            'COP' => 'co',
            'CLP' => 'cl',
            'ARS' => 'ar',
            'PEN' => 'pe',
            'VE' => 've',
        ];
        $suffix = Arr::get($suffixMap, $subscription->currency, '');

        if ($suffix) {
            $domain = 'https://www.mercadopago.com.' . $suffix;
        } else {
            $domain = 'https://www.mercadopago.com';
        }

        if (!$subscription || !$subscription->vendor_customer_id) {
            return $domain . '/subscription-plans/list';
        }

        return $domain . '/subscription-plans/subscription-details?id=' . $subscription->vendor_subscription_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'mercadopago_refund_error',
                __('Refund amount is required.', 'mercado-pago-for-fluent-cart')
            );
        }

        return MercadoPagoRefund::processRemoteRefund($transaction, $amount, $args);

    }

    public static function webhookUrl(): string
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
    }

    public function getWebhookInstructions(): string
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=mercado_pago');
        $configureLink = 'https://www.mercadopago.com/developers/panel/app';

        return sprintf(
            '<div style="line-height: 1.8;">
                <p><b>%s</b><code class="copyable-content">%s</code></p>
                <p>%s</p>
                <ol style="margin-left: 20px;">
                    <li>%s</li>
                    <li>%s</li>
                    <li>%s</li>
                    <li>%s</li>
                </ol>
                <p style="margin-top: 10px;"><b>%s</b> %s</p>
            </div>',
            __('Webhook URL:', 'mercado-pago-for-fluent-cart'),
            esc_html($webhook_url),
            sprintf(
                'Configure webhooks in your <a href="%1$s" target="_blank">%2$s</a>:',
                esc_url($configureLink),
                'Mercado Pago Developer Dashboard'
            ),
            __('Go to Your integrations > Select your application > Webhooks', 'mercado-pago-for-fluent-cart'),
            __('Enter the webhook URL above in "Production mode URL" or "Test mode URL"', 'mercado-pago-for-fluent-cart'),
            __('Select these events: <strong>Payments</strong>, <strong>Orders</strong>, and <strong>Plans and Subscriptions</strong>', 'mercado-pago-for-fluent-cart'),
            __('Click Save - A <strong>secret signature</strong> will be generated', 'mercado-pago-for-fluent-cart'),
            __('Important:', 'mercado-pago-for-fluent-cart'),
            __('Copy the generated secret signature and paste it in the "Webhook Secret" field above (for the corresponding mode). This is required to verify webhook authenticity and security.', 'mercado-pago-for-fluent-cart')
        );

    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'mercado-pago-for-fluent-cart'),
                'type' => 'notice'
            ],
            'payment_mode' => [
                'type' => 'tabs',
                'schema' => [
                    [
                        'type' => 'tab',
                        'label' => __('Live credentials', 'mercado-pago-for-fluent-cart'),
                        'value' => 'live',
                        'schema' => [
                            'live_public_key' => [
                                'value' => '',
                                'label' => __('Live Public Key', 'mercado-pago-for-fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('APP_USR-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'live_access_token' => [
                                'value' => '',
                                'label' => __('Live Access Token', 'mercado-pago-for-fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'live_webhook_secret' => [
                                'value' => '',
                                'label' => __('Live Webhook Secret', 'mercado-pago-for-fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your webhook secret signature', 'mercado-pago-for-fluent-cart'),
                                'help' => __('Found in Your integrations > Webhooks section. Used to verify webhook authenticity.', 'mercado-pago-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type' => 'tab',
                        'label' => __('Test credentials', 'mercado-pago-for-fluent-cart'),
                        'value' => 'test',
                        'schema' => [
                            'test_public_key' => [
                                'value' => '',
                                'label' => __('Test Public Key', 'mercado-pago-for-fluent-cart'),
                                'type' => 'text',
                                'placeholder' => __('TEST-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'test_access_token' => [
                                'value' => '',
                                'label' => __('Test Access Token', 'mercado-pago-for-fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('TEST-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'mercado-pago-for-fluent-cart'),
                            ],
                            'test_webhook_secret' => [
                                'value' => '',
                                'label' => __('Test Webhook Secret', 'mercado-pago-for-fluent-cart'),
                                'type' => 'password',
                                'placeholder' => __('Your webhook secret signature', 'mercado-pago-for-fluent-cart'),
                                'help' => __('Found in Your integrations > Webhooks section. Used to verify webhook authenticity.', 'mercado-pago-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            // 'boleto_payment_enabled' => [
            //     'value' => true,
            //     'label' => __('Enable Boleto Payment', 'mercado-pago-for-fluent-cart'),
            //     'type' => 'checkbox',
            //     'description' => __('Enable Boleto payment for your store.', 'mercado-pago-for-fluent-cart'),
            // ],
            'enable_wallet_support' => [
                'value' => false,
                'label' => __('Enable Wallet Payment Support', 'mercado-pago-for-fluent-cart'),
                'type' => 'checkbox',
                'disabled' => true,
                'description' => __('Mercado Pago Wallet payment support is disabled for now. Coming soon.', 'mercado-pago-for-fluent-cart'),
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'mercado-pago-for-fluent-cart'),
                'type' => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'live');

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