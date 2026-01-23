# Mercado Pago for FluentCart - Merchant Guide

Accept payments in Latin America using Mercado Pago with FluentCart. Support for cards, Pix, Boleto, and automatic refunds.

---

## Prerequisites

Before installing Mercado Pago for FluentCart, ensure you have:

- **FluentCart**: Version 1.2.5 or higher (must be installed and active)
- **WordPress**: Version 5.6 or higher
- **PHP**: Version 7.4 or higher
- **Supported Currency**: Your store must use one of these currencies:
  - 🇦🇷 ARS (Argentina Peso)
  - 🇧🇷 BRL (Brazilian Real)
  - 🇨🇱 CLP (Chilean Peso)
  - 🇲🇽 MXN (Mexican Peso)
  - 🇨🇴 COP (Colombian Peso)
  - 🇵🇪 PEN (Peruvian Sol)
  - 🇺🇾 UYU (Uruguayan Peso)
  - 🇺🇸 USD (US Dollar)

---

## Step 1: Install & Activate the Addon

The Mercado Pago addon can be installed directly from FluentCart:

1. Go to **FluentCart** → **Settings** → **Payment Methods**
2. Find **Mercado Pago** in the payment methods list
3. Click on **Mercado Pago** to open its settings
4. Click the **Activate Addon** button
5. The addon will be automatically downloaded and installed
6. Once installation is complete, toggle the **Payment Activation** switch to enable it

> **Note**: The plugin requires FluentCart version 1.2.5 or higher to function properly.

---

## Step 2: Get Your Mercado Pago Credentials

You'll need credentials from your Mercado Pago account. Standard Mercado Pago app credentials work with FluentCart's Checkout Bricks integration.

### Creating Your Mercado Pago Application:

1. Log in to your [Mercado Pago Developer Dashboard](https://www.mercadopago.com/developers/panel/app)
2. Go to **Your integrations**
3. If you have an existing application, you can use it. If not:
   - Click **Create application**
   - Enter an application name (e.g., "My Store - FluentCart")
   - Complete the application setup
4. Select your application from the list

> **Note**: You can use the same Mercado Pago application credentials for FluentCart. There's no need to create a separate app specifically for this integration.

### For Test Mode (Testing before going live):

1. In your application, navigate to the **Credentials** section
2. Copy the following **Test Credentials**:
   - **Test Public Key** (starts with `TEST-`)
   - **Test Access Token** (starts with `TEST-`)

### For Live Mode (Production):

1. In the same **Credentials** section of your application
2. Switch to **Production credentials**
3. Copy the following **Production Credentials**:
   - **Live Public Key** (starts with `APP_USR-`)
   - **Live Access Token** (starts with `APP_USR-`)

> **Important**: Keep your Access Tokens secure - never share them publicly. Treat them like passwords.

---

## Step 3: Configure Plugin Settings

1. Go to **FluentCart** → **Settings** → **Payment Methods** → **Mercado Pago**
2. Select the appropriate tab:
   - **Test credentials** tab for testing
   - **Live credentials** tab for production

### Test Mode Configuration:
- **Test Public Key**: Paste your Test Public Key
- **Test Access Token**: Paste your Test Access Token
- Leave **Test Webhook Secret** empty for now (we'll get it in Step 4)

### Live Mode Configuration:
- **Live Public Key**: Paste your Live Public Key
- **Live Access Token**: Paste your Live Access Token
- Leave **Live Webhook Secret** empty for now (we'll get it in Step 4)

### Additional Settings:
- **Enable Boleto Payment**: Check this to allow customers in Brazil to pay via Boleto (bank slip)

3. Click **Save Settings**

---

## Step 4: Setup Webhook (Critical)

Webhooks enable automatic payment confirmations, refunds, and subscription updates. **This step is mandatory for the plugin to work correctly.**

### Get Your Webhook URL:

Your unique webhook URL is shown in the plugin settings. It looks like:
```
https://yoursite.com/?fluent-cart=fct_payment_listener_ipn&method=mercado_pago
```

### Configure in Mercado Pago:

1. Go to [Mercado Pago Developer Dashboard](https://www.mercadopago.com/developers/panel/app)
2. Navigate to **Your integrations** → Select your application → **Webhooks**
3. Enter your webhook URL in the appropriate field:
   - Use **Test mode URL** if testing
   - Use **Production mode URL** for live transactions
4. Select these event types:
   - ✅ **Payments**
   - ✅ **Orders**
   - ✅ **Plans and Subscriptions**
5. Click **Save**
6. Mercado Pago will generate a **Secret Signature** - **copy it immediately**

### Add Secret to FluentCart:

1. Return to **FluentCart** → **Settings** → **Payment Methods** → **Mercado Pago**
2. Paste the secret signature into:
   - **Test Webhook Secret** (if testing)
   - **Live Webhook Secret** (if in production)
3. Click **Save Settings**

> **Security Note**: The webhook secret verifies that payment notifications are genuinely from Mercado Pago. Without it, webhooks will be rejected.

---

## Step 5: Test Your Integration

Before accepting real payments, thoroughly test the integration:

1. **Switch to Test Mode**:
   - In FluentCart settings, ensure your store is in **Test Mode**
   - Verify you've entered **Test credentials** in Mercado Pago settings

2. **Create a Test Order**:
   - Add a product to cart on your site
   - Proceed to checkout
   - Select **Mercado Pago** as payment method

3. **Use Test Cards**:
   - Use [Mercado Pago test cards](https://www.mercadopago.com/developers/en/docs/checkout-api/testing)
   - Test different scenarios:
     - ✅ Successful payment
     - ❌ Declined payment
     - ⏸️ Pending payment

4. **Verify Order Status**:
   - Check FluentCart orders dashboard
   - Confirm order status updates correctly
   - Test refund functionality from order details

5. **Test Webhook**:
   - Verify webhooks are being received in your FluentCart logs
   - Confirm payment status changes are reflected in orders

> **Recommended**: Test at least 3-5 successful transactions before going live.

### Test Environment Limitations

⚠️ **Important**: Mercado Pago's test environment may not fully replicate all real-world scenarios. Some payment methods, webhook behaviors, or edge cases might behave differently in test mode.

**Alternative Testing Approach**: If you encounter issues with test mode or want to verify real-world behavior:
1. Switch to **Live credentials**
2. Make a small real transaction (e.g., $0.10 or equivalent)
3. Verify the payment processes correctly
4. Process a **refund** immediately from FluentCart
5. Confirm the refund works as expected

This approach gives you confidence that your integration works correctly with real Mercado Pago transactions before accepting actual customer payments.

---

## Step 6: Go Live

Once testing is complete and everything works as expected (or if you've tested with small live transactions per Step 5):

1. **Add Live Credentials**:
   - Go to **FluentCart** → **Settings** → **Payment Methods** → **Mercado Pago**
   - Switch to the **Live credentials** tab
   - Enter your **Live Public Key**, **Live Access Token**, and **Live Webhook Secret**
   - Click **Save Settings**

2. **Configure Live Webhook**:
   - Follow Step 4 again, but use the **Production mode URL** field in Mercado Pago
   - Copy the new live webhook secret and save it in FluentCart

3. **Switch Store to Live Mode**:
   - In FluentCart global settings, change your store from **Test Mode** to **Live Mode**

4. **Verify Live Payment**:
   - Make a small real transaction to confirm everything works
   - Check that the order appears correctly in FluentCart
   - Verify you see the payment in your Mercado Pago account

---

## Payment Methods Available to Customers

Once configured, customers can pay using:

- **Credit/Debit Cards**: All major cards accepted in your region
- **Pix** (Brazil): Instant bank transfers
- **Boleto** (Brazil): Bank slip payments (if enabled)
- Additional local payment methods based on customer's country

The checkout experience is seamless with Mercado Pago's embedded payment form.

---

## Refunds

Refunds are processed automatically through FluentCart:

1. Go to **FluentCart** → **Orders** → Select an order
2. Click **Refund** button
3. Enter refund amount (full or partial)
4. Confirm refund

The refund will be processed in Mercado Pago, and the customer will receive their money according to Mercado Pago's refund timeline.

---

## Troubleshooting

### "FluentCart Required" Error
- Ensure FluentCart is installed and activated
- Check that FluentCart version is 1.2.5 or higher

### "Currency Not Supported" Error
- Verify your store currency is one of the supported currencies (see Prerequisites)
- Change your store currency in FluentCart settings if needed

### Payments Not Processing
- Double-check your credentials are correct
- Ensure you're using Test credentials in Test mode, Live credentials in Live mode
- Verify the webhook is configured correctly with the secret

### Webhook Not Working
- Confirm the webhook URL is correctly entered in Mercado Pago
- Check that the webhook secret matches between Mercado Pago and FluentCart
- Verify all three event types (Payments, Orders, Subscriptions) are selected
- Check your server allows incoming requests from Mercado Pago

### Orders Stuck in Pending
- This usually means webhook is not configured or not working
- Review Step 4 and ensure webhook setup is complete
- Check webhook secret is correctly saved

---

## Support

For issues specific to:
- **Mercado Pago API**: Contact [Mercado Pago Support](https://www.mercadopago.com/developers/en/support)
- **FluentCart or Plugin Issues**: Visit [FluentCart Documentation](https://fluentcart.com/docs) or contact FluentCart support

---

## Notes

- **Subscriptions**: Currently not supported (coming soon)
- **Wallet Payments**: Currently disabled (coming soon)
- Plugin version: 1.0.0
- Keep your plugin updated for the latest features and security patches
