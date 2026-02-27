/* ==========================================================================
   Voltika - PASO 4A: Payment Form (Contado / MSI)
   Order summary + payment type selector + Stripe card form (always visible)
   ========================================================================== */

var Paso4A = {

    _stripe: null,
    _elements: null,
    _cardElement: null,
    _pagoTipo: 'unico',  // 'unico' | 'msi'

    STRIPE_PUBLISHABLE_KEY: 'pk_live_51QpalADzBRkc6ufKZhJOivZHTWJsTaMJC5WJFDjMDJ2OEF9WuFUBHNjixmxZbhPGzMo5G6AW28dMtk5bEXvXUbEW00xTIexSaz',
    PAYMENT_INTENT_URL: 'php/create-payment-intent.php',
    ORDER_CONFIRM_URL:  'php/confirmar-orden.php',

    init: function(app) {
        this.app = app;
        this._stripe = null;
        this._cardElement = null;
        // _pagoTipo is set when user clicks a pay button
        this._pagoTipo = 'unico';
        this.render();
        this.bindEvents();
        // Mount Stripe automatically — no click required
        var self = this;
        setTimeout(function() { self._mountStripe(); }, 300);
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var total   = modelo.precioContado + state.costoLogistico;
        var msiPago = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);

        var html = '';

        // Back button
        html += VkUI.renderBackButton(3);

        // Card logos + header — matches mockup page 13
        html += '<div style="text-align:center;padding:10px 0 6px;">' +
            VkUI.renderCardLogos() +
            '</div>';

        html += '<div style="text-align:center;margin-bottom:4px;">';
        html += '<div style="font-size:22px;font-weight:800;color:var(--vk-text-primary);">Total hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:13px;color:var(--vk-green-primary);font-weight:600;margin-top:4px;">&#10003; 9 MSI disponibles ' + VkUI.renderCardLogos() + '</div>';
        }
        html += '</div>';

        // ── Order summary ─────────────────────────────────────────────────
        html += '<div class="vk-summary" style="margin-top:14px;">';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Modelo:</span>';
        html += '<span class="vk-summary__value">' + modelo.nombre + ' &middot; ' + (state.colorSeleccionado || modelo.colorDefault) + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega:</span>';
        html += '<span class="vk-summary__value">' + VOLTIKA_PRODUCTOS.config.entregaDiasHabiles + ' d\u00edas h\u00e1biles &middot; ' + (state.ciudad || '--') + '</span>';
        html += '</div>';

        html += '<div style="font-size:11px;color:var(--vk-text-muted);padding:2px 0 6px;">' +
            'Un asesor Voltika confirmar\u00e1 el centro de entrega en 24-48 hrs.' +
            '</div>';

        if (state.costoLogistico > 0) {
            html += '<div class="vk-summary__row">';
            html += '<span class="vk-summary__label">Costo log\u00edstico:</span>';
            html += '<span class="vk-summary__value">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</span>';
            html += '</div>';
        }

        if (modelo.tieneMSI) {
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);padding:4px 0 2px;">' +
                'o ' + 9 + ' pagos de ' + VkUI.formatPrecio(msiPago) + ' MXN (9 MSI sin intereses)' +
                '</div>';
        }

        html += '</div>'; // end summary

        // ── Contact + Card form ────────────────────────────────────────────
        html += '<div id="vk-checkout-form" style="border-top:2px solid var(--vk-border);margin-top:14px;padding-top:18px;">';

        // Terms
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-terms-check">';
        html += '<label class="vk-checkbox-label" for="vk-terms-check">' +
            'Acepto los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">t\u00e9rminos y condiciones</a> y el aviso de privacidad' +
            '</label>';
        html += '</div>';

        // Contact fields
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre completo</label>';
        html += '<input type="text" class="vk-form-input" id="vk-nombre" placeholder="Juan P\u00e9rez" autocomplete="name">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Correo electr\u00f3nico</label>';
        html += '<input type="email" class="vk-form-input" id="vk-email" placeholder="juanperez@email.com" autocomplete="email">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Tel\u00e9fono</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-telefono" placeholder="55 1234 5678" maxlength="15" autocomplete="tel">';
        html += '</div>';
        html += '</div>';

        // Stripe card element
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">&#128179; Datos de tarjeta</label>';
        html += '<div id="vk-stripe-card-element" style="border:1.5px solid var(--vk-border);border-radius:6px;padding:14px;background:#FAFAFA;min-height:46px;"></div>';
        html += '<div id="vk-stripe-card-errors" style="color:#C62828;font-size:12px;margin-top:6px;display:none;"></div>';
        html += '</div>';

        // ── TWO pay buttons (per client mockup page 13) ───────────────────
        html += '<div class="vk-dual-pay-btns">';

        // Button 1: Pago único
        html += '<button class="vk-btn vk-btn--primary vk-pay-btn" id="vk-pay-unico" data-tipo="unico">';
        html += '<span class="vk-pay-btn__label">&#128274; PAGAR ' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';

        // Button 2: 9 MSI (only if available)
        if (modelo.tieneMSI) {
            html += '<button class="vk-btn vk-btn--primary vk-pay-btn" id="vk-pay-msi" data-tipo="msi">';
            html += '<span class="vk-pay-btn__label">9 MSI de ' + VkUI.formatPrecio(msiPago) + ' /mes</span>';
            html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>';
            html += '</button>';
        }

        html += '</div>'; // end dual-pay-btns

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:12px;">' +
            '&#128274; Pago cifrado SSL &middot; ' + VkUI.renderCardLogos() +
            '</div>';
        html += '<div style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-top:4px;">' +
            'Los datos para facturar se te pedir\u00e1n posteriormente.' +
            '</div>';

        html += '</div>'; // end checkout-form

        // Error message
        html += '<div id="vk-pago-error" style="display:none;color:#C62828;background:#FFEBEE;border:1px solid #E53935;border-radius:6px;padding:12px;margin-top:12px;font-size:13px;"></div>';

        $('#vk-pago-container').html(html);
    },

    _mountStripe: function() {
        var self = this;

        if (typeof Stripe === 'undefined') {
            $('#vk-stripe-card-element').html(
                '<p style="color:#C62828;font-size:13px;">&#9888; Error: Stripe.js no disponible. Verifica tu conexion.</p>'
            );
            return;
        }

        if (self._stripe) return; // already mounted

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

        self._cardElement.mount('#vk-stripe-card-element');

        self._cardElement.on('change', function(event) {
            var $err = $('#vk-stripe-card-errors');
            if (event.error) {
                $err.text(event.error.message).show();
            } else {
                $err.text('').hide();
            }
        });
    },

    bindEvents: function() {
        var self = this;

        // Button 1: Pago único
        $(document).off('click', '#vk-pay-unico');
        $(document).on('click', '#vk-pay-unico', function(e) {
            e.preventDefault();
            self._pagoTipo = 'unico';
            self._handleSubmit();
        });

        // Button 2: 9 MSI
        $(document).off('click', '#vk-pay-msi');
        $(document).on('click', '#vk-pay-msi', function(e) {
            e.preventDefault();
            self._pagoTipo = 'msi';
            self._handleSubmit();
        });
    },

    _handleSubmit: function() {
        var self = this;

        if (!$('#vk-terms-check').is(':checked')) {
            alert('Por favor acepta los terminos y condiciones.');
            return;
        }

        var valid = true;
        valid = VkValidacion.validarCampo($('#vk-nombre'),   VkValidacion.nombre,   'Ingresa tu nombre completo') && valid;
        valid = VkValidacion.validarCampo($('#vk-email'),    VkValidacion.email,    'Ingresa un correo valido')   && valid;
        valid = VkValidacion.validarCampo($('#vk-telefono'), VkValidacion.telefono, 'Ingresa un telefono valido (10 digitos)') && valid;
        if (!valid) return;

        if (!self._stripe || !self._cardElement) {
            alert('El modulo de pago no esta listo. Recarga la pagina.');
            return;
        }

        self._setLoading(true);
        $('#vk-pago-error').hide();

        var state   = self.app.state;
        var modelo  = self.app.getModelo(state.modeloSeleccionado);
        var total   = modelo.precioContado + state.costoLogistico;
        var msiPago = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);
        var amountCents = (self._pagoTipo === 'msi' ? msiPago : total) * 100;

        var customerData = {
            nombre:   $('#vk-nombre').val().trim(),
            email:    $('#vk-email').val().trim(),
            telefono: $('#vk-telefono').val().trim(),
            modelo:   modelo.nombre,
            color:    state.colorSeleccionado || modelo.colorDefault,
            ciudad:   state.ciudad  || '',
            estado:   state.estado  || '',
            cp:       state.codigoPostal || ''
        };

        $.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                amount:       amountCents,
                method:       'card',
                installments: self._pagoTipo === 'msi',
                msiMeses:     modelo.msiMeses,
                customer:     customerData
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
                        self._confirmarOrden(customerData, modelo, result.paymentIntent.id, total, msiPago);
                    }
                });
            },
            error: function() {
                self._showError('Error de conexion. Verifica tu internet e intenta de nuevo.');
                self._setLoading(false);
            }
        });
    },

    _confirmarOrden: function(customerData, modelo, paymentIntentId, total, msiPago) {
        var self = this;

        // Save to app state for facturación and exito screens
        self.app.state.nombre       = customerData.nombre;
        self.app.state.email        = customerData.email;
        self.app.state.telefono     = customerData.telefono;
        self.app.state.totalPagado  = total;
        self.app.state.pagoCompletado = true;

        $.ajax({
            url: self.ORDER_CONFIRM_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                paymentIntentId: paymentIntentId,
                pagoTipo:  self._pagoTipo,
                nombre:    customerData.nombre,
                email:     customerData.email,
                telefono:  customerData.telefono,
                modelo:    customerData.modelo,
                color:     customerData.color,
                ciudad:    customerData.ciudad,
                estado:    customerData.estado,
                cp:        customerData.cp,
                total:     total,
                msiPago:   msiPago,
                msiMeses:  modelo.msiMeses
            }),
            complete: function() {
                self._setLoading(false);
                self.app.irAPaso('facturacion');
            }
        });
    },

    _showError: function(msg) {
        $('#vk-pago-error').text(msg).slideDown(200);
    },

    _setLoading: function(isLoading) {
        var $btn = this._pagoTipo === 'msi' ? $('#vk-pay-msi') : $('#vk-pay-unico');
        if (isLoading) {
            // Disable both buttons while processing
            $('.vk-pay-btn').prop('disabled', true);
            $btn.find('.vk-pay-btn__label').hide();
            $btn.find('.vk-pay-btn__spinner').show();
        } else {
            $('.vk-pay-btn').prop('disabled', false);
            $btn.find('.vk-pay-btn__label').show();
            $btn.find('.vk-pay-btn__spinner').hide();
        }
    }
};
