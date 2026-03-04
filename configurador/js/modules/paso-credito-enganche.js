/* ==========================================================================
   Voltika - Crédito: Pago de Enganche via Stripe
   Down payment for credit flow — reuses Stripe integration pattern from paso4a
   ========================================================================== */

var PasoCreditoEnganche = {

    _stripe: null,
    _elements: null,
    _cardElement: null,

    STRIPE_PUBLISHABLE_KEY: 'pk_test_51Rr5XCDPx1FQbvVSr8odW16SQzUgPoyMJroHp5emN9PttKU4oHs0jAOsBpxM50ISn4xmUzemZomXX6IuIrQN8FRB00NM2T7eGp',
    PAYMENT_INTENT_URL: 'php/create-payment-intent.php',
    ORDER_CONFIRM_URL:  'php/confirmar-orden.php',

    init: function(app) {
        this.app = app;
        this._stripe = null;
        this._cardElement = null;
        this.render();
        this.bindEvents();
        var self = this;
        setTimeout(function() { self._mountStripe(); }, 300);
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);
        var enganche    = credito.enganche;

        var html = '';

        html += VkUI.renderBackButton('resumen');

        html += '<h2 class="vk-paso__titulo">Pago de enganche</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:20px;">';

        // Enganche summary
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Enganche (' +
            Math.round(enganchePct * 100) + '%) para tu</div>';
        html += '<div style="font-size:18px;font-weight:700;margin:4px 0;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:28px;font-weight:800;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(enganche) + ' MXN</div>';
        html += '</div>';

        // Credit details box
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Precio contado</span>';
        html += '<span>' + VkUI.formatPrecio(modelo.precioContado) + '</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Monto financiado</span>';
        html += '<span>' + VkUI.formatPrecio(credito.montoFinanciado) + '</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Plazo</span>';
        html += '<span>' + (state.plazoMeses || 12) + ' meses</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;">';
        html += '<span style="color:var(--vk-text-secondary);">Pago semanal</span>';
        html += '<span style="font-weight:700;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(credito.pagoSemanal) + '</span>';
        html += '</div>';
        html += '</div>';

        // Card logos
        html += '<div style="text-align:center;margin-bottom:12px;">' +
            VkUI.renderCardLogos() + '</div>';

        // Stripe card element
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">&#128179; Datos de tarjeta</label>';
        html += '<div id="vk-enganche-card-element" style="border:1.5px solid var(--vk-border);' +
            'border-radius:6px;padding:14px;background:#FAFAFA;min-height:46px;"></div>';
        html += '<div id="vk-enganche-card-errors" style="color:#C62828;font-size:12px;' +
            'margin-top:6px;display:none;"></div>';
        html += '</div>';

        // Error
        html += '<div id="vk-enganche-error" style="display:none;color:#C62828;background:#FFEBEE;' +
            'border:1px solid #E53935;border-radius:6px;padding:12px;margin-top:12px;font-size:13px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-pagar">';
        html += '<span class="vk-pay-btn__label">&#128274; PAGAR ENGANCHE ' +
            VkUI.formatPrecio(enganche) + ' MXN</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' +
            VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:10px;">' +
            '&#128274; Pago cifrado SSL &middot; ' + VkUI.renderCardLogos() + '</div>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-enganche-container').html(html);
    },

    _mountStripe: function() {
        var self = this;

        if (typeof Stripe === 'undefined') {
            jQuery('#vk-enganche-card-element').html(
                '<p style="color:#C62828;font-size:13px;">&#9888; Error: Stripe.js no disponible.</p>'
            );
            return;
        }

        if (self._stripe) return;

        self._stripe   = Stripe(self.STRIPE_PUBLISHABLE_KEY);
        self._elements = self._stripe.elements({ locale: 'es' });

        self._cardElement = self._elements.create('card', {
            style: {
                base: {
                    fontFamily: 'Inter, Arial, sans-serif',
                    fontSize: '16px',
                    color: '#111827',
                    '::placeholder': { color: '#9CA3AF' }
                },
                invalid: { color: '#C62828' }
            },
            hidePostalCode: true
        });

        self._cardElement.mount('#vk-enganche-card-element');

        self._cardElement.on('change', function(event) {
            var $err = jQuery('#vk-enganche-card-errors');
            if (event.error) {
                $err.text(event.error.message).show();
            } else {
                $err.text('').hide();
            }
        });
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-enganche-pagar');
        jQuery(document).on('click', '#vk-enganche-pagar', function(e) {
            e.preventDefault();
            self._handlePayment();
        });
    },

    _handlePayment: function() {
        var self  = this;
        var state = self.app.state;

        if (!self._stripe || !self._cardElement) {
            alert('El módulo de pago no está listo. Recarga la página.');
            return;
        }

        self._setLoading(true);
        jQuery('#vk-enganche-error').hide();

        var modelo      = self.app.getModelo(state.modeloSeleccionado);
        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);
        var enganche    = credito.enganche;
        var amountCents = Math.round(enganche * 100);

        var customerData = {
            nombre:   state.nombre,
            email:    state.email,
            telefono: state.telefono,
            modelo:   modelo.nombre,
            color:    state.colorSeleccionado || modelo.colorDefault,
            ciudad:   state.ciudad  || '',
            estado:   state.estado  || '',
            cp:       state.codigoPostal || ''
        };

        jQuery.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                amount:       amountCents,
                method:       'card',
                installments: false,
                msiMeses:     0,
                customer:     customerData,
                tipo:         'enganche'
            }),
            success: function(response) {
                if (!response.clientSecret) {
                    self._showError('Error al iniciar el pago. Intenta de nuevo.');
                    self._setLoading(false);
                    return;
                }

                self._stripe.confirmCardPayment(response.clientSecret, {
                    payment_method: {
                        card: self._cardElement,
                        billing_details: {
                            name:  customerData.nombre,
                            email: customerData.email,
                            phone: '+52' + customerData.telefono
                        }
                    }
                }).then(function(result) {
                    if (result.error) {
                        self._showError(result.error.message);
                        self._setLoading(false);
                    } else if (result.paymentIntent.status === 'succeeded') {
                        self._confirmarEnganche(customerData, modelo, result.paymentIntent.id, enganche);
                    }
                });
            },
            error: function() {
                self._showError('Error de conexión. Verifica tu internet.');
                self._setLoading(false);
            }
        });
    },

    _confirmarEnganche: function(customerData, modelo, paymentIntentId, enganche) {
        var self = this;

        self.app.state.totalPagado = enganche;
        self.app.state.enganchePagado = true;

        jQuery.ajax({
            url: self.ORDER_CONFIRM_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                paymentIntentId: paymentIntentId,
                pagoTipo:  'enganche',
                nombre:    customerData.nombre,
                email:     customerData.email,
                telefono:  customerData.telefono,
                modelo:    customerData.modelo,
                color:     customerData.color,
                ciudad:    customerData.ciudad,
                estado:    customerData.estado,
                cp:        customerData.cp,
                total:     enganche,
                metodoPago: 'credito',
                enganchePct: self.app.state.enganchePorcentaje,
                plazoMeses:  self.app.state.plazoMeses
            }),
            complete: function() {
                self._setLoading(false);
                self.app.irAPaso('credito-contrato');
            }
        });
    },

    _showError: function(msg) {
        jQuery('#vk-enganche-error').text(msg).slideDown(200);
    },

    _setLoading: function(isLoading) {
        var $btn = jQuery('#vk-enganche-pagar');
        if (isLoading) {
            $btn.prop('disabled', true);
            $btn.find('.vk-pay-btn__label').hide();
            $btn.find('.vk-pay-btn__spinner').show();
        } else {
            $btn.prop('disabled', false);
            $btn.find('.vk-pay-btn__label').show();
            $btn.find('.vk-pay-btn__spinner').hide();
        }
    }
};
