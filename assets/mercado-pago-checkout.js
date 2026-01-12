class MercadoPagoCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.publicKey = response?.payment_args?.public_key;
        this.locale = response?.payment_args?.locale || 'en_US';
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

        // Remove existing brick container if it exists
        const existingBrickContainer = document.getElementById('paymentBrick_container');
        if (existingBrickContainer && existingBrickContainer.parentNode) {
            existingBrickContainer.parentNode.removeChild(existingBrickContainer);
        }

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

                                orderData = response?.data?.order_data;

                                if (response?.status === 'failed') {
                                    that.showError(response?.message);
                                    that.cleanupMercadoPagoFormData();
                                    return reject();
                                } else {

                                    if (response?.data?.payment?.status === 'approved' || response?.data?.payment?.status === 'authorized') {
                                        const confirmParams = new URLSearchParams({
                                            action: 'fluent_cart_confirm_mercadopago_payment_onetime',
                                            payment_id: response.data?.payment?.id,
                                            transaction_hash : orderData?.transaction_hash,
                                            order_hash : orderData?.order_hash,
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
                                        }

                                        xhr.send(confirmParams.toString());
                                        
                                        that.cleanupMercadoPagoFormData();
                                        resolve();
                                    } else {
                                        that.showError(that.$t('Payment not approved, Try again!'));
                                    }
                                }
                            } else {
                                that.paymentLoader?.changeLoaderStatus(that.$t('Not proper order handler'));
                                that.paymentLoader?.hideLoader();
                                that.cleanupMercadoPagoFormData();
                                return reject();
                            }

                            that.paymentLoader?.changeLoaderStatus('confirming');
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
            this.paymentBrickController = await this.bricksBuilder.create('payment', 'paymentBrick_container', settings);
            window.paymentBrickController = this.paymentBrickController;
    
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: {
                    payment_method: 'mercado_pago'
                }
            }));

            if (!this.paymentBrickController) {
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
        this.cleanupMercadoPagoFormData();

        if (!formData || typeof formData !== 'object') {
            return;
        }

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mp_form_data';
        input.value = JSON.stringify(formData);
        input.className = 'fct-mercadopago-form-data';
        this.form.appendChild(input);

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

    unmount() {
        // Unmount the payment brick controller if it exists
        if (this.paymentBrickController && typeof this.paymentBrickController.unmount === 'function') {
            try {
                this.paymentBrickController.unmount();
            } catch (error) {
                console.warn('Error unmounting MercadoPago payment brick:', error);
            }
            this.paymentBrickController = null;
        }

        // Also cleanup global reference
        if (window.paymentBrickController && typeof window.paymentBrickController.unmount === 'function') {
            try {
                window.paymentBrickController.unmount();
            } catch (error) {
                console.warn('Error unmounting global MercadoPago payment brick:', error);
            }
            window.paymentBrickController = null;
        }

        // Remove the brick container
        const brickContainer = document.getElementById('paymentBrick_container');
        if (brickContainer && brickContainer.parentNode) {
            brickContainer.parentNode.removeChild(brickContainer);
        }

        // Cleanup form data
        this.cleanupMercadoPagoFormData();

        // Reset references
        this.mp = null;
        this.bricksBuilder = null;
    }
}

// Store global instance reference for cleanup
window.mercadopagoCheckoutInstance = null;

// Listen for FluentCart payment loading event
window.addEventListener("fluent_cart_load_payments_mercado_pago", function (e) {
    if (window.mercadopagoCheckoutInstance && typeof window.mercadopagoCheckoutInstance.unmount === 'function') {

        // console.log('Unmounting MercadoPago checkout instance');

        window.mercadopagoCheckoutInstance.unmount();
        window.mercadopagoCheckoutInstance = null;

        
    }

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
        const checkoutInstance = new MercadoPagoCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader);
        window.mercadopagoCheckoutInstance = checkoutInstance;
        checkoutInstance.init();
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
