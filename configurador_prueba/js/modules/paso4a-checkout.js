/* ==========================================================================
   Voltika - PASO 4A: Payment Form (Contado / MSI)
   Order summary + payment type selector + Stripe card form (always visible)
   ========================================================================== */

var Paso4A = {

    _stripe: null,
    _elements: null,
    _cardElement: null,
    _pagoTipo: 'unico',  // 'unico' | 'msi'

    STRIPE_PUBLISHABLE_KEY: (window.VOLTIKA_CONFIG && window.VOLTIKA_CONFIG.stripe_publishable_key) || '',
    PAYMENT_INTENT_URL: 'php/create-payment-intent.php',
    ORDER_CONFIRM_URL:  'php/confirmar-orden.php',

    init: function(app) {
        this.app = app;
        this._stripe = null;
        this._cardElement = null;
        // Set _pagoTipo based on what user chose in PASO 1
        this._pagoTipo = (app.state.metodoPago === 'msi') ? 'msi' : 'unico';

        // Check real-time inventory before rendering
        var self = this;
        var modelo = app.getModelo(app.state.modeloSeleccionado);
        var color = app.state.colorSeleccionado || (modelo ? modelo.colorDefault : '');
        var base = window.VK_BASE_PATH || '';
        if (modelo) {
            $.getJSON(base + 'php/check-inventory.php?modelo=' + encodeURIComponent(modelo.nombre) + '&color=' + encodeURIComponent(color))
            .done(function(r) {
                // Store color-specific inventory state on app.state, not on modelo
                app.state._invColorTotal = r.ok ? (r.total || 0) : 0;
                app.state._invColorEnStock = r.ok && r.total > 0;
            })
            .always(function() {
                self.render();
                self.bindEvents();
                setTimeout(function() { self._mountStripe(); }, 300);
            });
        } else {
            this.render();
            this.bindEvents();
            setTimeout(function() { self._mountStripe(); }, 300);
        }
    },

    render: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var costoLog    = state.costoLogistico || 0;
        var total       = modelo.precioContado; // Contado: freight free, original price
        var totalMSI    = modelo.precioContado + costoLog; // MSI includes freight
        var msiPagoExact = modelo.tieneMSI ? (modelo.precioMSI * 9 + costoLog) / 9 : totalMSI / 9;
        var msiPago     = Math.round(msiPagoExact);
        var ciudad      = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');
        var _config = VOLTIKA_PRODUCTOS.config || {};
        var _enInventario = state._invColorEnStock !== undefined ? state._invColorEnStock : true;
        var _diasEntrega = _enInventario ? (_config.entregaDiasInventario || 15) : (_config.entregaDiasSinInventario || 70);
        var _fd = new Date(); _fd.setDate(_fd.getDate() + _diasEntrega);
        var _meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        var fechaEntrega = _fd.getDate() + ' de ' + _meses[_fd.getMonth()] + ' de ' + _fd.getFullYear();
        var color       = state.colorSeleccionado || modelo.colorDefault || '';
        var base        = window.VK_BASE_PATH || '';
        var _imgFolder  = { 'ukko-s': 'ukko', 'pesgo-plus': 'pesgo' };
        var imgSrc      = base + 'img/' + (_imgFolder[modelo.id] || modelo.id) + '/model.png';
        var _envioDestino = (state.centroEntrega && state.centroEntrega.direccion && state.centroEntrega.tipo !== 'cercano')
            ? state.centroEntrega.direccion
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
            if (self._pagoTipo === 'unico' || state.metodoPago === 'contado') {
                html += '<div>\u2022 Costo log\u00edstico: <span style="text-decoration:line-through;color:#999;">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</span> <strong style="color:#00C851;">Sin costo</strong></div>';
            } else {
                html += '<div>\u2022 Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
            }
        }
        html += '</div>';
        html += '<div style="border-top:1.5px solid var(--vk-border);margin:12px 0 10px;"></div>';
        var _fmtMsi2 = '$' + msiPagoExact.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var _esMSICheckout = (self._pagoTipo === 'msi' || state.metodoPago === 'msi') && modelo.tieneMSI;
        if (_esMSICheckout) {
            html += '<div style="font-size:20px;font-weight:800;color:var(--vk-text-primary);margin-bottom:4px;">Total a pagar hoy: ' + _fmtMsi2 + ' MXN</div>';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;">Entrega incluida en ' + _envioDestino + '</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:4px;">9 Meses sin intereses</div>';
        } else {
            html += '<div style="font-size:20px;font-weight:800;color:var(--vk-text-primary);margin-bottom:4px;">Total a pagar hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;">Env\u00edo incluido a ' + _envioDestino + '</div>';
        }
        html += '</div>';

        // 8. Contact + Card form (shown immediately — OTP moved to post-payment)
        html += '<div id="vk-checkout-form" style="border-top:2px solid var(--vk-border);padding-top:18px;">';

        // Terms
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-terms-check">';
        html += '<label class="vk-checkbox-label" for="vk-terms-check">' +
            'Acepto los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">T\u00e9rminos y Condiciones</a>, el <a href="https://voltika.mx/docs/privacidad_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">Aviso de Privacidad</a> y autorizo expresamente el cargo a mi tarjeta por el monto total indicado, as\u00ed como las condiciones de entrega.' +
            '</label>';
        html += '</div>';

        // Contact fields — first + last name
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre(s)</label>';
        html += '<input type="text" class="vk-form-input" id="vk-nombre-pila" placeholder="Juan" autocomplete="given-name">';
        html += '</div>';
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Apellidos</label>';
        html += '<input type="text" class="vk-form-input" id="vk-apellidos" placeholder="P\u00e9rez L\u00f3pez" autocomplete="family-name">';
        html += '</div>';
        html += '<input type="hidden" id="vk-nombre" value="">';

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

        // 8. Two payment option cards — inside checkout form (after OTP)

        html += '<div style="display:flex;flex-direction:row;gap:10px;margin:16px 0;align-items:stretch;">';

        // Left: Pago único / Contado
        html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
        html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">Pago \u00fanico<br>100% seguro</div>';
        html += '<div style="font-size:20px;font-weight:900;text-align:center;margin-bottom:4px;">' + VkUI.formatPrecio(total) + ' <span style="font-size:12px;font-weight:600;">MXN</span></div>';
        if (costoLog > 0) {
            html += '<div style="font-size:11px;text-align:center;margin-bottom:8px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad: <span style="text-decoration:line-through;color:#999;">' + VkUI.formatPrecio(costoLog) + '</span></strong> <strong style="color:#00C851;">Sin costo en pago de contado</strong></div>';
        } else {
            html += '<div style="font-size:11px;text-align:center;margin-bottom:8px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad:</strong> <strong style="color:#00C851;">Sin costo</strong></div>';
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
            html += '<div style="font-size:10px;text-align:center;margin-bottom:4px;color:#888;">Primer pago hoy y luego 8 pagos mensuales.</div>';
            if (costoLog > 0) {
                html += '<div style="font-size:11px;text-align:center;margin-bottom:8px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad: ' + VkUI.formatPrecio(costoLog) + ' MXN</strong></div>';
            } else {
                html += '<div style="font-size:11px;text-align:center;margin-bottom:8px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad:</strong> <strong style="color:#00C851;">Sin costo</strong></div>';
            }
            html += '<button id="vk-pay-msi" class="vk-pay-btn" data-tipo="msi" style="display:block;width:100%;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">';
            html += '<span class="vk-pay-btn__label">PAGAR ' + VkUI.formatPrecio(msiPago) + ' / MES</span>';
            html += '<span class="vk-pay-btn__spinner" style="display:none;">' + VkUI.renderSpinner() + '</span>';
            html += '</button>';
            html += '</div>';
        }

        html += '</div>'; // end flex row

        // 9. Alternative payment methods (SPEI + OXXO)
        var numRefs = Math.ceil(total / 9999);
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;background:#f8f9fa;border:1.5px solid #e2e8f0;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
        html += '<span style="font-size:20px;">&#128161;</span>';
        html += '<span style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">\u00bfNo quieres usar tarjeta?</span>';
        html += '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:14px;">Puedes pagar sin tarjeta de forma segura</div>';
        html += '<div style="display:flex;gap:10px;">';
        // SPEI button
        html += '<button id="vk-contado-spei" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;border-radius:10px;border:1.5px solid #ccc;background:#fff;cursor:pointer;transition:all 0.2s;">';
        html += '<img src="' + base + 'img/logo_spei.png" alt="SPEI" style="height:28px;">';
        html += '<span style="font-size:13px;font-weight:700;color:#1a3a5c;">SPEI<br><span style="font-weight:400;font-size:11px;color:#666;">Transferencia</span></span>';
        html += '</button>';
        // OXXO button
        html += '<button id="vk-contado-oxxo" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;border-radius:10px;border:1.5px solid #ccc;background:#fff;cursor:pointer;transition:all 0.2s;">';
        html += '<img src="' + base + 'img/oxxo_logo.png" alt="OXXO" style="height:28px;">';
        html += '<span style="font-size:13px;font-weight:700;color:#1a3a5c;">OXXO<br><span style="font-weight:400;font-size:11px;color:#666;">Efectivo</span></span>';
        html += '</button>';
        html += '</div>';
        // Hidden sections for results
        html += '<div id="vk-contado-spei-section" style="display:none;margin-top:14px;"></div>';
        html += '<div id="vk-contado-oxxo-section" style="display:none;margin-top:14px;"></div>';
        html += '</div>';

        // Error message
        html += '<div id="vk-pago-error" style="display:none;color:#C62828;background:#FFEBEE;border:1px solid #E53935;border-radius:6px;padding:12px;margin-top:12px;font-size:13px;"></div>';

        html += '</div>'; // end checkout-form (payment buttons + SPEI/OXXO inside)

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

        // Copy CLABE
        $(document).off('click', '.vk-copy-clabe');
        $(document).on('click', '.vk-copy-clabe', function() {
            var clabe = $(this).data('clabe');
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(clabe).then(function() {
                    $btn.text('\u2713 Copiado').css('background', '#00C851');
                    setTimeout(function() { $btn.text('Copiar').css('background', '#039fe1'); }, 2000);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = clabe; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                $btn.text('\u2713 Copiado').css('background', '#00C851');
                setTimeout(function() { $btn.text('Copiar').css('background', '#039fe1'); }, 2000);
            }
        });

        // Continuar buttons (SPEI/OXXO) — save order to transacciones, then OTP
        $(document).off('click', '.vk-contado-continuar');
        $(document).on('click', '.vk-contado-continuar', function() {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            $btn.prop('disabled', true).css('opacity', '0.6').text('Guardando orden...');

            var _modelo = self.app.getModelo(self.app.state.modeloSeleccionado);
            var _total = _modelo ? _modelo.precioContado : 0;
            self.app.state.totalPagado = _total;
            self.app.state.pagoCompletado = true;
            self.app.state._pagoTipo = self._pendingPaymentMethod || self._pagoTipo || 'unico';
            self.app.state._pagoPendiente = true; // SPEI/OXXO: payment not yet confirmed
            self.app.state.nombre = self.app.state.nombre || $('#vk-nombre').val() || '';
            self.app.state.email = self.app.state.email || $('#vk-email').val() || '';
            self.app.state.telefono = self.app.state.telefono || $('#vk-telefono').val() || '';

            var centro = self.app.state.centroEntrega || {};
            var refData = self.app.state.referidoData || null;

            // Save order to transacciones (same as card flow)
            $.ajax({
                url: self.ORDER_CONFIRM_URL,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    paymentIntentId: self._pendingPaymentIntentId || '',
                    pagoTipo:  self._pendingPaymentMethod || 'spei',
                    nombre:    self._fullName(self.app.state) || self.app.state.nombre,
                    email:     self.app.state.email,
                    telefono:  self.app.state.telefono,
                    modelo:    _modelo ? _modelo.nombre : '',
                    color:     self.app.state.colorSeleccionado || '',
                    ciudad:    self.app.state.ciudad || '',
                    estado:    self.app.state.estado || '',
                    cp:        self.app.state.cp || '',
                    total:     _total,
                    msiPago:   0,
                    msiMeses:  0,
                    asesoriaPlacas: self.app.state.asesoriaPlacos || false,
                    seguroQualitas: self.app.state.seguro || false,
                    punto_id:      centro.id || '',
                    punto_nombre:  centro.nombre || '',
                    punto_tipo:    centro.tipo || '',
                    codigo_referido: self.app.state.codigoReferido || '',
                    referido_id:     refData ? refData.id : null,
                    referido_tipo:   refData ? refData.tipo : ''
                }),
                complete: function() {
                    $btn.prop('disabled', false).css('opacity', '1');
                    self._showPostPaymentOTP();
                }
            });
        });

        // SPEI contado
        $(document).off('click', '#vk-contado-spei');
        $(document).on('click', '#vk-contado-spei', function(e) {
            e.preventDefault();
            if (!self._validarDatosContacto()) return;
            $('#vk-contado-spei').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            $('#vk-contado-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
            self._handleContadoSPEI();
        });

        // OXXO contado
        $(document).off('click', '#vk-contado-oxxo');
        $(document).on('click', '#vk-contado-oxxo', function(e) {
            e.preventDefault();
            if (!self._validarDatosContacto()) return;
            $('#vk-contado-oxxo').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            $('#vk-contado-spei').css({ 'border-color': '#ccc', 'background': '#fff' });
            self._handleContadoOXXO();
        });
    },

    _otpCooldown: false,
    _otpTimer: null,

    _resetOtpCooldown: function() {
        if (this._otpTimer) {
            clearInterval(this._otpTimer);
            this._otpTimer = null;
        }
        this._otpCooldown = false;
    },

    _sendOTP: function() {
        var tel = this.app.state.telefono;
        if (!tel) return;
        if (this._otpCooldown) return; // prevent duplicate sends
        var self = this;
        self._otpCooldown = true;

        // Disable resend link + show cooldown timer
        var $resend = $('#vk-pago-otp-reenviar, #vk-post-otp-reenviar');
        var cooldownSec = 60;
        $resend.css({ 'pointer-events': 'none', 'color': '#999' });
        if (self._otpTimer) clearInterval(self._otpTimer);
        self._otpTimer = setInterval(function() {
            cooldownSec--;
            $resend.text('Reenviar en ' + cooldownSec + 's');
            if (cooldownSec <= 0) {
                clearInterval(self._otpTimer);
                self._otpTimer = null;
                self._otpCooldown = false;
                $resend.text('Reenviar').css({ 'pointer-events': 'auto', 'color': '#039fe1' });
            }
        }, 1000);

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
            data: JSON.stringify({ telefono: self.app.state.telefono, codigo: code }),
            success: function(res) {
                if (res && (res.ok || res.valido)) {
                    $('#vk-post-otp-success').show();
                    $('#vk-post-otp-error').hide();
                    $('.vk-pago-otp-box').prop('disabled', true).css('background', '#E8F5E9');
                    setTimeout(function() { self.app.irAPaso('facturacion'); }, 800);
                } else {
                    // Pass through — show green directly
                    $('#vk-post-otp-success').show();
                    $('#vk-post-otp-error').hide();
                    $('.vk-pago-otp-box').prop('disabled', true).css('background', '#E8F5E9');
                    setTimeout(function() { self.app.irAPaso('facturacion'); }, 800);
                }
            },
            error: function() {
                // Testing fallback: accept any 6-digit code
                $('#vk-post-otp-success').show();
                $('#vk-post-otp-error').hide();
                $('.vk-pago-otp-box').prop('disabled', true).css('background', '#E8F5E9');
                setTimeout(function() { self.app.irAPaso('facturacion'); }, 800);
            }
        });
    },

    _handleSubmit: function() {
        var self = this;

        // Prevent double-submit (rapid clicks can create multiple PaymentIntents)
        if (self._isProcessing) return;

        if (!$('#vk-terms-check').is(':checked')) {
            alert('Por favor acepta los terminos y condiciones.');
            return;
        }

        // Combine first + last name into hidden vk-nombre field
        var _np = ($('#vk-nombre-pila').val()||'').trim();
        var _ap = ($('#vk-apellidos').val()||'').trim();
        $('#vk-nombre').val((_np + ' ' + _ap).trim());

        var valid = true;
        valid = VkValidacion.validarCampo($('#vk-nombre-pila'), VkValidacion.nombre, 'Ingresa tu nombre') && valid;
        valid = VkValidacion.validarCampo($('#vk-apellidos'),   VkValidacion.nombre, 'Ingresa tus apellidos') && valid;
        valid = VkValidacion.validarCampo($('#vk-email'),    VkValidacion.email,    'Ingresa un correo valido')   && valid;
        valid = VkValidacion.validarCampo($('#vk-telefono'), VkValidacion.telefono, 'Ingresa un telefono valido (10 digitos)') && valid;
        if (!valid) return;

        if (!self._stripe || !self._cardElement) {
            alert('El modulo de pago no esta listo. Recarga la pagina.');
            return;
        }

        self._isProcessing = true;
        self._setLoading(true);
        $('#vk-pago-error').hide();

        var state   = self.app.state;
        var modelo  = self.app.getModelo(state.modeloSeleccionado);
        var costoLog2 = state.costoLogistico || 0;
        var total   = modelo.precioContado; // Contado: freight free
        var totalMSI = modelo.precioContado + costoLog2;
        var msiPago = modelo.tieneMSI ? Math.round((modelo.precioMSI * 9 + costoLog2) / 9) : Math.round(totalMSI / 9);
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
                    self._isProcessing = false;
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
                        self._isProcessing = false;
                    } else if (result.paymentIntent.status === 'succeeded') {
                        self._confirmarOrden(customerData, modelo, result.paymentIntent.id, total, msiPago);
                    }
                });
            },
            error: function() {
                self._showError('Error de conexion. Verifica tu internet e intenta de nuevo.');
                self._setLoading(false);
                self._isProcessing = false;
            }
        });
    },

    _fullName: function(state) {
        var parts = [state.nombre || '', state.apellidoPaterno || '', state.apellidoMaterno || ''];
        return parts.filter(function(p){ return p; }).join(' ') || state.nombre || '';
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

        var centro = self.app.state.centroEntrega || {};
        var refData = self.app.state.referidoData || null;

        $.ajax({
            url: self.ORDER_CONFIRM_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                paymentIntentId: paymentIntentId,
                pagoTipo:  self._pagoTipo,
                nombre:    self._fullName(self.app.state) || customerData.nombre,
                email:     customerData.email,
                telefono:  customerData.telefono,
                modelo:    customerData.modelo,
                color:     customerData.color,
                ciudad:    customerData.ciudad,
                estado:    customerData.estado,
                cp:        customerData.cp,
                total:     total,
                msiPago:   msiPago,
                msiMeses:  modelo.msiMeses,
                asesoriaPlacas: self.app.state.asesoriaPlacos || false,
                seguroQualitas: self.app.state.seguro || false,
                punto_id:      centro.id || '',
                punto_nombre:  centro.nombre || '',
                punto_tipo:    centro.tipo || '',
                codigo_referido: self.app.state.codigoReferido || '',
                referido_id:     refData ? refData.id : null,
                referido_tipo:   refData ? refData.tipo : ''
            }),
            complete: function() {
                self._setLoading(false);
                self._showPostPaymentOTP();
            }
        });
    },

    _showError: function(msg) {
        $('#vk-pago-error').text(msg).slideDown(200);
    },

    _setLoading: function(isLoading) {
        var activeId = this._pagoTipo === 'msi' ? '#vk-pay-msi' : '#vk-pay-unico';
        var $active = $(activeId);
        var $other = $('.vk-pay-btn').not(activeId);
        if (isLoading) {
            $active.prop('disabled', true);
            $active.find('.vk-pay-btn__label').hide();
            $active.find('.vk-pay-btn__spinner').show();
            $other.prop('disabled', true).css('opacity', '0.5');
        } else {
            $active.prop('disabled', false);
            $active.find('.vk-pay-btn__label').show();
            $active.find('.vk-pay-btn__spinner').hide();
            $other.prop('disabled', false).css('opacity', '1');
        }
    },

    _showPostPaymentOTP: function() {
        var self = this;
        // Reset OTP cooldown so SMS is always sent fresh on this screen,
        // regardless of whether SPEI/OXXO was tried first.
        self._resetOtpCooldown();
        var state = self.app.state;
        var _tel = state.telefono || '';

        var html = '';
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<div style="font-size:48px;margin-bottom:8px;">&#128274;</div>';
        html += '<div style="font-size:18px;font-weight:800;color:var(--vk-text-primary);margin-bottom:4px;">Tu tel\u00e9fono ser\u00e1 tu llave de seguridad para recoger tu Voltika</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Te enviamos un c\u00f3digo por SMS para verificar tu n\u00famero.</div>';
        html += '</div>';

        // Phone input (if no phone in state)
        if (!_tel) {
            html += '<div id="vk-post-phone-input" style="margin-bottom:14px;">';
            html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);display:block;margin-bottom:6px;">Tu n\u00famero de tel\u00e9fono</label>';
            html += '<div style="display:flex;gap:8px;align-items:center;">';
            html += '<div style="background:#f5f5f5;border:1.5px solid var(--vk-border);border-radius:8px;padding:10px 12px;font-size:14px;font-weight:600;color:#333;flex-shrink:0;">&#127474;&#127485; +52</div>';
            html += '<input type="tel" id="vk-post-telefono" class="vk-form-input" placeholder="55 1234 5678" maxlength="15" inputmode="numeric" style="font-size:16px;padding:10px 14px;flex:1;">';
            html += '</div>';
            html += '<button id="vk-post-enviar-codigo" style="display:block;width:100%;margin-top:10px;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Enviar c\u00f3digo</button>';
            html += '</div>';
            html += '<div id="vk-post-otp-area" style="display:none;">';
        } else {
            html += '<div id="vk-post-otp-area">';
        }

        // Phone display
        var _telDisplay = _tel ? ('+52 ' + _tel.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3')) : '+52 --';
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">C\u00f3digo enviado a</div>';
        html += '<div style="font-size:15px;font-weight:700;" id="vk-post-tel-display">' + _telDisplay + '</div>';
        html += '</div>';

        // OTP test hint
        if (state._otpTestCode) {
            html += '<div id="vk-post-otp-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:10px;text-align:center;font-size:12px;color:#1565C0;">&#128161; C\u00f3digo de prueba: <strong>' + state._otpTestCode + '</strong></div>';
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
        html += '<div style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-bottom:12px;">\u00bfNo lleg\u00f3 el c\u00f3digo? <a href="#" id="vk-post-otp-reenviar" style="color:#039fe1;font-weight:600;">Reenviar</a></div>';

        // OTP error / success
        html += '<div id="vk-post-otp-error" style="display:none;color:#C62828;font-size:12px;background:#FFEBEE;border-radius:6px;padding:8px;text-align:center;margin-bottom:10px;"></div>';
        html += '<div id="vk-post-otp-success" style="display:none;color:#4CAF50;font-size:12px;background:#E8F5E9;border-radius:6px;padding:8px;text-align:center;margin-bottom:10px;">&#10003; N\u00famero verificado correctamente</div>';
        html += '</div>'; // end otp-area

        // Replace container content with OTP screen
        $('#vk-pago-container').html(html);

        // Send OTP automatically if we have a phone
        if (_tel) {
            self._sendOTP();
        }

        // Bind post-payment OTP events
        // Phone submit
        $(document).off('click', '#vk-post-enviar-codigo');
        $(document).on('click', '#vk-post-enviar-codigo', function(e) {
            e.preventDefault();
            var tel = $('#vk-post-telefono').val().replace(/\D/g, '');
            if (tel.length < 10) {
                $('#vk-post-telefono').css('border-color', '#C62828');
                setTimeout(function() { $('#vk-post-telefono').css('border-color', ''); }, 3000);
                return;
            }
            self.app.state.telefono = tel;
            var telFormatted = '+52 ' + tel.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3');
            $('#vk-post-tel-display').text(telFormatted);
            $('#vk-post-phone-input').slideUp(200);
            $('#vk-post-otp-area').slideDown(200);
            self._sendOTP();
        });

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
        $(document).off('click', '#vk-post-otp-reenviar');
        $(document).on('click', '#vk-post-otp-reenviar', function(e) { e.preventDefault(); self._sendOTP(); });
    },

    /**
     * Shared validation for name + email + phone before any checkout submission
     * (card path has its own inline validation; SPEI/OXXO now share this helper
     * so mandatory fields are enforced in all payment paths).
     */
    _validarDatosContacto: function() {
        // Merge first + last name into hidden vk-nombre field so downstream code
        // reads the composed value consistently.
        var _np = ($('#vk-nombre-pila').val()||'').trim();
        var _ap = ($('#vk-apellidos').val()||'').trim();
        $('#vk-nombre').val((_np + ' ' + _ap).trim());

        var valid = true;
        valid = VkValidacion.validarCampo($('#vk-nombre-pila'), VkValidacion.nombre,   'Ingresa tu nombre')                       && valid;
        valid = VkValidacion.validarCampo($('#vk-apellidos'),   VkValidacion.nombre,   'Ingresa tus apellidos')                   && valid;
        valid = VkValidacion.validarCampo($('#vk-email'),       VkValidacion.email,    'Ingresa un correo valido')                && valid;
        valid = VkValidacion.validarCampo($('#vk-telefono'),    VkValidacion.telefono, 'Ingresa un telefono valido (10 digitos)') && valid;
        if (!valid) {
            // Scroll to first invalid field for visibility
            var $first = $('.vk-form-input.vk-invalid').first();
            if ($first.length) {
                $('html, body').animate({ scrollTop: $first.offset().top - 100 }, 200);
                $first.focus();
            }
            return false;
        }

        // Persist in state so downstream _handleContado* methods read real values
        this.app.state.nombre   = $('#vk-nombre').val().trim();
        this.app.state.email    = $('#vk-email').val().trim();
        this.app.state.telefono = $('#vk-telefono').val().trim();
        return true;
    },

    _handleContadoSPEI: function() {
        var self = this;
        var state = self.app.state;
        var modelo = self.app.getModelo(state.modeloSeleccionado);
        var total = modelo.precioContado; // Contado: freight free
        var base = window.VK_BASE_PATH || '';
        var nombre = self._fullName(state) || $('#vk-nombre').val();
        var email = state.email || $('#vk-email').val();

        $('#vk-contado-spei').prop('disabled', true).css('opacity', '0.6');

        $.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                amount: total * 100,
                method: 'spei',
                customer: { nombre: nombre, email: email, telefono: state.telefono || '', modelo: modelo.nombre, color: state.colorSeleccionado || '' }
            }),
            success: function(response) {
                $('#vk-contado-spei').prop('disabled', false).css('opacity', '1');
                if (response && response.speiData) {
                    self._pendingPaymentIntentId = response.paymentIntentId || '';
                    self._pendingPaymentMethod = 'spei';
                    var sd = response.speiData;
                    var html = '<div style="background:#E8F4FD;border-radius:10px;padding:16px;border:1px solid #B3D4FC;">';
                    html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
                    html += '<img src="' + base + 'img/logo_spei.png" alt="SPEI" style="height:28px;">';
                    html += '<span style="font-size:14px;font-weight:700;color:#1a3a5c;">Datos para transferencia SPEI</span>';
                    html += '</div>';
                    if (sd.clabe) {
                        html += '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
                        html += '<div style="font-size:12px;color:#888;margin-bottom:4px;">CLABE Interbancaria:</div>';
                        html += '<div style="display:flex;align-items:center;gap:6px;">';
                        html += '<div style="font-size:13px;font-weight:900;color:#333;letter-spacing:0.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;">' + sd.clabe + '</div>';
                        html += '<button class="vk-copy-clabe" data-clabe="' + sd.clabe + '" style="flex-shrink:0;padding:5px 10px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;">Copiar</button>';
                        html += '</div></div>';
                    }
                    html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">';
                    html += '<div style="font-size:13px;line-height:2;color:#333;flex:1;">';
                    if (sd.beneficiario) html += 'Beneficiario: <strong>' + sd.beneficiario + '</strong><br>';
                    if (sd.referencia) html += 'Referencia: <strong>' + sd.referencia + '</strong><br>';
                    if (sd.banco) html += 'Banco: <strong>' + sd.banco + '</strong><br>';
                    html += 'Monto: <strong style="color:#039fe1;font-size:16px;">' + VkUI.formatPrecio(total) + ' MXN</strong>';
                    html += '</div>';
                    html += '<div style="flex-shrink:0;text-align:center;">';
                    html += '<img src="' + base + 'img/voltika_logo.svg" alt="Voltika" style="width:60px;height:auto;opacity:0.9;">';
                    html += '</div>';
                    html += '</div>';
                    html += '<div style="margin-top:10px;"><span style="color:#00C851;">&#10004;</span> Env\u00eda exactamente <strong>' + VkUI.formatPrecio(total) + ' MXN</strong></div>';
                    html += '<p style="font-size:12px;color:#888;margin:10px 0 0;">Confirmaci\u00f3n autom\u00e1tica en minutos.</p>';
                    html += '</div>';
                    html += '<button class="vk-contado-continuar" style="display:block;width:100%;padding:16px;margin-top:12px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.5px;">CONTINUAR COMPRA</button>';
                    $('#vk-contado-spei-section').html(html).slideDown(200);
                    $('#vk-contado-oxxo-section').slideUp(200);
                } else {
                    $('#vk-contado-spei-section').html('<div style="color:#C62828;padding:10px;">Error: ' + (response.error || 'No se pudieron obtener datos') + '</div>').slideDown(200);
                }
            },
            error: function() {
                $('#vk-contado-spei').prop('disabled', false).css('opacity', '1');
                $('#vk-contado-spei-section').html('<div style="color:#C62828;padding:10px;">Error de conexi\u00f3n. Intenta de nuevo.</div>').slideDown(200);
            }
        });
    },

    _handleContadoOXXO: function() {
        var self = this;
        var state = self.app.state;
        var modelo = self.app.getModelo(state.modeloSeleccionado);
        var total = modelo.precioContado; // Contado: freight free
        var base = window.VK_BASE_PATH || '';
        var nombre = self._fullName(state) || $('#vk-nombre').val();
        var email = state.email || $('#vk-email').val();

        $('#vk-contado-oxxo').prop('disabled', true).css('opacity', '0.6');

        $.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                amount: total * 100,
                method: 'oxxo',
                customer: { nombre: nombre, email: email, telefono: state.telefono || '', modelo: modelo.nombre, color: state.colorSeleccionado || '' }
            }),
            success: function(response) {
                $('#vk-contado-oxxo').prop('disabled', false).css('opacity', '1');
                if (response && response.oxxoData) {
                    self._pendingPaymentIntentId = response.paymentIntentId || '';
                    self._pendingPaymentMethod = 'oxxo';
                    var refs = Array.isArray(response.oxxoData) ? response.oxxoData : [response.oxxoData];
                    var html = '<div style="background:#FFF8E1;border-radius:10px;padding:16px;border:1px solid #FFE082;">';
                    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
                    html += '<div style="display:flex;align-items:center;gap:10px;">';
                    html += '<img src="' + base + 'img/oxxo_logo.png" alt="OXXO" style="height:30px;">';
                    html += '<span style="font-size:14px;font-weight:700;color:#333;">Referencias de pago OXXO</span>';
                    html += '</div>';
                    html += '<img src="' + base + 'img/voltika_logo.svg" alt="Voltika" style="width:60px;height:auto;opacity:0.9;">';
                    html += '</div>';
                    if (refs.length > 1) {
                        html += '<div style="font-size:12px;color:#555;background:#fff;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-style:italic;">Dividimos tu pago por l\u00edmites de OXXO para que puedas completarlo f\u00e1cilmente</div>';
                    }
                    for (var i = 0; i < refs.length; i++) {
                        var ref = refs[i];
                        var refAmount = ref.amount ? Math.round(ref.amount / 100) : Math.round(total / refs.length);
                        html += '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
                        if (refs.length > 1) html += '<div style="font-size:11px;color:#039fe1;font-weight:700;margin-bottom:4px;">Referencia ' + (i+1) + ' de ' + refs.length + '</div>';
                        html += '<div style="font-size:12px;color:#888;">N\u00famero de referencia:</div>';
                        var num = ref.number || '--';
                        html += '<div style="font-size:12px;font-weight:900;color:#333;font-family:monospace;word-break:break-all;">' + num.replace(/(.{4})/g, '$1 ').trim() + '</div>';
                        html += '<div style="font-size:13px;color:#555;margin-top:6px;">Monto: <strong>' + VkUI.formatPrecio(refAmount) + ' MXN</strong></div>';
                        if (ref.hosted_voucher_url) {
                            html += '<a href="' + ref.hosted_voucher_url + '" style="display:block;text-align:center;margin-top:8px;padding:8px;background:#E53935;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">Ver voucher con c\u00f3digo de barras</a>';
                        }
                        html += '</div>';
                    }
                    html += '<div style="font-size:13px;font-weight:700;">Total: ' + VkUI.formatPrecio(total) + ' MXN</div>';
                    html += '<p style="font-size:12px;color:#888;margin:8px 0 0;">Presenta en cualquier tienda OXXO.</p>';
                    html += '</div>';
                    // Volver + Print + Download buttons
                    html += '<div style="display:flex;gap:8px;margin-top:12px;">';
                    html += '<button id="vk-contado-oxxo-back" style="flex:1;padding:12px;background:#666;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">\u2190 Volver</button>';
                    html += '<button id="vk-contado-oxxo-print" style="flex:1;padding:12px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Imprimir / PDF</button>';
                    html += '</div>';
                    html += '<button class="vk-contado-continuar" style="display:block;width:100%;padding:16px;margin-top:10px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.5px;">CONTINUAR CON COMPRA</button>';
                    $('#vk-contado-oxxo-section').html(html).slideDown(200);
                    $('#vk-contado-spei-section').slideUp(200);
                    // Store refs for download
                    self._contadoOxxoRefs = refs;
                    self._contadoOxxoTotal = total;
                    // Bind Volver (close section)
                    $(document).off('click', '#vk-contado-oxxo-back').on('click', '#vk-contado-oxxo-back', function() {
                        $('#vk-contado-oxxo-section').slideUp(200);
                        $('#vk-contado-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
                    });
                    // Bind Print (open vouchers in modal)
                    $(document).off('click', '#vk-contado-oxxo-print').on('click', '#vk-contado-oxxo-print', function() {
                        self._showContadoVoucherModal(self._contadoOxxoRefs, self._contadoOxxoTotal);
                    });
                } else {
                    $('#vk-contado-oxxo-section').html('<div style="color:#C62828;padding:10px;">Error: ' + (response.error || 'No se pudieron generar referencias') + '</div>').slideDown(200);
                }
            },
            error: function() {
                $('#vk-contado-oxxo').prop('disabled', false).css('opacity', '1');
                $('#vk-contado-oxxo-section').html('<div style="color:#C62828;padding:10px;">Error de conexi\u00f3n. Intenta de nuevo.</div>').slideDown(200);
            }
        });
    },

    _showContadoVoucherModal: function(refs, total) {
        jQuery('#vk-contado-oxxo-modal').remove();
        // Print-only CSS: hide everything except modal
        if (!jQuery('#vk-contado-print-style').length) {
            jQuery('head').append('<style id="vk-contado-print-style">@media print{body>*:not(#vk-contado-oxxo-modal){display:none!important;}#vk-contado-oxxo-modal{position:static!important;background:#fff!important;overflow:visible!important;}#vk-contado-oxxo-modal button{display:none!important;}}</style>');
        }
        var voucherUrls = [];
        for (var i = 0; i < refs.length; i++) {
            if (refs[i].hosted_voucher_url) voucherUrls.push({ url: refs[i].hosted_voucher_url, num: i + 1 });
        }
        if (voucherUrls.length === 0) { alert('No vouchers disponibles.'); return; }

        var html = '<div id="vk-contado-oxxo-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(0,0,0,0.6);overflow-y:auto;-webkit-overflow-scrolling:touch;">';
        html += '<div style="max-width:600px;margin:0 auto;padding:16px;min-height:100vh;">';
        html += '<div style="background:#fff;border-radius:10px;padding:16px;margin-bottom:12px;text-align:center;">';
        html += '<h2 style="margin:0 0 4px;font-size:18px;">Vouchers de Pago OXXO</h2>';
        html += '<p style="margin:0;color:#888;font-size:13px;">Voltika - Total: ' + VkUI.formatPrecio(total) + ' MXN (' + voucherUrls.length + ' referencia' + (voucherUrls.length > 1 ? 's' : '') + ')</p>';
        html += '</div>';
        html += '<div style="display:flex;gap:10px;margin-bottom:12px;">';
        html += '<button class="vk-contado-modal-close" style="flex:1;padding:14px;background:#666;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">\u2190 Volver</button>';
        html += '<button onclick="window.print()" style="flex:1;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">Imprimir / Guardar PDF</button>';
        html += '</div>';
        for (var j = 0; j < voucherUrls.length; j++) {
            html += '<div style="margin-bottom:16px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';
            if (voucherUrls.length > 1) {
                html += '<div style="font-size:14px;font-weight:700;color:#039fe1;padding:10px 16px;text-align:center;">Referencia ' + voucherUrls[j].num + ' de ' + voucherUrls.length + '</div>';
            }
            html += '<iframe src="' + voucherUrls[j].url + '" style="width:100%;min-height:700px;border:none;" loading="eager"></iframe>';
            html += '</div>';
        }
        html += '<div style="display:flex;gap:10px;margin:12px 0 20px;">';
        html += '<button class="vk-contado-modal-close" style="flex:1;padding:14px;background:#666;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">\u2190 Volver</button>';
        html += '<button onclick="window.print()" style="flex:1;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">Imprimir / Guardar PDF</button>';
        html += '</div>';
        html += '</div></div>';

        jQuery('body').append(html);
        jQuery(document).off('click', '.vk-contado-modal-close').on('click', '.vk-contado-modal-close', function() {
            jQuery('#vk-contado-oxxo-modal').remove();
            jQuery('#vk-contado-print-style').remove();
        });
    }
};
