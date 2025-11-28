class MercadoPagoCheckout {
    #publicKey = null;
    #mp = null;
    #bricksBuilder = null;
    #paymentBrickController = null;

    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#publicKey = response?.payment_args?.public_key;
    }

    init() {
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const that = this;        
        const mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        if (mercadoPagoContainer) {
            mercadoPagoContainer.innerHTML = '';
        }

        this.renderPaymentInfo();

        this.#publicKey = this.data?.payment_args?.public_key;

        window.addEventListener("fluent_cart_payment_next_action_mercado_pago", async(e) => {
            const remoteResponse = e.detail?.response;           
            const paymentData = remoteResponse?.data?.payment_data;
            const amount = remoteResponse?.data?.amount;
            const intent = remoteResponse?.data?.intent;
            const transactionHash = remoteResponse?.data?.transaction_hash;

            if (paymentData && amount) {
                if (intent === 'onetime') {
                    await this.initializeMercadoPago(paymentData, amount, transactionHash);
                } else if (intent === 'subscription') {
                    this.showError(this.$t('Subscription payments are coming soon.'));
                }
            }
        });
    }

    translate(string) {
        const translations = window.fct_mercadopago_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-mercadopago-info">';
        
        // Simple header
        html += '<div class="fct-mercadopago-header">';
        html += '<p class="fct-mercadopago-subheading">' + this.$t('Available payment methods on Checkout') + '</p>';
        html += '</div>';
        
        // Payment methods
        html += '<div class="fct-mercadopago-methods">';
        html += '<div class="fct-mercadopago-method">';
        html += '<span class="fct-method-name">' + this.$t('Cards') + '</span>';
        html += '</div>';
        html += '<div class="fct-mercadopago-method">';
        html += '<span class="fct-method-name">' + this.$t('Pix') + '</span>';
        html += '</div>';
        html += '<div class="fct-mercadopago-method">';
        html += '<span class="fct-method-name">' + this.$t('Boleto') + '</span>';
        html += '</div>';
        html += '</div>';
        
        html += '</div>';
        
        // Add CSS styles
        html += `<style>
            .fct-mercadopago-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 20px;
            }
            
            .fct-mercadopago-header {
                text-align: center;
                margin-bottom: 16px;
            }
            
            .fct-mercadopago-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }
            
            .fct-mercadopago-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 10px;
            }
            
            .fct-mercadopago-method {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                transition: all 0.2s ease;
            }
            
            .fct-method-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
            }

            #mercadoPagoPaymentBrick_container {
                margin-top: 20px;
            }
            
            @media (max-width: 768px) {
                .fct-mercadopago-info {
                    padding: 16px;
                }
                
                .fct-mercadopago-methods {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                
                .fct-mercadopago-method {
                    padding: 8px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        if (container) {
            container.innerHTML = html;
        }
    }

    async initializeMercadoPago(paymentData, amount, transactionHash) {
        try {
            // Initialize MercadoPago SDK
            if (!this.#publicKey) {
                this.showError(this.$t('Public key is missing'));
                return;
            }

            this.#mp = new MercadoPago(this.#publicKey, {
                locale: 'en-US'
            });

            this.#bricksBuilder = this.#mp.bricks();

            // Clear previous content and add payment brick container
            const container = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
            if (container) {
                const infoDivs = container.querySelectorAll('.fct-mercadopago-info');
                infoDivs.forEach(div => div.remove());
                
                // Add brick container if it doesn't exist
                if (!document.getElementById('mercadoPagoPaymentBrick_container')) {
                    const brickContainer = document.createElement('div');
                    brickContainer.id = 'mercadoPagoPaymentBrick_container';
                    container.appendChild(brickContainer);
                }
            }

            // Create Payment Brick
            this.#paymentBrickController = await this.#bricksBuilder.create('payment', 'mercadoPagoPaymentBrick_container', {
                initialization: {
                    amount: amount,
                    payer: {
                        email: paymentData.payer?.email || '',
                        firstName: paymentData.payer?.first_name || '',
                        lastName: paymentData.payer?.last_name || '',
                    },
                },
                customization: {
                    visual: {
                        style: {
                            theme: 'default'
                        }
                    },
                    paymentMethods: {
                        maxInstallments: 12,
                    }
                },
                callbacks: {
                    onReady: () => {
                        this.paymentLoader.hideLoader();
                        this.paymentLoader.enableCheckoutButton(this.$t('Pay Now'));
                    },
                    onSubmit: async (formData) => {
                        return await this.handlePaymentSubmit(formData, paymentData, transactionHash);
                    },
                    onError: (error) => {
                        console.error('Payment Brick error:', error);
                        this.showError(error.message || this.$t('Something went wrong'));
                    }
                }
            });

        } catch (error) {
            console.error('MercadoPago initialization error:', error);
            this.showError(error.message || this.$t('Failed to initialize payment'));
        }
    }

    async handlePaymentSubmit(formData, paymentData, transactionHash) {
        this.paymentLoader.changeLoaderStatus('processing');
        
        try {
            // Merge form data with payment data
            const finalPaymentData = {
                ...paymentData,
                token: formData.token,
                issuer_id: formData.issuer_id,
                payment_method_id: formData.payment_method_id,
                transaction_amount: formData.transaction_amount,
                installments: formData.installments,
                payer: {
                    ...paymentData.payer,
                    email: formData.payer.email,
                    identification: formData.payer.identification,
                }
            };

            // Create payment via backend (secure)
            const paymentResponse = await this.createPayment(finalPaymentData, transactionHash);

            if (paymentResponse.error || !paymentResponse.payment_id) {
                this.showError(paymentResponse.message || this.$t('Payment failed'));
                this.paymentLoader.hideLoader();
                return;
            }

            // Confirm payment with FluentCart
            await this.confirmPayment(paymentResponse.payment_id);

        } catch (error) {
            console.error('Payment submission error:', error);
            this.showError(error.message || this.$t('Payment processing failed'));
            this.paymentLoader.hideLoader();
        }
    }

    async createPayment(paymentData, transactionHash) {
        try {
            // Send payment data to backend to create payment securely
            const params = new URLSearchParams({
                action: 'fluent_cart_create_mercadopago_payment',
                transaction_hash: transactionHash,
                payment_data: JSON.stringify(paymentData),
                mercadopago_fct_nonce: window.fct_mercadopago_data?.nonce
            });

            const response = await fetch(window.fluentcart_checkout_vars.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            });

            return await response.json();
        } catch (error) {
            return {
                error: true,
                message: error.message
            };
        }
    }

    async confirmPayment(paymentId) {
        const params = new URLSearchParams({
            action: 'fluent_cart_confirm_mercadopago_payment',
            payment_id: paymentId,
            mercadopago_fct_nonce: window.fct_mercadopago_data?.nonce
        });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        const that = this;
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res?.redirect_url) {
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus('redirecting');
                        window.location.href = res.redirect_url;
                    } else {
                        that.showError(res?.message || that.$t('Payment confirmation failed'));
                        that.paymentLoader.hideLoader();
                    }
                } catch (error) {
                    that.showError(error.message);
                    that.paymentLoader.hideLoader();
                }
            } else {
                that.showError(that.$t('Network error: ' + xhr.status));
                that.paymentLoader.hideLoader();
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.showError(err?.message);
            } catch (e) {
                console.error('An error occurred:', e);
                that.showError(e.message);
            }
            that.paymentLoader.hideLoader();
        };

        xhr.send(params.toString());
    }

    showError(message) {
        let mercadoPagoContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_mercado_pago');
        
        if (mercadoPagoContainer) {
            // Remove existing error messages
            const existingErrors = mercadoPagoContainer.querySelectorAll('.fct-mercadopago-error');
            existingErrors.forEach(el => el.remove());
            
            const errorDiv = document.createElement('div');
            errorDiv.className = 'fct-mercadopago-error';
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '14px';
            errorDiv.style.padding = '10px';
            errorDiv.style.marginTop = '10px';
            errorDiv.style.backgroundColor = '#f8d7da';
            errorDiv.style.border = '1px solid #f5c6cb';
            errorDiv.style.borderRadius = '4px';
            errorDiv.textContent = message;
            
            mercadoPagoContainer.appendChild(errorDiv);
        }
        
        this.paymentLoader.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
    }
}

window.addEventListener("fluent_cart_load_payments_mercado_pago", function (e) {
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
            displayErrorMessage(response?.message);
            return;
        }
        new MercadoPagoCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_mercadopago_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('An error occurred while loading Mercado Pago.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.style.fontSize = '14px';
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
        if (mercadoPagoContainer) {
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            const translations = window.fct_mercadopago_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            mercadoPagoContainer.appendChild(loadingMessage);
        }
    }
});

