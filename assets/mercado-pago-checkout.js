class MercadoPagoCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.publicKey = response?.payment_args?.public_key;
        this.locale = response?.payment_args?.locale || 'en';
        this.intentMode = response?.intent?.mode;
        this.mp = null;
        this.bricksBuilder = null;
        this.paymentBrickController = null;
    }

    translate(string) {
        const translations = window.fct_mercadopago_data?.translations || {};
        return translations[string] || string;
    }

    async init() {
        const ref = this;
        let mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        
        if (!mercadoPagoContainer) {
            return;
        }

        mercadoPagoContainer.innerHTML = '';

        // Hide payment methods
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }

        // Load Mercado Pago SDK
        try {
            await this.loadMercadoPagoSDK();
            this.mp = new MercadoPago(this.publicKey, {
                locale: this.locale
            });
            this.bricksBuilder = this.mp.bricks();
        } catch (error) {
            this.showError(this.$t('Failed to load Mercado Pago SDK'));
            return;
        }

        let orderData = null;

        if ('payment' === this.intentMode) {
            await this.onetimePaymentHandler(ref, orderData, mercadoPagoContainer);
        } else if ('subscription' === this.intentMode) {
            await this.subscriptionPaymentHandler(ref, orderData, mercadoPagoContainer);
        }
    }

    async loadMercadoPagoSDK() {
        return new Promise((resolve, reject) => {
            if (window.MercadoPago) {
                resolve(window.MercadoPago);
            } else {
                const script = document.createElement('script');
                script.src = 'https://sdk.mercadopago.com/js/v2';
                script.async = true;
                script.onload = () => resolve(window.MercadoPago);
                script.onerror = () => reject(new Error('Failed to load Mercado Pago SDK'));
                document.head.appendChild(script);
            }
        });
    }

    async onetimePaymentHandler(ref, orderData, mercadoPagoContainer) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';

        // Create container for Payment Brick
        const brickContainer = document.createElement('div');
        brickContainer.id = 'paymentBrick_container';
        mercadoPagoContainer.appendChild(brickContainer);

        const settings = {
            initialization: {
                amount: parseFloat(this.data?.intent?.amount || 0),
            },
            customization: {
                visual: {
                    style: {
                        theme: 'default',
                    },
                },
                paymentMethods: {
                    creditCard: 'all',
                    debitCard: 'all',
                    ticket: 'all',
                    bankTransfer: 'all',
                    maxInstallments: 1
                },
            },
            callbacks: {
                onReady: () => {
                    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                        detail: {
                            payment_method: 'mercado_pago'
                        }
                    }));
                    const loadingElement = document.getElementById('fct_loading_payment_processor');
                    if (loadingElement) {
                        loadingElement.remove();
                    }
                },
                onSubmit: async ({ selectedPaymentMethod, formData }) => {
                    that.paymentLoader?.changeLoaderStatus('processing');
                    
                    return new Promise(async (resolve, reject) => {
                        try {
                            // First, create the order in FluentCart
                            if (typeof ref.orderHandler === 'function') {
                                const response = await ref.orderHandler();
                                if (!response) {
                                    that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                                    that.paymentLoader?.hideLoader();
                                    return reject();
                                }
                                orderData = response;
                            } else {
                                that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                                that.paymentLoader?.hideLoader();
                                return reject();
                            }

                            // Add transaction UUID to formData
                            formData.transaction_id = orderData?.data?.transaction?.uuid;

                            that.paymentLoader?.changeLoaderStatus('confirming');

                            // Process payment via Mercado Pago
                            fetch(`${window.fluentcart_checkout_vars.rest_url}mercadopago/process_payment`, {
                                method: "POST",
                                headers: {
                                    "Content-Type": "application/json",
                                    "X-WP-Nonce": window.fct_mercadopago_data?.nonce,
                                },
                                body: JSON.stringify(formData),
                            })
                            .then(response => response.json())
                            .then(paymentResponse => {
                                if (paymentResponse?.id && paymentResponse?.status === 'approved') {
                                    that.paymentLoader?.changeLoaderStatus('completed');
                                    
                                    // Confirm with FluentCart
                                    const confirmParams = new URLSearchParams({
                                        action: 'fluent_cart_confirm_mercadopago_payment',
                                        payment_id: paymentResponse.id,
                                        ref_id: orderData?.data?.transaction?.uuid,
                                        mode: mode
                                    });

                                    const xhr = new XMLHttpRequest();
                                    xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                                    xhr.onload = function () {
                                        if (xhr.status >= 200 && xhr.status < 300) {
                                            try {
                                                const res = JSON.parse(xhr.responseText);
                                                if (res?.redirect_url) {
                                                    that.paymentLoader.triggerPaymentCompleteEvent(res);
                                                    that.paymentLoader?.changeLoaderStatus('redirecting');
                                                    window.location.href = res.redirect_url;
                                                }
                                            } catch (error) {
                                                console.error('Error parsing response:', error);
                                                reject();
                                            }
                                        } else {
                                            console.error('Network response was not ok');
                                            reject();
                                        }
                                    };

                                    xhr.onerror = function () {
                                        reject();
                                    };

                                    xhr.send(confirmParams.toString());
                                    resolve();
                                } else {
                                    that.showError(that.$t('Payment not approved'));
                                    reject();
                                }
                            })
                            .catch((error) => {
                                console.error('Payment error:', error);
                                that.showError(error?.message || that.$t('Payment failed'));
                                reject();
                            });
                        } catch (error) {
                            console.error('Error:', error);
                            reject();
                        }
                    });
                },
                onError: (error) => {
                    console.error('Payment Brick error:', error);
                    that.showError(that.$t('Something went wrong'));
                },
            },
        };

        try {
            this.paymentBrickController = await this.bricksBuilder.create('payment', 'paymentBrick_container', settings);
        } catch (error) {
            console.error('Error creating Payment Brick:', error);
            this.showError(this.$t('Failed to initialize payment form'));
        }
    }

    async subscriptionPaymentHandler(ref, orderData, mercadoPagoContainer) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';

        // Create container for Payment Brick
        const brickContainer = document.createElement('div');
        brickContainer.id = 'paymentBrick_container';
        mercadoPagoContainer.appendChild(brickContainer);

        const settings = {
            initialization: {
                amount: parseFloat(this.data?.intent?.amount || 0),
            },
            customization: {
                visual: {
                    style: {
                        theme: 'default',
                    },
                },
                paymentMethods: {
                    creditCard: 'all',
                    debitCard: 'all',
                    maxInstallments: 1
                },
            },
            callbacks: {
                onReady: () => {
                    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                        detail: {
                            payment_method: 'mercado_pago'
                        }
                    }));
                    const loadingElement = document.getElementById('fct_loading_payment_processor');
                    if (loadingElement) {
                        loadingElement.remove();
                    }
                },
                onSubmit: async ({ selectedPaymentMethod, formData }) => {
                    that.paymentLoader?.changeLoaderStatus('processing');
                    
                    return new Promise(async (resolve, reject) => {
                        try {
                            // First, create the order in FluentCart
                            if (typeof ref.orderHandler === 'function') {
                                const response = await ref.orderHandler();
                                if (!response) {
                                    that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                                    that.paymentLoader?.hideLoader();
                                    return reject();
                                }
                                orderData = response;
                            } else {
                                that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                                that.paymentLoader?.hideLoader();
                                return reject();
                            }

                            // Add subscription UUID to formData
                            formData.subscription_id = orderData?.data?.subscription?.uuid;
                            formData.transaction_id = orderData?.data?.transaction?.uuid;

                            that.paymentLoader?.changeLoaderStatus('confirming');

                            // Process subscription via Mercado Pago
                            fetch(`${window.fluentcart_checkout_vars.rest_url}mercadopago/create_subscription`, {
                                method: "POST",
                                headers: {
                                    "Content-Type": "application/json",
                                    "X-WP-Nonce": window.fct_mercadopago_data?.nonce,
                                },
                                body: JSON.stringify(formData),
                            })
                            .then(response => response.json())
                            .then(subscriptionResponse => {
                                if (subscriptionResponse?.id && subscriptionResponse?.status === 'authorized') {
                                    that.paymentLoader?.changeLoaderStatus('completed');
                                    
                                    // Confirm with FluentCart
                                    const confirmParams = new URLSearchParams({
                                        action: 'fluent_cart_confirm_mercadopago_subscription',
                                        subscription_id: subscriptionResponse.id,
                                        ref_id: orderData?.data?.transaction?.uuid,
                                        mode: mode
                                    });

                                    const xhr = new XMLHttpRequest();
                                    xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
                                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                                    xhr.onload = function () {
                                        if (xhr.status >= 200 && xhr.status < 300) {
                                            try {
                                                const res = JSON.parse(xhr.responseText);
                                                if (res?.redirect_url) {
                                                    that.paymentLoader.triggerPaymentCompleteEvent(res);
                                                    that.paymentLoader?.changeLoaderStatus('redirecting');
                                                    window.location.href = res.redirect_url;
                                                }
                                            } catch (error) {
                                                console.error('Error parsing response:', error);
                                                reject();
                                            }
                                        } else {
                                            console.error('Network response was not ok');
                                            reject();
                                        }
                                    };

                                    xhr.onerror = function () {
                                        reject();
                                    };

                                    xhr.send(confirmParams.toString());
                                    resolve();
                                } else {
                                    that.showError(that.$t('Subscription not authorized'));
                                    reject();
                                }
                            })
                            .catch((error) => {
                                console.error('Subscription error:', error);
                                that.showError(error?.message || that.$t('Subscription creation failed'));
                                reject();
                            });
                        } catch (error) {
                            console.error('Error:', error);
                            reject();
                        }
                    });
                },
                onError: (error) => {
                    console.error('Payment Brick error:', error);
                    that.showError(that.$t('Something went wrong'));
                },
            },
        };

        try {
            this.paymentBrickController = await this.bricksBuilder.create('payment', 'paymentBrick_container', settings);
        } catch (error) {
            console.error('Error creating Payment Brick:', error);
            this.showError(this.$t('Failed to initialize payment form'));
        }
    }

    showError(message) {
        this.paymentLoader?.changeLoaderStatus('error');
        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton();
        
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.style.marginTop = '10px';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        if (mercadoPagoContainer) {
            // Remove existing error messages
            const existingError = mercadoPagoContainer.querySelector('.fct-error-message');
            if (existingError) {
                existingError.remove();
            }
            mercadoPagoContainer.appendChild(errorDiv);
        }
    }
}

// Listen for FluentCart payment loading event
window.addEventListener("fluent_cart_load_payments_mercado_pago", function (e) {
    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: {
            payment_method: 'mercado_pago'
        }
    }));

    function $t(string) {
        const translations = window.fct_mercadopago_data?.translations || {};
        return translations[string] || string;
    }

    addLoadingText();

    // Fetch payment info from backend (similar to PayPal)
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
            return;
        }
        // Initialize Mercado Pago checkout with fetched data
        new MercadoPagoCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        let message = error?.message || $t('An error occurred while loading Mercado Pago.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        if (mercadoPagoContainer) {
            mercadoPagoContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    function addLoadingText() {
        let mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        if (!mercadoPagoContainer) return;
        
        const loadingMessage = document.createElement('p');
        loadingMessage.id = 'fct_loading_payment_processor';
        loadingMessage.textContent = $t('Loading Payment Processor...');
        loadingMessage.style.textAlign = 'center';
        loadingMessage.style.padding = '20px';
        mercadoPagoContainer.appendChild(loadingMessage);
    }
});
