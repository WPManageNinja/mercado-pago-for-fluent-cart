# Mercado Pago for FluentCart

Accept payments via Mercado Pago in FluentCart with support for one-time payments, subscriptions, and automatic refunds via webhooks.

## Description

Mercado Pago for FluentCart is a payment gateway addon that seamlessly integrates Mercado Pago's payment processing capabilities with your FluentCart store. This plugin supports multiple payment methods including credit/debit cards, Pix, Boleto, and more.

### Features

- ✅ **One-time Payments** - Accept single payments for products and services
- ✅ **Inline Payment Elements** - Secure checkout using Mercado Pago Checkout Bricks
- ✅ **Multiple Payment Methods** - Cards, Pix, Boleto, and more
- ✅ **Automatic Refunds** - Process refunds directly from FluentCart
- ✅ **Webhook Support** - Real-time payment notifications
- ✅ **Test & Live Modes** - Test thoroughly before going live
- 🔄 **Subscription Support** - Coming soon
- 🌍 **Multi-Currency** - Support for ARS, BRL, CLP, MXN, COP, PEN, UYU, USD

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- FluentCart 1.2.5 or higher
- Mercado Pago Account ([Sign up here](https://www.mercadopago.com))

## Installation

1. Upload the `mercado-pago-for-fluent-cart` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to FluentCart > Settings > Payment Methods
4. Find "Mercado Pago" and click "Configure"
5. Enter your Mercado Pago API credentials
6. Save settings and test your integration

## Configuration

### Getting Your API Credentials

1. Log in to your [Mercado Pago Developer Dashboard](https://www.mercadopago.com/developers/panel/app)
2. Go to "Your integrations" and select or create an application
3. Copy your Public Key and Access Token
4. Enter these credentials in FluentCart settings

### Test Mode

Always test your integration using Test credentials before going live:

1. Use your Test Public Key and Test Access Token
2. Make test purchases using [Mercado Pago test cards](https://www.mercadopago.com/developers/en/docs/checkout-api/additional-content/test-cards)
3. Verify webhooks are working correctly
4. Switch to Live mode when ready

### Webhook Configuration

Webhooks allow Mercado Pago to notify your store about payment status changes in real-time:

1. Copy the webhook URL from FluentCart settings
2. Go to [Mercado Pago Webhooks](https://www.mercadopago.com/developers/panel/app)
3. Add the webhook URL
4. Select events to listen to: `payment`, `subscription.preapproval`, etc.

## Supported Payment Methods

### By Country

- 🇦🇷 **Argentina**: Credit/Debit Cards, Rapipago, Pago Fácil
- 🇧🇷 **Brazil**: Credit/Debit Cards, Pix, Boleto
- 🇨🇱 **Chile**: Credit/Debit Cards, Servipag, Khipu
- 🇲🇽 **Mexico**: Credit/Debit Cards, OXXO, SPEI
- 🇨🇴 **Colombia**: Credit/Debit Cards, PSE, Efecty
- 🇵🇪 **Peru**: Credit/Debit Cards, PagoEfectivo
- 🇺🇾 **Uruguay**: Credit/Debit Cards, Abitab, Redpagos

## Usage

### Processing One-Time Payments

1. Customer adds products to cart
2. Proceeds to checkout
3. Selects Mercado Pago as payment method
4. Completes payment using inline payment form
5. Order is automatically confirmed upon successful payment

### Processing Refunds

1. Go to FluentCart > Orders
2. Select the order to refund
3. Click "Process Refund"
4. Enter refund amount and reason
5. Refund is processed automatically via Mercado Pago API

## Troubleshooting

### Payment Not Confirming

- Check webhook configuration
- Verify API credentials are correct
- Check FluentCart logs for errors
- Ensure SSL certificate is valid

### Currency Not Supported

Mercado Pago supports specific currencies per country:
- Use ARS for Argentina
- Use BRL for Brazil
- Use CLP for Chile
- Use MXN for Mexico
- Use USD for international transactions

### Test Payments Failing

- Verify you're using Test credentials
- Use official Mercado Pago test cards
- Check API connection in settings

## Support

For support, please:

1. Check [Mercado Pago Documentation](https://www.mercadopago.com/developers/en/docs)
2. Review [FluentCart Documentation](https://fluentcart.com/docs)
3. Contact FluentCart support
4. Visit Mercado Pago support

## Changelog

### 1.0.0 - 2025-01-28
- Initial release
- One-time payment support
- Inline payment elements (Checkout Bricks)
- Refund support
- Webhook integration
- Multi-currency support
- Test and Live modes

## Development

### Filter Hooks

```php
// Customize payment data
add_filter('fluent_cart/mercadopago/onetime_payment_args', function($paymentData, $context) {
    // Modify payment data
    return $paymentData;
}, 10, 2);

// Customize settings
add_filter('mercadopago_fc/mercadopago_settings', function($settings) {
    // Modify settings
    return $settings;
});
```

### Action Hooks

```php
// After payment webhook received
add_action('fluent_cart/payments/mercado_pago/webhook_payment_updated', function($data) {
    // Custom logic after payment update
}, 10);
```

## License

This plugin is licensed under the GPLv2 or later.

## Credits

Developed by FluentCart Team
Mercado Pago API integration

