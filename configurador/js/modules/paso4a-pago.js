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
        // Pre-select payment type based on what was chosen in PASO 1
        this._pagoTipo = (app.state.metodoPago === 'msi') ? 'msi' : 'unico';
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

        // Card logos
        html += '<div style="text-align:center;padding:8px 0 4px;">' +
            VkUI.renderCardLogos() +
            '</div>';

        // Header
        html += '<h2 class="vk-paso__titulo">PASO 4</h2>';
        html += '<p class="vk-paso__subtitulo">Completa tu pago de forma segura</p>';

        // ── Order summary ─────────────────────────────────────────────────
        html += '<div class="vk-summary">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:12px;">&#128230; Resumen de tu compra</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Modelo:</span>';
        html += '<span class="vk-summary__value">' + modelo.nombre + ' &middot; ' + (state.colorSeleccionado || modelo.colorDefault) + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega en:</span>';
        html += '<span class="vk-summary__value">' + (state.ciudad || '--') + ', ' + (state.estado || '--') + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Tiempo de entrega:</span>';
        html += '<span class="vk-summary__value">' + VOLTIKA_PRODUCTOS.config.entregaDiasHabiles + ' dias habiles</span>';
        html += '</div>';

        html += '<div style="font-size:12px;color:var(--vk-text-muted);padding:4px 0 8px;">' +
            'Un asesor Voltika confirma el centro de entrega en 24-48 hrs.' +
            '</div>';

        if (state.costoLogistico > 0) {
            html += '<div class="vk-summary__row">';
            html += '<span class="vk-summary__label">Costo logistico:</span>';
            html += '<span class="vk-summary__value">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</span>';
            html += '</div>';
        }

        html += '<div class="vk-summary__row vk-summary__row--total">';
        html += '<span>Total:</span>';
        html += '<span>' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '</div>';

        html += '</div>'; // end summary

        // ── Payment type selector ─────────────────────────────────────────
        html += '<div style="margin:16px 0 12px;">';
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">Selecciona tu forma de pago:</div>';
        html += '<div class="vk-payment-options">';

        // Option 1: Pago unico
        var unicoCls = this._pagoTipo === 'unico' ? ' vk-payment-option--selected' : '';
        html += '<div class="vk-payment-option' + unicoCls + '" data-pago-tipo="unico" style="cursor:pointer;">';
        html += '<div class="vk-payment-option__title">Pago unico</div>';
        html += '<div style="font-size:18px;font-weight:800;color:var(--vk-green-primary);margin:6px 0;">' + VkUI.formatPrecio(total) + '</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-muted);">MXN &middot; pago unico</div>';
        html += '<div class="vk-payment-option__bullet" style="margin-top:8px;">&#10004; Sin cargos adicionales</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Pago 100% seguro con Stripe</div>';
        html += '</div>';

        // Option 2: MSI
        var msiCls = this._pagoTipo === 'msi' ? ' vk-payment-option--selected' : '';
        if (modelo.tieneMSI) {
            html += '<div class="vk-payment-option' + msiCls + '" data-pago-tipo="msi" style="cursor:pointer;">';
            html += '<div class="vk-payment-option__title">9 MSI sin intereses</div>';
            html += '<div style="font-size:18px;font-weight:800;color:var(--vk-green-primary);margin:6px 0;">' + VkUI.formatPrecio(msiPago) + '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);">MXN / mes &middot; 9 meses</div>';
            html += '<div class="vk-payment-option__bullet" style="margin-top:8px;">&#10004; Sin intereses ni cargos ocultos</div>';
            html += '<div class="vk-payment-option__bullet">&#10004; Con tu tarjeta de credito ' + VkUI.renderCardLogos() + '</div>';
            html += '</div>';
        } else {
            html += '<div class="vk-payment-option" style="opacity:0.5;pointer-events:none;">';
            html += '<div class="vk-payment-option__title">9 MSI</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-top:8px;">No disponible para este modelo</div>';
            html += '</div>';
        }

        html += '</div>'; // end vk-payment-options
        html += '</div>';

        // ── Contact + Card form (always visible) ──────────────────────────
        html += '<div id="vk-checkout-form">';
        html += '<div style="border-top:2px solid var(--vk-border);margin-top:8px;padding-top:20px;">';

        // Terms
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-terms-check">';
        html += '<label class="vk-checkbox-label" for="vk-terms-check">' +
            'Acepto los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">terminos y condiciones</a> y el aviso de privacidad' +
            '</label>';
        html += '</div>';

        // Contact fields
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre completo</label>';
        html += '<input type="text" class="vk-form-input" id="vk-nombre" placeholder="Juan Perez" autocomplete="name">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Correo electronico</label>';
        html += '<input type="email" class="vk-form-input" id="vk-email" placeholder="juanperez@email.com" autocomplete="email">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Telefono</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-telefono" placeholder="55 1234 5678" maxlength="15" autocomplete="tel">';
        html += '</div>';
        html += '</div>';

        // Stripe card element
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">&#128179; Datos de tarjeta</label>';
        html += '<div id="vk-stripe-card-element" style="border:1.5px solid var(--vk-border);border-radius:6px;padding:14px;background:#FAFAFA;min-height:46px;">' +
            '</div>';
        html += '<div id="vk-stripe-card-errors" style="color:#C62828;font-size:12px;margin-top:6px;display:none;"></div>';
        html += '</div>';

        // Pay button
        html += '<button class="vk-btn vk-btn--primary" id="vk-submit-pago" style="margin-top:8px;">';
        html += '<span id="vk-submit-pago-label">' + this._getLabelPago(total, msiPago) + '</span>';
        html += '<span id="vk-submit-pago-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:12px;">' +
            '&#128274; Pago cifrado SSL &middot; Powered by Stripe' +
            '</div>';
        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:4px;">' +
            'Los datos para facturar se te pediran posteriormente.' +
            '</div>';

        html += '</div>'; // end inner padding
        html += '</div>'; // end checkout-form

        // Error message
        html += '<div id="vk-pago-error" style="display:none;color:#C62828;background:#FFEBEE;border:1px solid #E53935;border-radius:6px;padding:12px;margin-top:12px;font-size:13px;"></div>';

        $('#vk-pago-container').html(html);
    },

    _getLabelPago: function(total, msiPago) {
        if (this._pagoTipo === 'msi') {
            return 'PAGAR PRIMER CARGO ' + VkUI.formatPrecio(msiPago) + ' MXN';
        }
        return 'PAGAR ' + VkUI.formatPrecio(total) + ' MXN';
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

        // Payment type selector — update button label only
        $(document).off('click', '#vk-paso-4a .vk-payment-option');
        $(document).on('click', '#vk-paso-4a .vk-payment-option', function() {
            var tipo = $(this).data('pago-tipo');
            if (!tipo) return;

            self._pagoTipo = tipo;

            // Update selected state
            $('#vk-paso-4a .vk-payment-option').removeClass('vk-payment-option--selected');
            $(this).addClass('vk-payment-option--selected');

            // Update pay button label
            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var total   = modelo.precioContado + self.app.state.costoLogistico;
            var msiPago = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);
            $('#vk-submit-pago-label').text(self._getLabelPago(total, msiPago));
        });

        // Submit payment
        $(document).off('click', '#vk-submit-pago');
        $(document).on('click', '#vk-submit-pago', function(e) {
            e.preventDefault();
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
                self._showSuccess(customerData, modelo, total);
            }
        });
    },

    _showSuccess: function(customerData, modelo, total) {
        var html = '';
        html += '<div style="background:#E8F5E9;border:2px solid #4CAF50;border-radius:12px;padding:24px;text-align:center;margin-top:24px;">';
        html += '<div style="font-size:48px;">&#10004;</div>';
        html += '<h3 style="color:#2E7D32;margin:8px 0 4px;">&#161;Pago exitoso! Tu Voltika ya es tuya.</h3>';
        html += '<p style="font-size:14px;color:#555;margin:8px 0;">Confirmacion enviada a <strong>' + customerData.email + '</strong></p>';
        html += '<div style="background:#FFF;border-radius:8px;padding:12px;margin-top:12px;font-size:13px;text-align:left;">';
        html += '<div><strong>Modelo:</strong> ' + modelo.nombre + '</div>';
        html += '<div><strong>Total pagado:</strong> ' + VkUI.formatPrecio(total) + ' MXN</div>';
        html += '<div><strong>Entrega:</strong> Un asesor Voltika te contactara en 24-48 horas.</div>';
        html += '</div>';
        html += '</div>';

        $('#vk-pago-container').html(html);
        VkUI.scrollToTop();
    },

    _showError: function(msg) {
        $('#vk-pago-error').text(msg).slideDown(200);
    },

    _setLoading: function(isLoading) {
        var $btn = $('#vk-submit-pago');
        if (isLoading) {
            $btn.prop('disabled', true);
            $('#vk-submit-pago-label').hide();
            $('#vk-submit-pago-spinner').show();
        } else {
            $btn.prop('disabled', false);
            $('#vk-submit-pago-label').show();
            $('#vk-submit-pago-spinner').hide();
        }
    }
};
