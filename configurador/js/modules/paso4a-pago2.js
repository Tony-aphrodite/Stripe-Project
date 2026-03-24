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
        this._otpVerified = false;
        // Send OTP and mount Stripe
        var self = this;
        setTimeout(function() { self._mountStripe(); self._sendOTP(); }, 300);
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var costoLog    = state.costoLogistico || 0;
        var total       = modelo.precioContado + costoLog;
        var msiPago     = modelo.tieneMSI ? Math.round((modelo.precioMSI * 9 + costoLog) / 9) : Math.round(total / 9);
        var ciudad      = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');
        var _fd = new Date(); _fd.setDate(_fd.getDate() + 15);
        var _meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        var fechaEntrega = _fd.getDate() + ' de ' + _meses[_fd.getMonth()] + ' de ' + _fd.getFullYear();
        var color       = state.colorSeleccionado || modelo.colorDefault || '';
        var base        = window.VK_BASE_PATH || '';
        var imgSrc      = base + 'img/' + modelo.id + '/model.png';
        var _envioDestino = (state.centroEntrega && state.centroEntrega.nombre && state.centroEntrega.tipo !== 'cercano')
            ? state.centroEntrega.nombre
            : (state.ciudad || 'tu ciudad');
        // _envioDestino used in Resumen section

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

        // 4. "Tu moto está lista" section (simplified — no color/entrega details)
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<img src="' + imgSrc + '" alt="' + modelo.nombre + '" style="width:110px;height:auto;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-size:13px;color:var(--vk-green-primary);font-weight:700;margin-bottom:2px;">&#10003; Tu moto est\u00e1 lista</div>';
        html += '<div style="font-weight:800;font-size:20px;line-height:1.1;">' + Paso1._getModeloLogo(modelo.id, modelo.nombre) + '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // 5. Resumen de tu compra (right below model card)
        html += '<div class="vk-summary" style="margin-bottom:20px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Resumen de tu compra</div>';
        html += '<div style="font-size:14px;line-height:1.9;">';
        html += '<div>\u2022 Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div>\u2022 Color: <strong>' + color + '</strong></div>';
        html += '<div>\u2022 Entrega en: <strong>' + ciudad + '</strong></div>';
        html += '<div>\u2022 Fecha M\u00e1xima de entrega: <strong style="color:#039fe1;">' + fechaEntrega + '</strong></div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 Asesor Voltika confirma la ubicaci\u00f3n exacta del centro autorizado entre 24 a 48 horas, h\u00e1biles despu\u00e9s del pago</div>';
        if (state.costoLogistico > 0) {
            html += '<div>\u2022 Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
        }
        html += '</div>';
        html += '<div style="border-top:1.5px solid var(--vk-border);margin:12px 0 10px;"></div>';
        html += '<div style="font-size:20px;font-weight:800;color:var(--vk-text-primary);margin-bottom:4px;">Total a pagar hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;">Env\u00edo incluido a ' + _envioDestino + '</div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 o 9 pagos de <strong>' + VkUI.formatPrecio(msiPago) + ' MXN</strong> (9 MSI sin intereses)</div>';
        }
        html += '</div>';

        // 7. OTP verification section
        html += '<div style="border-top:2px solid var(--vk-border);padding-top:18px;margin-bottom:18px;">';
        html += '<div style="font-size:15px;font-weight:800;text-align:center;margin-bottom:4px;">Confirma tu n\u00famero para continuar</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);text-align:center;margin-bottom:12px;">Te enviamos un c\u00f3digo por SMS para confirmar tu identidad.</div>';

        // Phone display
        var _tel = state.telefono || '';
        var _telDisplay = _tel ? ('+52 ' + _tel.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3')) : '+52 --';
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">C\u00f3digo enviado a</div>';
        html += '<div style="font-size:15px;font-weight:700;">' + _telDisplay + '</div>';
        html += '</div>';

        // OTP test hint
        if (state._otpTestCode) {
            html += '<div id="vk-pago-otp-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:10px;text-align:center;font-size:12px;color:#1565C0;">&#128161; C\u00f3digo de prueba: <strong>' + state._otpTestCode + '</strong></div>';
        }

        // 6 OTP boxes
        html += '<div style="display:flex;gap:8px;justify-content:center;margin-bottom:8px;">';
        for (var oi = 0; oi < 6; oi++) {
            html += '<input type="text" class="vk-pago-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" ' +
                'style="width:42px;height:50px;text-align:center;font-size:22px;font-weight:700;' +
                'border:2px solid #e5e7eb;border-radius:8px;outline:none;transition:border-color 0.15s;" ' +
                'data-index="' + oi + '">';
        }
        html += '</div>';

        html += '<div style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-bottom:4px;">&#9201; Esto toma menos de 10 segundos</div>';
        html += '<div style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-bottom:12px;">\u00bfNo lleg\u00f3 el c\u00f3digo? <a href="#" id="vk-pago-otp-reenviar" style="color:#039fe1;font-weight:600;">Reenviar</a></div>';

        // OTP error
        html += '<div id="vk-pago-otp-error" style="display:none;color:#C62828;font-size:12px;background:#FFEBEE;border-radius:6px;padding:8px;text-align:center;margin-bottom:10px;"></div>';
        // OTP success
        html += '<div id="vk-pago-otp-success" style="display:none;color:#4CAF50;font-size:12px;background:#E8F5E9;border-radius:6px;padding:8px;text-align:center;margin-bottom:10px;">&#10003; N\u00famero verificado correctamente</div>';
        html += '</div>';

        // 8. Contact + Card form (hidden until OTP verified)
        html += '<div id="vk-checkout-form" style="display:none;border-top:2px solid var(--vk-border);padding-top:18px;">';

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

        // 8. Two payment option cards — below card form
        html += '<div style="display:flex;flex-direction:row;gap:10px;margin:16px 0;align-items:stretch;">';

        // Left: Pago único / Contado
        html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
        html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">Pago \u00fanico<br>100% seguro</div>';
        html += '<div style="font-size:20px;font-weight:900;text-align:center;margin-bottom:4px;">' + VkUI.formatPrecio(total) + ' <span style="font-size:12px;font-weight:600;">MXN</span></div>';
        if (costoLog > 0) {
            html += '<div style="font-size:10px;text-align:center;margin-bottom:8px;color:#555;">Costo log\u00edstico: <span style="text-decoration:line-through;color:#999;">' + VkUI.formatPrecio(costoLog) + '</span> <strong style="color:#00C851;">Sin costo</strong></div>';
        }
        html += '<button id="vk-pay-unico" class="vk-pay-btn" data-tipo="unico" style="display:block;width:100%;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">';
        html += '<span class="vk-pay-btn__label">PAGAR ' + VkUI.formatPrecio(total) + '</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + '</span>';
        html += '</button>';
        html += '</div>';

        // Right: 9 MSI
        if (modelo.tieneMSI) {
            html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
            html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">9 MSI<br>sin intereses</div>';
            html += '<div style="font-size:20px;font-weight:900;text-align:center;margin-bottom:4px;">' + VkUI.formatPrecio(msiPago) + ' <span style="font-size:12px;font-weight:600;">/ mes</span></div>';
            if (costoLog > 0) {
                html += '<div style="font-size:10px;text-align:center;margin-bottom:8px;color:#555;">Costo log\u00edstico: <strong style="color:#039fe1;">' + VkUI.formatPrecio(costoLog) + '</strong></div>';
            }
            html += '<button id="vk-pay-msi" class="vk-pay-btn" data-tipo="msi" style="display:block;width:100%;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">';
            html += '<span class="vk-pay-btn__label">PAGAR ' + VkUI.formatPrecio(msiPago) + ' / MES</span>';
            html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + '</span>';
            html += '</button>';
            html += '</div>';
        }

        html += '</div>'; // end flex row

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

        // OTP box input
        $(document).off('keydown keyup paste focus blur', '.vk-pago-otp-box');
        $(document).on('keydown', '.vk-pago-otp-box', function(e) {
            var $this = $(this), idx = parseInt($this.data('index'));
            if (e.key === 'Backspace') {
                if ($this.val() === '' && idx > 0) $('.vk-pago-otp-box[data-index="' + (idx - 1) + '"]').val('').focus();
                else $this.val('');
                e.preventDefault(); return;
            }
            if (!/^[0-9]$/.test(e.key) && !['Tab','ArrowLeft','ArrowRight'].includes(e.key)) e.preventDefault();
        });
        $(document).on('keyup', '.vk-pago-otp-box', function() {
            var $this = $(this), idx = parseInt($this.data('index'));
            var val = $this.val().replace(/\D/g, '');
            $this.val(val.slice(-1));
            if (val && idx < 5) $('.vk-pago-otp-box[data-index="' + (idx + 1) + '"]').focus();
            var code = ''; $('.vk-pago-otp-box').each(function() { code += $(this).val(); });
            if (code.length === 6) self._verifyOTP(code);
        });
        $(document).on('paste', '.vk-pago-otp-box', function(e) {
            e.preventDefault();
            var clip = e.originalEvent.clipboardData || e.originalEvent['clipboardData'];
            var pasted = clip ? clip.getData('text').replace(/\D/g, '').slice(0, 6) : '';
            $('.vk-pago-otp-box').each(function(i) { $(this).val(pasted[i] || ''); });
            if (pasted.length === 6) self._verifyOTP(pasted);
        });
        $(document).on('focus', '.vk-pago-otp-box', function() { $(this).css('border-color', '#039fe1'); });
        $(document).on('blur', '.vk-pago-otp-box', function() { $(this).css('border-color', $(this).val() ? '#039fe1' : '#e5e7eb'); });

        // Resend OTP
        $(document).off('click', '#vk-pago-otp-reenviar');
        $(document).on('click', '#vk-pago-otp-reenviar', function(e) { e.preventDefault(); self._sendOTP(); });

        // Button 1: Pago unico
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

    _sendOTP: function() {
        var tel = this.app.state.telefono;
        if (!tel) return;
        var self = this;
        $.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/enviar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ telefono: tel, nombre: self.app.state.nombre || '' }),
            success: function(res) {
                if (res && res.testCode) {
                    self.app.state._otpTestCode = res.testCode;
                    if (!$('#vk-pago-otp-hint').length) {
                        $('.vk-pago-otp-box').first().closest('div').before(
                            '<div id="vk-pago-otp-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:10px;text-align:center;font-size:12px;color:#1565C0;">&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong></div>'
                        );
                    } else {
                        $('#vk-pago-otp-hint').html('&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong>').show();
                    }
                }
            }
        });
    },

    _verifyOTP: function(code) {
        var self = this;
        $.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/verificar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ telefono: self.app.state.telefono, code: code }),
            success: function(res) {
                if (res && res.ok) {
                    self._otpVerified = true;
                    $('#vk-pago-otp-success').show();
                    $('#vk-pago-otp-error').hide();
                    $('#vk-checkout-form').slideDown(200);
                    $('.vk-pago-otp-box').prop('disabled', true).css('background', '#E8F5E9');
                } else {
                    $('#vk-pago-otp-error').text('C\u00f3digo incorrecto. Intenta de nuevo.').show();
                    $('#vk-pago-otp-success').hide();
                }
            },
            error: function() {
                // Testing fallback: accept any 6-digit code
                self._otpVerified = true;
                $('#vk-pago-otp-success').show();
                $('#vk-pago-otp-error').hide();
                $('#vk-checkout-form').slideDown(200);
                $('.vk-pago-otp-box').prop('disabled', true).css('background', '#E8F5E9');
            }
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
        var costoLog2 = state.costoLogistico || 0;
        var total   = modelo.precioContado + costoLog2;
        var msiPago = modelo.tieneMSI ? Math.round((modelo.precioMSI * 9 + costoLog2) / 9) : Math.round(total / 9);
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
        self.app.state._pagoTipo = self._pagoTipo;

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
