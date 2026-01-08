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

        // Load Mercado Pago SDK
        try {
            await this.loadMercadoPagoSDK();
            this.mp = new MercadoPago(this.publicKey, {
                locale: 'en_US'
            });
            this.bricksBuilder = this.mp.bricks();
        } catch (error) {
            this.showError(this.$t('Failed to load Mercado Pago SDK'));
            return;
        }

        let orderData = null;

        this.paymentHandler(ref, orderData, mercadoPagoContainer, this.intentMode);
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

    async paymentHandler(ref, orderData, mercadoPagoContainer, intentMode) {
        const that = this;
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode') || 'order';

        // Create container for Payment Brick
        const brickContainer = document.createElement('div');
        brickContainer.id = 'paymentBrick_container';
        mercadoPagoContainer.appendChild(brickContainer);

        const settings = {
            initialization: {
                amount: Number(this.data?.intent?.amount || 0),
                preferenceId: this.data?.payment_args?.preference_id || '',
            },
            customization: {
                visual: {
                    style: {
                        theme: 'default',
                    },
                },
                paymentMethods: {
                    ticket: "all",
                    bankTransfer: "all",
                    creditCard: "all",
                    prepaidCard: "all",
                    debitCard: "all",
                    mercadoPago: "all",
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
                            // Append Mercado Pago formData to the main FluentCart form
                            // This makes it available in the backend via orderHandler
                            that.appendMercadoPagoFormDataToMainForm(formData);

                            // First, create the order in FluentCart
                            if (typeof ref.orderHandler === 'function') {
                                const response = await ref.orderHandler();
                                if (!response) {
                                    that.paymentLoader?.changeLoaderStatus(that.$t('Order creation failed'));
                                    that.paymentLoader?.hideLoader();
                                    that.cleanupMercadoPagoFormData();
                                    return reject();
                                }
                                orderData = response;
                            } else {
                                that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                                that.paymentLoader?.hideLoader();
                                that.cleanupMercadoPagoFormData();
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
                                        that.cleanupMercadoPagoFormData();
                                        resolve();
                                    } else {
                                        that.showError(that.$t('Payment not approved'));
                                        that.cleanupMercadoPagoFormData();
                                        reject();
                                    }
                                })
                                .catch((error) => {
                                    console.error('Payment error:', error);
                                    that.showError(error?.message || that.$t('Payment failed'));
                                    that.cleanupMercadoPagoFormData();
                                    reject();
                                });
                        } catch (error) {
                            console.error('Error:', error);
                            that.cleanupMercadoPagoFormData();
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
            window.paymentBrickController = await this.bricksBuilder.create('payment', 'paymentBrick_container', settings);
    
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'mercado_pago'
                }
            }));

            if (!window.paymentBrickController) {
                that.showError(that.$t('Failed to initialize mercado pago payment form'));
                return;
            }

        } catch (error) {
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'mercado_pago'
                }
            }));
            that.showError(that.$t('Failed to initialize mercado pago payment form'));
            return;
        }
    }

    appendMercadoPagoFormDataToMainForm(formData) {
        // Remove any existing Mercado Pago form data field first
        this.cleanupMercadoPagoFormData();

        if (!formData || typeof formData !== 'object') {
            return;
        }

        // Create a single hidden field with JSON stringified formData
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mp_form_data';
        input.value = JSON.stringify(formData);
        input.className = 'fct-mercadopago-form-data';
        this.form.appendChild(input);

        // Store reference for cleanup
        this.mercadoPagoFormField = input;
    }


    cleanupMercadoPagoFormData() {
        // Remove the stored field reference
        if (this.mercadoPagoFormField && this.mercadoPagoFormField.parentNode) {
            this.mercadoPagoFormField.parentNode.removeChild(this.mercadoPagoFormField);
            this.mercadoPagoFormField = null;
        }

        // Also remove any fields that might have been left behind
        const existingFields = this.form.querySelectorAll('.fct-mercadopago-form-data');
        existingFields.forEach(field => {
            if (field && field.parentNode) {
                field.parentNode.removeChild(field);
            }
        });
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
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'mercado_pago'
                }
            }));

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
