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

        var base        = window.VK_BASE_PATH || '';
        var enganchePct = state.enganchePorcentaje || 0.30;
        var plazoMeses  = state.plazoMeses || 36;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, plazoMeses);
        var enganche    = credito.enganche;
        var colorId     = state.colorSeleccionado || modelo.colorDefault;
        var colorNombre = '';
        for (var i = 0; i < modelo.colores.length; i++) {
            if (modelo.colores[i].id === colorId) { colorNombre = modelo.colores[i].nombre; break; }
        }
        var motoImg     = VkUI.getImagenMoto(modelo.id, colorId);
        var plazoSemanas = Math.round(plazoMeses * 4.33);

        // Estimated delivery date (~5 months from now)
        var entrega = new Date();
        entrega.setMonth(entrega.getMonth() + 5);
        var mesesNombres = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        var entregaStr = entrega.getDate() + ' ' + mesesNombres[entrega.getMonth()] + ' ' + entrega.getFullYear();

        var html = '';

        html += VkUI.renderBackButton('resumen');

        // Header
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:4px;">Tu Voltika est\u00e1 lista</h2>';
        html += '<p style="font-size:14px;color:var(--vk-text-secondary);margin:0;">Paga tu <strong>enganche</strong> para reservarla.</p>';
        html += '</div>';

        // Model summary card (dark background)
        html += '<div style="background:#1e1e2f;border-radius:14px;padding:16px;margin-bottom:20px;color:#fff;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<img src="' + base + motoImg + '" alt="Voltika ' + modelo.nombre + '" ' +
            'style="width:110px;height:auto;flex-shrink:0;">';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-size:16px;font-weight:800;margin-bottom:6px;">Voltika ' + modelo.nombre + '</div>';
        html += '<div style="font-size:12px;color:#ccc;line-height:1.7;">';
        html += 'Modelo: <strong style="color:#fff;">Voltika ' + modelo.nombre + '</strong><br>';
        html += '&#8226; Color: <strong style="color:#fff;">' + (colorNombre || colorId) + '</strong><br>';
        html += '&#8226; Pago semanal: <strong style="color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong> MXN<br>';
        html += '&#8226; Plazo: <strong style="color:#fff;">' + plazoSemanas + ' semanas</strong><br>';
        html += 'Entrega estimada<br><strong style="color:#fff;font-size:14px;">' + entregaStr + '</strong>';
        html += '</div>';
        html += '<div style="font-size:11px;color:#aaa;margin-top:6px;">En un punto autorizado <strong style="color:#fff;">Voltika</strong> cerca de ti</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Enganche amount
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);letter-spacing:0.5px;text-transform:uppercase;">Enganche a pagar</div>';
        html += '<div style="font-size:34px;font-weight:800;color:var(--vk-green-primary);margin-top:4px;">' +
            VkUI.formatPrecio(enganche) + ' MXN</div>';
        html += '</div>';

        // Payment methods section
        html += '<div style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin-bottom:12px;">Selecciona el m\u00e9todo de pago</div>';

        // === 1. Tarjeta de crédito / débito ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">';
        html += '<span style="font-size:14px;font-weight:600;">Tarjeta de cr\u00e9dito / d\u00e9bito</span>';
        html += '<span style="margin-left:auto;">' + VkUI.renderCardLogos() + '</span>';
        html += '</div>';

        // Stripe card element
        html += '<div class="vk-form-group" style="margin-bottom:12px;">';
        html += '<div id="vk-enganche-card-element" style="border:1.5px solid var(--vk-border);' +
            'border-radius:6px;padding:14px;background:#FAFAFA;min-height:46px;"></div>';
        html += '<div id="vk-enganche-card-errors" style="color:#C62828;font-size:12px;' +
            'margin-top:6px;display:none;"></div>';
        html += '</div>';

        // Error
        html += '<div id="vk-enganche-error" style="display:none;color:#C62828;background:#FFEBEE;' +
            'border:1px solid #E53935;border-radius:6px;padding:12px;margin-bottom:12px;font-size:13px;"></div>';

        // CTA Tarjeta
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-pagar">';
        html += '<span class="vk-pay-btn__label">PAGAR CON TARJETA</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' +
            VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';
        html += '</div>';

        // === 2. Transferencia bancaria SPEI ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">';
        html += '<span style="font-size:14px;font-weight:600;">Transferencia bancaria SPEI</span>';
        html += '</div>';
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-spei" style="background:#1a3a5c;">PAGAR POR TRANSFERENCIA SPEI</button>';
        html += '</div>';

        // === 3. Pago en efectivo en tiendas OXXO ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">';
        html += '<span style="font-size:14px;font-weight:600;">Pago en efectivo en tiendas OXXO</span>';
        html += '</div>';
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<div style="font-size:28px;font-weight:800;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(enganche) + ' MXN</div>';
        html += '</div>';
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-oxxo" style="background:#1a3a5c;">' +
            'PAGO EN EFECTIVO EN OXXO</button>';
        html += '</div>';

        // Footer
        html += '<div style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin-top:8px;">' +
            '&#128274; Pago 100% seguro &middot; Confirmaci\u00f3n inmediata</div>';

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
