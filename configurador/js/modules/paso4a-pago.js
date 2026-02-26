/* ==========================================================================
   Voltika - PASO 4A: Payment Form (Contado / MSI)
   Order summary + payment options + Stripe Elements integration
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
        this._pagoTipo = 'unico';
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var total = modelo.precioContado + state.costoLogistico;
        var msiPago = Math.round(total / modelo.msiMeses);

        var html = '';

        // Top bar: card logos
        html += '<div style="text-align:center;padding:8px 0;margin-bottom:8px;">' +
            '<span style="font-size:18px;">' + VkUI.renderCardLogos() + '</span>' +
            '</div>';

        // Header
        html += '<h2 class="vk-paso__titulo">PASO 4</h2>';
        html += '<p class="vk-paso__subtitulo">Confirma tu forma de pago segura</p>';

        // Order summary
        html += '<div class="vk-summary">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:12px;">Resumen de tu compra</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Modelo:</span>';
        html += '<span class="vk-summary__value">' + modelo.nombre + ' - ' + (state.colorSeleccionado || 'Negro') + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega en:</span>';
        html += '<span class="vk-summary__value">' + (state.ciudad || '--') + ', ' + (state.estado || '--') + '</span>';
        html += '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Entrega estimada:</span>';
        html += '<span class="vk-summary__value">' + VOLTIKA_PRODUCTOS.config.entregaDiasHabiles + ' dias habiles</span>';
        html += '</div>';

        html += '<div style="font-size:12px;color:var(--vk-text-muted);margin:8px 0;">' +
            'Asesor Voltika confirma la ubicacion exacta del centro autorizado entre 24 a 48 horas despues del pago.' +
            '</div>';

        html += '<div class="vk-summary__row">';
        html += '<span class="vk-summary__label">Costo logistico:</span>';
        html += '<span class="vk-summary__value">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</span>';
        html += '</div>';

        html += '<div class="vk-summary__row vk-summary__row--total">';
        html += '<span>Total a pagar hoy:</span>';
        html += '<span>' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '</div>';

        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:8px;">' +
            '&bull; o ' + modelo.msiMeses + ' pagos de ' + VkUI.formatPrecio(msiPago) + ' MXN <span style="color:var(--vk-text-muted);">(' + modelo.msiMeses + ' MSI sin intereses)</span>' +
            '</div>';

        html += '</div>'; // end summary

        // Payment options (2 columns)
        html += '<div class="vk-payment-options">';

        // Option 1: Pago unico
        html += '<div class="vk-payment-option" data-pago-tipo="unico">';
        html += '<div class="vk-payment-option__title">Pago unico<br>100% seguro</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Pago protegido y encriptado</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Confirmacion bancaria al instante</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Atencion personalizada post-venta</div>';
        html += '<button class="vk-btn vk-btn--primary" style="width:100%;margin:12px 0 0;font-size:13px;padding:10px;" data-pago-tipo="unico">' +
            'PAGAR ' + VkUI.formatPrecio(total) + ' MXN</button>';
        html += '</div>';

        // Option 2: MSI
        html += '<div class="vk-payment-option" data-pago-tipo="msi">';
        html += '<div class="vk-payment-option__title">' + modelo.msiMeses + ' MSI sin intereses<br><small>Tu moto hoy, sin pagar todo de golpe</small></div>';
        html += '<div class="vk-payment-option__bullet">&#10004; ' + modelo.msiMeses + ' pagos fijos de ' + VkUI.formatPrecio(msiPago) + ' MXN</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Sin intereses ni cargos ocultos</div>';
        html += '<div class="vk-payment-option__bullet">&#10004; Cargo automatico seguro cada mes</div>';
        html += '<button class="vk-btn vk-btn--secondary" style="width:100%;margin:12px 0 0;font-size:13px;padding:10px;" data-pago-tipo="msi">' +
            'PAGAR PRIMER CARGO ' + VkUI.formatPrecio(msiPago) + ' MXN</button>';
        html += '</div>';

        html += '</div>'; // end payment options

        // Security badge
        html += '<div class="vk-security">';
        html += '<div class="vk-security__title">&#128274; Compra 100% segura</div>';
        html += '<div class="vk-security__note">' +
            'Voltika procesa pagos con tecnologia bancaria certificada.' +
            '</div>';
        html += '</div>';

        // Checkout form (hidden until payment option selected)
        html += '<div id="vk-checkout-form" style="display:none;">';
        html += this.renderCheckoutForm(modelo, total, msiPago);
        html += '</div>';

        // Global error message
        html += '<div id="vk-pago-error" style="display:none;color:#C62828;background:#FFEBEE;border:1px solid #E53935;border-radius:6px;padding:12px;margin-top:12px;font-size:13px;"></div>';

        $('#vk-pago-container').html(html);
    },

    renderCheckoutForm: function(modelo, total, msiPago) {
        var html = '';

        html += '<div style="border-top:2px solid var(--vk-border);margin-top:24px;padding-top:24px;">';

        // Header
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">&#9745; voltika</div>';
        html += '<div style="font-size:28px;font-weight:800;margin-top:8px;" id="vk-checkout-total">Total hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-top:4px;">' +
            '&#10004; 9 MSI disponibles ' + VkUI.renderCardLogos() +
            '</div>';
        html += '</div>';

        // Order mini-summary
        html += '<div style="background:var(--vk-bg-light);padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;">';
        html += '<div>&bull; ' + modelo.nombre + ' - ' + (this.app.state.colorSeleccionado || 'Negro') + '</div>';
        html += '<div>&bull; Tiempo de entrega: 7 a 10 dias habiles en tu ciudad</div>';
        html += '<div>&bull; Te contactara un asesor personal Voltika en 24 a 48 horas</div>';
        html += '</div>';

        // Terms checkbox
        html += '<div class="vk-checkbox-group">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-terms-check">';
        html += '<label class="vk-checkbox-label" for="vk-terms-check">' +
            'Acepto los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">terminos y condiciones</a> y el aviso de privacidad' +
            '</label>';
        html += '</div>';

        // Contact fields
        html += '<div style="margin-top:16px;">';

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

        // Stripe Card Element container
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">&#128179; Datos de tarjeta</label>';
        html += '<div id="vk-stripe-card-element" style="border:1.5px solid var(--vk-border);border-radius:6px;padding:14px;background:#FAFAFA;">' +
            '</div>';
        html += '<div id="vk-stripe-card-errors" style="color:#C62828;font-size:12px;margin-top:6px;"></div>';
        html += '</div>';

        // Pay button
        html += '<button class="vk-btn vk-btn--primary" id="vk-submit-pago" style="margin-top:8px;">' +
            '<span id="vk-submit-pago-label">COMPLETAR PAGO</span>' +
            '<span id="vk-submit-pago-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>' +
            '</button>';

        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:12px;">' +
            '&#128274; Pago cifrado SSL &middot; Powered by Stripe &middot; ' + VkUI.renderCardLogos() +
            '</div>';

        html += '</div>'; // end form fields
        html += '</div>'; // end checkout

        return html;
    },

    _mountStripe: function() {
        var self = this;

        if (typeof Stripe === 'undefined') {
            $('#vk-stripe-card-element').html(
                '<p style="color:#C62828;font-size:13px;">&#9888; Error: Stripe.js no esta disponible. Verifica tu conexion a internet.</p>'
            );
            return;
        }

        if (self._stripe) return; // Already mounted

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

        // Payment option selection — show checkout form
        $(document).on('click', '#vk-paso-4a .vk-payment-option, #vk-paso-4a .vk-payment-option .vk-btn', function(e) {
            e.stopPropagation();
            var $target = $(this).closest('[data-pago-tipo]');
            self._pagoTipo = $target.data('pago-tipo') || $(this).data('pago-tipo') || 'unico';

            var modelo  = self.app.getModelo(self.app.state.modeloSeleccionado);
            var total   = modelo.precioContado + self.app.state.costoLogistico;
            var msiPago = Math.round(total / modelo.msiMeses);

            // Update checkout total label to reflect chosen mode
            if (self._pagoTipo === 'msi') {
                $('#vk-checkout-total').text('Primer cargo: ' + VkUI.formatPrecio(msiPago) + ' MXN');
            } else {
                $('#vk-checkout-total').text('Total hoy: ' + VkUI.formatPrecio(total) + ' MXN');
            }

            $('#vk-checkout-form').slideDown(400);

            // Mount Stripe card element after form is visible
            setTimeout(function() {
                self._mountStripe();
                $('html, body').animate({
                    scrollTop: $('#vk-checkout-form').offset().top - 20
                }, 300);
            }, 450);
        });

        // Submit payment
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
        var msiPago = Math.round(total / modelo.msiMeses);
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

        // Step 1: Create PaymentIntent on server
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

                // Step 2: Confirm card payment with Stripe
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
                        // Step 3: Confirm order (save DB + send email)
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
        html += '<p style="font-size:14px;color:#555;margin:8px 0;">Hemos enviado la confirmacion a <strong>' + customerData.email + '</strong></p>';
        html += '<div style="background:#FFF;border-radius:8px;padding:12px;margin-top:12px;font-size:13px;text-align:left;">';
        html += '<div><strong>Modelo:</strong> ' + modelo.nombre + '</div>';
        html += '<div><strong>Total pagado:</strong> ' + VkUI.formatPrecio(total) + ' MXN</div>';
        html += '<div><strong>Entrega:</strong> Un asesor Voltika te contactara en 24-48 horas.</div>';
        html += '</div>';
        html += '</div>';

        $('#vk-checkout-form').html(html);
        $('html, body').animate({ scrollTop: $('#vk-checkout-form').offset().top - 20 }, 300);
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
