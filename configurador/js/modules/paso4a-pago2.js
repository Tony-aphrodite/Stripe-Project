/* ==========================================================================
   Voltika - PASO 4A: Payment Form (Contado / MSI)
   Order summary + payment type selector + Stripe card form (always visible)
   ========================================================================== */

var Paso4A = {

    _stripe: null,
    _elements: null,
    _cardElement: null,
    _pagoTipo: 'unico',  // 'unico' | 'msi'

    STRIPE_PUBLISHABLE_KEY: 'pk_test_51Rr5XCDPx1FQbvVSr8odW16SQzUgPoyMJroHp5emN9PttKU4oHs0jAOsBpxM50ISn4xmUzemZomXX6IuIrQN8FRB00NM2T7eGp',
    PAYMENT_INTENT_URL: 'php/create-payment-intent.php',
    ORDER_CONFIRM_URL:  'php/confirmar-orden.php',

    init: function(app) {
        this.app = app;
        this._stripe = null;
        this._cardElement = null;
        // Set _pagoTipo based on what user chose in PASO 1
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

        var total       = modelo.precioContado + state.costoLogistico;
        var msiPago     = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);
        var ciudad      = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');
        var diasEntrega = VOLTIKA_PRODUCTOS.config.entregaDiasHabiles || '7 a 10';
        var color       = state.colorSeleccionado || modelo.colorDefault || '';
        var base        = window.VK_BASE_PATH || '';
        var imgSrc      = base + 'img/' + modelo.id + '/model.png';

        var html = '';

        // 1. Back button
        html += VkUI.renderBackButton(3);

        // 2. Header (title first)
        html += '<div style="text-align:center;margin-bottom:10px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 4 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:8px;">Confirma tu forma de pago segura</h2>';
        // 3. Card logos below title
        html += VkUI.renderCardLogos();
        html += '</div>';

        // 4. "Tu moto está lista" section
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<img src="' + imgSrc + '" alt="' + modelo.nombre + '" style="width:110px;height:auto;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-size:13px;color:var(--vk-green-primary);font-weight:700;margin-bottom:2px;">&#10003; Tu moto est\u00e1 lista</div>';
        html += '<div style="font-weight:800;font-size:20px;line-height:1.1;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">Color: ' + color + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Entrega: ' + ciudad + '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // 5. Two payment option cards — horizontal (flex row)
        html += '<div style="display:flex;flex-direction:row;gap:10px;margin-bottom:16px;align-items:stretch;">';

        // Left: Pago único / Contado
        html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
        html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">Pago \u00fanico<br>100% seguro</div>';
        html += '<div style="font-size:11px;color:var(--vk-text-secondary);flex:1;line-height:1.6;">';
        html += '<div>\u2022 Pago protegido y encriptado</div>';
        html += '<div>\u2022 Confirmaci\u00f3n bancaria al instante</div>';
        html += '<div>\u2022 Atenci\u00f3n personalizada post-venta</div>';
        html += '</div>';
        html += '<button id="vk-pay-unico" class="vk-pay-btn" data-tipo="unico" style="display:block;width:100%;margin-top:10px;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">';
        html += '<span class="vk-pay-btn__label">PAGAR ' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + '</span>';
        html += '</button>';
        html += '</div>';

        // Right: 9 MSI
        if (modelo.tieneMSI) {
            html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
            html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">9 MSI<br>sin intereses</div>';
            html += '<div style="font-size:11px;color:var(--vk-text-secondary);flex:1;line-height:1.6;">';
            html += '<div>Tu moto hoy, sin pagar todo de golpe</div>';
            html += '<div>&#10003; 9 pagos de ' + VkUI.formatPrecio(msiPago) + ' MXN</div>';
            html += '<div>&#10003; Sin intereses ni cargos ocultos</div>';
            html += '<div>&#10003; Cargo autom\u00e1tico cada mes</div>';
            html += '<div>&#10003; Sin tr\u00e1mites adicionales</div>';
            html += '</div>';
            html += '<button id="vk-pay-msi" class="vk-pay-btn" data-tipo="msi" style="display:block;width:100%;margin-top:10px;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">';
            html += '<span class="vk-pay-btn__label">PAGAR PRIMER CARGO</span>';
            html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + '</span>';
            html += '</button>';
            html += '</div>';
        }

        html += '</div>'; // end flex row

        // 6. Contact + Card form
        html += '<div id="vk-checkout-form" style="border-top:2px solid var(--vk-border);padding-top:18px;">';

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

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:4px;margin-bottom:8px;">' +
            '&#128274; Pago cifrado SSL &middot; ' + VkUI.renderCardLogos() +
            '</div>';

        html += '</div>'; // end checkout-form

        // 7. Resumen de tu compra (bottom)
        html += '<div class="vk-summary" style="margin-top:20px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Resumen de tu compra</div>';
        html += '<div style="font-size:14px;line-height:1.9;">';
        html += '<div>\u2022 Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div>\u2022 Color: <strong>' + color + '</strong></div>';
        html += '<div>\u2022 Entrega en: <strong>' + ciudad + '</strong></div>';
        html += '<div>\u2022 Entrega estimada: <strong>' + diasEntrega + ' d\u00edas h\u00e1biles</strong> en tu ciudad</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 Asesor Voltika confirma la ubicaci\u00f3n exacta del centro autorizado entre 24 a 48 horas, h\u00e1biles despu\u00e9s del pago</div>';
        if (state.costoLogistico > 0) {
            html += '<div>\u2022 Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
        }
        html += '</div>';
        html += '<div style="border-top:1.5px solid var(--vk-border);margin:12px 0 10px;"></div>';
        html += '<div style="font-size:20px;font-weight:800;color:var(--vk-text-primary);margin-bottom:4px;">Total a pagar hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 o 9 pagos de <strong>' + VkUI.formatPrecio(msiPago) + ' MXN</strong> (9 MSI sin intereses)</div>';
        }
        html += '</div>';

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
        var $btn = $('.vk-pay-btn');
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
