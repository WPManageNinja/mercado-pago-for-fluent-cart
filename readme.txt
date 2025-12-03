=== Mercado Pago for FluentCart ===
Contributors: fluentcart, akmelias
Tags: mercado pago, payment gateway, fluent cart, ecommerce, payments
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via Mercado Pago in FluentCart - supports one-time payments, subscriptions, and automatic refunds.

== Description ==

Mercado Pago for FluentCart is a powerful payment gateway addon that seamlessly integrates Mercado Pago's payment processing capabilities with your FluentCart store. Accept payments from customers across Latin America with multiple payment methods.

= Features =

* **One-Time Payments** - Accept single payments for products and services
* **Inline Payment Elements** - Secure checkout using Mercado Pago Checkout Bricks
* **Multiple Payment Methods** - Cards, Pix, Boleto, OXXO, and more
* **Automatic Refunds** - Process refunds directly from FluentCart
* **Webhook Support** - Real-time payment notifications
* **Test & Live Modes** - Test thoroughly before going live
* **Multi-Currency Support** - ARS, BRL, CLP, MXN, COP, PEN, UYU, USD

= Supported Payment Methods =

* Credit and Debit Cards (Visa, Mastercard, American Express, etc.)
* Pix (Brazil)
* Boleto Bancário (Brazil)
* OXXO (Mexico)
* PSE (Colombia)
* And many more regional payment methods

= Supported Countries =

* 🇦🇷 Argentina
* 🇧🇷 Brazil
* 🇨🇱 Chile
* 🇲🇽 Mexico
* 🇨🇴 Colombia
* 🇵🇪 Peru
* 🇺🇾 Uruguay

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/mercado-pago-for-fluent-cart` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to FluentCart > Settings > Payment Methods
4. Find "Mercado Pago" and click "Configure"
5. Enter your Mercado Pago API credentials (get them from https://www.mercadopago.com/developers)
6. Configure webhook URL in your Mercado Pago dashboard
7. Save settings and test your integration

== Frequently Asked Questions ==

= Do I need a Mercado Pago account? =

Yes, you need a Mercado Pago account to use this plugin. You can sign up at https://www.mercadopago.com

= Where do I get my API credentials? =

Log in to your Mercado Pago account and go to Developers > Your integrations. Create or select an application to get your Public Key and Access Token.

= Does this support subscriptions? =

Subscription support is coming in a future update. Currently, the plugin supports one-time payments.

= Can I test before going live? =

Yes! Use your Test credentials to test payments before switching to Live mode. Use Mercado Pago's test cards for testing.

= What currencies are supported? =

Supported currencies include: ARS (Argentina Peso), BRL (Brazilian Real), CLP (Chilean Peso), MXN (Mexican Peso), COP (Colombian Peso), PEN (Peruvian Sol), UYU (Uruguayan Peso), and USD.

= How do refunds work? =

You can process refunds directly from FluentCart. Go to Orders, select the order, and click "Process Refund". The refund will be automatically processed through Mercado Pago's API.

== Screenshots ==

1. Mercado Pago payment method in FluentCart checkout
2. Admin settings panel for Mercado Pago configuration
3. Inline payment form with card details
4. Multiple payment methods available

== Changelog ==

= 1.0.0 =
* Initial release
* One-time payment support
* Inline payment elements (Checkout Bricks)
* Refund support
* Webhook integration
* Multi-currency support
* Test and Live modes
* Support for multiple payment methods

== Upgrade Notice ==

= 1.0.0 =
Initial release of Mercado Pago for FluentCart.

== Additional Info ==

For more information, visit:
* [FluentCart Website](https://fluentcart.com)
* [Mercado Pago Documentation](https://www.mercadopago.com/developers)
* [Support](https://fluentcart.com/support)

