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
        this.statusScreenBrickController = null;
        this.mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        this.isRendering = false;
    }

    translate(string) {
        const translations = window.fct_mercadopago_data?.translations || {};
        return translations[string] || string;
    }

    async init() {
        // if already initialized, unmount it
        if (this.paymentBrickController) {
            await this.safeUnmount();
        }

        const ref = this;

        if (!this.mercadoPagoContainer) {
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

        this.paymentHandler(ref, orderData, this.intentMode);
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

    async paymentHandler(ref, orderData, intentMode) {

        if (this.isRendering) {
            return;
        }

        this.isRendering = true;
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
        this.mercadoPagoContainer.appendChild(brickContainer);

        let paymentMethods = {
            bankTransfer: "all",
            creditCard: "all",
            prepaidCard: "all",
            debitCard: "all",
            mercadoPago: "all",
        };

        // if boletto is enabled, add it to the payment methods
        if (this.data?.payment_args?.boleto_payment_enabled) {
            paymentMethods.ticket = "all";
        }

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
                paymentMethods: paymentMethods,
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
                            that.appendMercadoPagoFormDataToMainForm(formData, selectedPaymentMethod);

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
                                        try {
                                            await that.confirmPayment(response?.data?.payment?.id, orderData?.transaction_hash, orderData?.order_hash);
                                            that.cleanupMercadoPagoFormData();
                                            resolve();
                                        } catch (error) {
                                            console.error('Payment confirmation failed:', error);
                                            that.cleanupMercadoPagoFormData();
                                            return reject(error);
                                        }
                                    } else if (response?.data?.payment?.status === 'pending' || response?.data?.payment?.status === 'in_process') {

                                        // if (response?.data?.redirect_url) {
                                        //     window.location.href = response?.data?.redirect_url;
                                        //     return resolve();
                                        // }

                                       this.renderStatusScreenBrick(response?.data?.payment?.id, orderData?.transaction_hash, orderData?.order_hash);

                                    }
                                    else {
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
                    if (error?.type == 'non_critical') {
                        console.log('Non critical error:', error);
                    } else {
                        that.showError(that.$t('Something went wrong'));
                    }
                    
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
        } finally {
            this.isRendering = false;
        }
    }

    async confirmPayment(paymentId, transactionHash, orderHash) {
        const that = this;
        const confirmParams = new URLSearchParams({
            action: 'fluent_cart_confirm_mercadopago_payment_onetime',
            payment_id: paymentId,
            transaction_hash : transactionHash,
            order_hash : orderHash,
            mercadopago_fct_nonce: window.fct_mercadopago_data?.nonce,
        });
        
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const res = JSON.parse(xhr.responseText);

                        if (res?.status === 'failed') {
                            that.showError(res?.message || that.$t('Payment confirmation failed'));
                            that.paymentLoader?.hideLoader();
                            that.paymentLoader?.enableCheckoutButton();
                            return reject(new Error(res?.message));
                        }
                        
                        if (res?.redirect_url) {
                            that.paymentLoader?.changeLoaderStatus('redirecting');
                            window.location.href = res.redirect_url;
                            return resolve(true);
                        } else {
                            that.showError(res?.message || that.$t('Something went wrong'));
                            that.paymentLoader?.hideLoader();
                            that.paymentLoader?.enableCheckoutButton();
                            return reject(new Error(res?.message || 'No redirect URL'));
                        }
                    } catch (error) {
                        that.showError(that.$t('Something went wrong'));
                        that.paymentLoader?.hideLoader();
                        that.paymentLoader?.enableCheckoutButton();
                        return reject(error);
                    }
                } else {
                    console.log('xhr.status', xhr);
                    that.showError( xhr.statusText || that.$t('Network response was not ok'));
                    that.paymentLoader?.hideLoader();
                    that.paymentLoader?.enableCheckoutButton();
                    return reject(new Error('Network error'));
                }
            };

            xhr.onerror = function (error) {
                console.error('XHR error:', error);
                that.showError(that.$t('Something went wrong'));
                that.paymentLoader?.hideLoader();
                that.paymentLoader?.enableCheckoutButton();
                return reject(error);
            };

            xhr.send(confirmParams.toString());
        });
    }


    async renderStatusScreenBrick(paymentId, transactionHash, orderHash) {

        this.paymentBrickController.unmount();

        const existingStatusScreenBrickContainer = document.getElementById('statusScreenBrick_container');
        if (existingStatusScreenBrickContainer && existingStatusScreenBrickContainer.parentNode) {
            existingStatusScreenBrickContainer.parentNode.removeChild(existingStatusScreenBrickContainer);
        }

        // create status screen brick container
        const statusScreenBrickContainer = document.createElement('div');
        statusScreenBrickContainer.id = 'statusScreenBrick_container';
        this.mercadoPagoContainer.appendChild(statusScreenBrickContainer);

        const settings = {
          initialization: {
            paymentId: paymentId, // payment id to show
          },
          callbacks: {
            onReady: () => {
               const loadingElement = document.getElementById('fct_loading_payment_processor');
               if (loadingElement) {
                loadingElement.remove();
               }

                this.paymentLoader?.hideLoader();

                const donePaymentButton = document.createElement('button');
                donePaymentButton.id = 'mp_fct_done_payment_button';
                donePaymentButton.className = 'mp-payment-done-button';
                donePaymentButton.textContent = this.$t('Confirm After Payment');
                
                // Apply inline styles to ensure they work
                Object.assign(donePaymentButton.style, {
                    display: 'block',
                    maxWidth: '448px',
                    width: '92%',
                    margin: '10px auto',
                    padding: '16px 24px',
                    backgroundColor: '#009ee3',
                    color: '#ffffff',
                    fontSize: '16px',
                    fontWeight: '600',
                    textAlign: 'center',
                    border: 'none',
                    borderRadius: '6px',
                    cursor: 'pointer',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                    transition: 'all 0.2s ease'
                });
                
                // Add hover effect
                donePaymentButton.addEventListener('mouseenter', () => {
                    donePaymentButton.style.backgroundColor = '#0086c3';
                    donePaymentButton.style.transform = 'translateY(-1px)';
                    donePaymentButton.style.boxShadow = '0 4px 8px rgba(0,0,0,0.15)';
                });
                
                donePaymentButton.addEventListener('mouseleave', () => {
                    donePaymentButton.style.backgroundColor = '#009ee3';
                    donePaymentButton.style.transform = 'translateY(0)';
                    donePaymentButton.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                });
                
                donePaymentButton.addEventListener('click', () => {
                    this.confirmPayment(paymentId, transactionHash, orderHash);
                });
                
                statusScreenBrickContainer.appendChild(donePaymentButton);

            },
            onError: (error) => {
              this.showError(this.$t('Something went wrong'));
            },
          },
         };

         window.statusScreenBrickController = await this.bricksBuilder.create(
          'statusScreen',
          'statusScreenBrick_container',
          settings,
         ); 

    };


    appendMercadoPagoFormDataToMainForm(formData, selectedPaymentMethod) {
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

        const input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'mp_selected_payment_method';
        input2.value = selectedPaymentMethod;
        input2.className = 'fct-mercadopago-form-data';
        this.form.appendChild(input2);

        this.mercadoPagoSelectedPaymentMethodField = input2;
    }


    cleanupMercadoPagoFormData() {
        // remove error message if it exists
        const existingError = this.mercadoPagoContainer.querySelector('.fct-error-message');
        if (existingError) {
            existingError.remove();
        }

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

        if (this.mercadoPagoContainer) {
            const existingError = this.mercadoPagoContainer.querySelector('.fct-error-message');
            if (existingError) {
                existingError.remove();
            }
            this.mercadoPagoContainer.appendChild(errorDiv);
        }
    }

    async safeUnmount() {
        try {
            if (this.paymentBrickController) {
                await this.paymentBrickController.unmount();
            }
        } catch (e) {
            console.warn(e);
        } finally {
            this.paymentBrickController = null;
        }
    }
}

window.mercadopagoCheckoutInstance = null;

window.addEventListener("fluent_cart_load_payments_mercado_pago", function (e) {
    if (window.mercadopagoCheckoutInstance && typeof window.mercadopagoCheckoutInstance.unmount === 'function' && window.mercadopagoCheckoutInstance.mercadoPagoContainer) {
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

        if (this.mercadoPagoContainer) {
            this.mercadoPagoContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    function addLoadingText() {
        if (!this.mercadoPagoContainer) return;

        const loadingMessage = document.createElement('p');
        loadingMessage.id = 'fct_loading_payment_processor';
        loadingMessage.textContent = $t('Loading Payment Processor...');
        loadingMessage.style.textAlign = 'center';
        loadingMessage.style.padding = '20px';
        mercadoPagoContainer.appendChild(loadingMessage);
    }
});
