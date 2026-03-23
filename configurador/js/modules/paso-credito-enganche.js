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

        // OXXO references (limit $10,000 per operation)
        var oxxoLimit = 10000;
        var numRefs = Math.ceil(enganche / oxxoLimit);
        var oxxoRefs = [];
        var oxxoRemaining = enganche;
        for (var j = 0; j < numRefs; j++) {
            var refAmount = Math.min(oxxoRemaining, oxxoLimit);
            oxxoRefs.push(refAmount);
            oxxoRemaining -= refAmount;
        }

        var html = '';

        html += VkUI.renderBackButton('resumen');

        // Header
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:4px;">Tu Voltika est\u00e1 lista</h2>';
        html += '<p style="font-size:14px;color:var(--vk-text-secondary);margin:0;">Paga tu <strong>enganche</strong> para reservarla.</p>';
        html += '</div>';

        // Model summary card
        html += '<div class="vk-card" style="border-radius:14px;padding:16px;margin-bottom:20px;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<img src="' + base + motoImg + '" alt="Voltika ' + modelo.nombre + '" ' +
            'style="width:110px;height:auto;flex-shrink:0;">';
        html += '<div style="flex:1;min-width:0;">';
        var _logoMap = {'m05':'menu_m05_tx.svg','m03':'menu_m03_tx.svg','ukko-s':'menu_ukko_tx.svg','mc10':'menu_mc10_tx.svg','pesgo-plus':'menu_pesgo_tx.svg','mino':'menu_mino_tx.svg'};
        var _logoFile = _logoMap[modelo.id];
        if (_logoFile) {
            html += '<div style="margin-bottom:6px;"><img src="' + base + 'img/' + _logoFile + '" alt="' + modelo.nombre + '" style="height:22px;width:auto;"></div>';
        } else {
            html += '<div style="font-size:16px;font-weight:800;margin-bottom:6px;">Voltika ' + modelo.nombre + '</div>';
        }
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);line-height:1.7;">';
        html += 'Modelo: <strong style="color:var(--vk-text-primary);">Voltika ' + modelo.nombre + '</strong><br>';
        html += '&#8226; Color: <strong style="color:var(--vk-text-primary);">' + (colorNombre || colorId) + '</strong><br>';
        html += '&#8226; Pago semanal: <strong style="color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong> MXN<br>';
        html += '&#8226; Plazo: <strong style="color:var(--vk-text-primary);">' + plazoSemanas + ' semanas</strong><br>';
        html += '<span style="font-weight:700;color:var(--vk-text-primary);">Fecha M\u00e1xima de entrega</span><br>';
        html += '<strong style="color:#039fe1;font-size:16px;">' + entregaStr + '</strong>';
        html += '</div>';
        // Show selected delivery center or city
        var centroNombre = (state.centroEntrega && state.centroEntrega.nombre) ? state.centroEntrega.nombre : '';
        var entregaUbicacion = centroNombre || ((state.ciudad || '') + (state.estado ? ', ' + state.estado : ''));
        html += '<div style="font-size:11px;color:var(--vk-text-muted);margin-top:6px;">';
        if (entregaUbicacion) {
            html += 'Entrega en <strong style="color:var(--vk-text-primary);">' + entregaUbicacion + '</strong>';
        } else {
            html += 'En un punto autorizado <strong style="color:var(--vk-text-primary);">Voltika</strong> cerca de ti';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Enganche amount
        html += '<div style="text-align:center;margin:12px 0 16px;">';
        html += '<div style="font-size:16px;font-weight:800;color:var(--vk-text-primary);letter-spacing:0.5px;text-transform:uppercase;">ENGANCHE A PAGAR</div>';
        html += '<div style="font-size:32px;font-weight:900;color:var(--vk-green-primary);margin-top:6px;">' +
            VkUI.formatPrecio(enganche) + ' MXN</div>';
        html += '</div>';

        // Payment methods section
        html += '<div style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin-bottom:12px;">Selecciona el m\u00e9todo de pago</div>';

        // === 1. Tarjeta de crédito / débito ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
        html += '<span style="font-size:14px;font-weight:600;">Tarjeta de cr\u00e9dito / d\u00e9bito</span>';
        html += '<span>' + VkUI.renderCardLogos() + '</span>';
        html += '</div>';
        // Stripe card element mount point
        html += '<div id="vk-enganche-card-element" style="padding:12px;border:1px solid #ddd;border-radius:8px;margin-bottom:10px;background:#fff;"></div>';
        html += '<div id="vk-enganche-card-errors" style="display:none;color:#C62828;font-size:12px;margin-bottom:8px;"></div>';
        html += '<div id="vk-enganche-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:10px;"></div>';
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-pagar">';
        html += '<span class="vk-pay-btn__label">PAGAR CON TARJETA</span>';
        html += '<span class="vk-pay-btn__spinner" style="display:none;">' +
            VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';
        html += '</div>';

        // === 2. Alternative payment methods (SPEI + OXXO) ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;background:#f8f9fa;border:1.5px solid #e2e8f0;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
        html += '<span style="font-size:20px;">&#128161;</span>';
        html += '<span style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">\u00bfNo quieres usar tarjeta?</span>';
        html += '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:14px;">Puedes pagar sin tarjeta de forma segura</div>';
        html += '<div style="display:flex;gap:10px;">';
        // SPEI button
        html += '<button id="vk-enganche-spei" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;border-radius:10px;border:1.5px solid #ccc;background:#fff;cursor:pointer;transition:all 0.2s;">';
        html += '<img src="' + base + 'img/logo_spei.png" alt="SPEI" style="height:28px;">';
        html += '<span style="font-size:13px;font-weight:700;color:#1a3a5c;">SPEI<br><span style="font-weight:400;font-size:11px;color:#666;">Transferencia</span></span>';
        html += '</button>';
        // OXXO button
        html += '<button id="vk-enganche-oxxo" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;border-radius:10px;border:1.5px solid #ccc;background:#fff;cursor:pointer;transition:all 0.2s;">';
        html += '<img src="' + base + 'img/oxxo_logo.png" alt="OXXO" style="height:28px;">';
        html += '<span style="font-size:13px;font-weight:700;color:#1a3a5c;">OXXO<br><span style="font-weight:400;font-size:11px;color:#666;">Efectivo</span></span>';
        html += '</button>';
        html += '</div>';
        // Hidden sections for SPEI/OXXO results
        html += '<div id="vk-spei-section" style="display:none;margin-top:14px;"></div>';
        html += '<div id="vk-oxxo-section" style="display:none;margin-top:14px;">';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);background:var(--vk-bg-light);border-radius:6px;padding:10px;margin-bottom:10px;">';
        html += 'Por el l\u00edmite de <strong>$10,000</strong> por operaci\u00f3n en OXXO<br>se generar\u00e1n <strong>' + numRefs + ' referencias</strong> de pago:';
        html += '</div>';
        html += '</div>';
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

        jQuery(document).off('click', '#vk-enganche-pagar')
            .off('click', '#vk-enganche-spei')
            .off('click', '#vk-enganche-oxxo');

        // Card payment
        jQuery(document).on('click', '#vk-enganche-pagar', function(e) {
            e.preventDefault();
            self._handlePayment();
        });

        // SPEI payment
        jQuery(document).on('click', '#vk-enganche-spei', function(e) {
            e.preventDefault();
            jQuery('#vk-enganche-spei').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            jQuery('#vk-enganche-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
            self._handleSPEI();
        });

        // OXXO payment
        jQuery(document).on('click', '#vk-enganche-oxxo', function(e) {
            e.preventDefault();
            jQuery('#vk-enganche-oxxo').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            jQuery('#vk-enganche-spei').css({ 'border-color': '#ccc', 'background': '#fff' });
            self._handleOXXO();
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

    _getEngancheData: function() {
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);
        return {
            enganche: credito.enganche,
            amountCents: Math.round(credito.enganche * 100),
            modelo: modelo,
            customer: {
                nombre:   state.nombre,
                email:    state.email,
                telefono: state.telefono,
                modelo:   modelo.nombre,
                color:    state.colorSeleccionado || modelo.colorDefault,
                ciudad:   state.ciudad || '',
                estado:   state.estado || '',
                cp:       state.codigoPostal || ''
            }
        };
    },

    _handleSPEI: function() {
        var self = this;
        var data = self._getEngancheData();

        jQuery('#vk-enganche-spei').prop('disabled', true).text('PROCESANDO...');
        jQuery('#vk-enganche-error').hide();

        jQuery.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            timeout: 15000,
            data: JSON.stringify({
                amount:       data.amountCents,
                method:       'spei',
                installments: false,
                msiMeses:     0,
                customer:     data.customer,
                tipo:         'enganche'
            }),
            success: function(response) {
                if (response && response.speiData) {
                    self._showSPEIDetails(response.speiData, data.enganche);
                } else if (response && response.error) {
                    self._showSPEIError('Error: ' + response.error);
                } else {
                    self._showSPEIError('No se pudieron obtener los datos bancarios. Intenta de nuevo.');
                }
            },
            error: function(xhr) {
                var errMsg = 'Error de conexi\u00f3n.';
                try { errMsg = JSON.parse(xhr.responseText).error || errMsg; } catch(e) {}
                console.error('SPEI error:', errMsg, xhr.status, xhr.responseText);
                self._showSPEIError(errMsg);
            }
        });
    },

    _showSPEIError: function(msg) {
        var html = '<div style="background:#FFEBEE;border-radius:10px;padding:16px;border:1px solid #E53935;">';
        html += '<div style="font-size:14px;font-weight:700;color:#C62828;margin-bottom:8px;">&#9888; Error al obtener datos SPEI</div>';
        html += '<p style="font-size:13px;color:#555;margin:0 0 12px;">' + msg + '</p>';
        html += '<button id="vk-enganche-spei-retry" style="display:block;width:100%;padding:12px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Intentar de nuevo</button>';
        html += '</div>';
        jQuery('#vk-spei-section').html(html).show();
        var self = this;
        jQuery(document).off('click', '#vk-enganche-spei-retry').on('click', '#vk-enganche-spei-retry', function() {
            self._handleSPEI();
        });
    },

    _showSPEIDetails: function(speiData, enganche) {
        var base = window.VK_BASE_PATH || '';
        var html = '<div style="background:#E8F4FD;border-radius:10px;padding:16px;border:1px solid #B3D4FC;">';
        html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
        html += '<img src="' + base + 'img/logo_spei.png" alt="SPEI" style="height:28px;">';
        html += '<span style="font-size:14px;font-weight:700;color:#1a3a5c;">Datos para transferencia SPEI</span>';
        html += '</div>';
        // CLABE highlighted
        if (speiData.clabe) {
            html += '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
            html += '<div style="font-size:12px;color:#888;margin-bottom:4px;">CLABE Interbancaria:</div>';
            html += '<div style="font-size:18px;font-weight:900;color:#333;letter-spacing:1px;">' + speiData.clabe + '</div>';
            html += '</div>';
        }
        html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">';
        html += '<div style="font-size:13px;line-height:2;color:#333;flex:1;">';
        if (speiData.beneficiario) html += 'Beneficiario: <strong>' + speiData.beneficiario + '</strong><br>';
        if (speiData.referencia) html += 'Referencia: <strong>' + speiData.referencia + '</strong><br>';
        if (speiData.banco) html += 'Banco: <strong>' + speiData.banco + '</strong><br>';
        html += 'Monto: <strong style="color:#039fe1;font-size:16px;">' + VkUI.formatPrecio(enganche) + ' MXN</strong>';
        html += '</div>';
        html += '<div style="flex-shrink:0;text-align:center;">';
        html += '<img src="' + base + 'img/voltika_logo.svg" alt="Voltika" style="width:60px;height:auto;opacity:0.9;">';
        html += '</div>';
        html += '</div>';
        html += '<p style="font-size:12px;color:#888;margin:10px 0 0;">Confirmaci\u00f3n autom\u00e1tica en minutos despu\u00e9s de recibir la transferencia.</p>';
        html += '</div>';
        jQuery('#vk-spei-section').html(html).show();
    },

    _handleOXXO: function() {
        var self = this;
        var data = self._getEngancheData();

        jQuery('#vk-enganche-oxxo').prop('disabled', true).text('Generando referencias...');
        jQuery('#vk-enganche-error').hide();

        jQuery.ajax({
            url: self.PAYMENT_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            timeout: 30000,
            data: JSON.stringify({
                amount:       data.amountCents,
                method:       'oxxo',
                installments: false,
                msiMeses:     0,
                customer:     data.customer,
                tipo:         'enganche'
            }),
            success: function(response) {
                if (response && response.oxxoData && response.oxxoData.length > 0) {
                    self._showOXXOVoucher(response.oxxoData, data.enganche);
                } else if (response && response.error) {
                    self._showOXXOError('Error: ' + response.error);
                } else {
                    self._showOXXOError('No se pudo generar la referencia. Intenta de nuevo.');
                }
            },
            error: function(xhr) {
                var errMsg = 'Error de conexi\u00f3n.';
                try { errMsg = JSON.parse(xhr.responseText).error || errMsg; } catch(e) {}
                console.error('OXXO error:', errMsg, xhr.status, xhr.responseText);
                self._showOXXOError(errMsg);
            }
        });
    },

    _showOXXOVoucher: function(oxxoData, enganche) {
        var base = window.VK_BASE_PATH || '';
        var refs = Array.isArray(oxxoData) ? oxxoData : [oxxoData];
        var html = '<div id="vk-oxxo-voucher" style="background:#FFF8E1;border-radius:10px;padding:16px;margin-top:12px;border:1px solid #FFE082;">';
        html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
        html += '<img src="' + base + 'img/oxxo_logo.png" alt="OXXO" style="height:30px;">';
        html += '<span style="font-size:14px;font-weight:700;color:#333;">Referencia' + (refs.length > 1 ? 's' : '') + ' de pago OXXO</span>';
        html += '</div>';
        for (var i = 0; i < refs.length; i++) {
            var ref = refs[i];
            var refAmount = ref.amount ? Math.round(ref.amount / 100) : Math.round(enganche / refs.length);
            html += '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;overflow:hidden;">';
            if (refs.length > 1) {
                html += '<div style="font-size:11px;color:#039fe1;font-weight:700;margin-bottom:4px;">Referencia ' + (i + 1) + ' de ' + refs.length + '</div>';
            }
            html += '<div style="font-size:12px;color:#888;margin-bottom:4px;">N\u00famero de referencia:</div>';
            // Format number in groups of 4 for readability
            var num = ref.number || '--';
            var formatted = num.replace(/(.{4})/g, '$1 ').trim();
            html += '<div style="font-size:12px;font-weight:900;color:#333;font-family:monospace;line-height:1.6;word-break:break-all;">' + formatted + '</div>';
            html += '<div style="font-size:13px;color:#555;margin-top:6px;">Monto: <strong>' + VkUI.formatPrecio(refAmount) + ' MXN</strong></div>';
            if (ref.expires_after) {
                var exp = new Date(ref.expires_after * 1000);
                html += '<div style="font-size:12px;color:#888;margin-top:2px;">Vence: <strong>' + exp.toLocaleDateString('es-MX') + '</strong></div>';
            }
            // Stripe hosted voucher link (with barcode)
            if (ref.hosted_voucher_url) {
                html += '<a href="' + ref.hosted_voucher_url + '" target="_blank" rel="noopener" ' +
                    'style="display:block;text-align:center;margin-top:10px;padding:10px;background:#E53935;color:#fff;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;">' +
                    '&#128179; Ver voucher con c\u00f3digo de barras</a>';
            }
            html += '</div>';
        }
        html += '<div style="font-size:13px;color:#555;font-weight:700;">Total: <strong>' + VkUI.formatPrecio(enganche) + ' MXN</strong></div>';
        html += '<p style="font-size:12px;color:#888;margin:10px 0 0;">Presenta el voucher con c\u00f3digo de barras en cualquier tienda OXXO. Confirmaci\u00f3n autom\u00e1tica al pagar.</p>';
        html += '</div>';
        jQuery('#vk-oxxo-section').html(html).show();
    },

    _downloadOXXOPDF: function(refs, enganche) {
        // Not used anymore — Stripe hosted voucher replaces custom PDF
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Referencias OXXO - Voltika</title>';
        html += '<style>body{font-family:Arial,sans-serif;padding:30px;max-width:500px;margin:0 auto;}';
        html += '.ref-box{border:2px solid #FFE082;border-radius:10px;padding:20px;margin-bottom:16px;background:#FFF8E1;}';
        html += '.ref-num{font-size:16px;font-weight:900;color:#333;word-break:break-all;letter-spacing:1px;margin:8px 0;}';
        html += '.ref-label{font-size:12px;color:#888;}';
        html += '.ref-amount{font-size:14px;color:#555;margin-top:6px;}';
        html += '.header{text-align:center;margin-bottom:24px;}';
        html += '.total{font-size:16px;font-weight:800;text-align:center;margin:16px 0;padding:12px;background:#FFF8E1;border-radius:8px;}';
        html += '.footer{font-size:12px;color:#888;text-align:center;margin-top:20px;}';
        html += '@media print{button{display:none!important;}}</style></head><body>';

        html += '<div class="header">';
        html += '<h2 style="margin:0;color:#333;">Referencias de Pago OXXO</h2>';
        html += '<p style="color:#888;font-size:13px;">Voltika - Pago de enganche</p>';
        html += '</div>';

        for (var i = 0; i < refs.length; i++) {
            var ref = refs[i];
            var refAmount = ref.amount ? Math.round(ref.amount / 100) : Math.round(enganche / refs.length);
            html += '<div class="ref-box">';
            if (refs.length > 1) {
                html += '<div style="font-size:12px;color:#039fe1;font-weight:700;">Referencia ' + (i + 1) + ' de ' + refs.length + '</div>';
            }
            html += '<div class="ref-label">N\u00famero de referencia:</div>';
            html += '<div class="ref-num">' + (ref.number || '--') + '</div>';
            html += '<div class="ref-amount">Monto: <strong>$' + refAmount.toLocaleString('es-MX') + ' MXN</strong></div>';
            if (ref.expires_after) {
                var exp = new Date(ref.expires_after * 1000);
                html += '<div style="font-size:12px;color:#888;margin-top:4px;">Vence: <strong>' + exp.toLocaleDateString('es-MX') + '</strong></div>';
            }
            html += '</div>';
        }

        html += '<div class="total">Total a pagar: <strong>$' + Math.round(enganche).toLocaleString('es-MX') + ' MXN</strong></div>';
        html += '<div class="footer">';
        html += '<p>Presenta cada referencia en cualquier tienda OXXO.</p>';
        html += '<p>Confirmaci\u00f3n autom\u00e1tica al pagar.</p>';
        html += '<p style="margin-top:12px;color:#039fe1;font-weight:700;">voltika.mx</p>';
        html += '</div>';
        html += '<div style="text-align:center;margin-top:20px;"><button onclick="window.print()" style="padding:12px 30px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Imprimir / Guardar PDF</button></div>';
        html += '</body></html>';

        var blob = new Blob([html], { type: 'text/html' });
        var url = URL.createObjectURL(blob);
        var win = window.open(url, '_blank');
        if (win) {
            win.onload = function() {
                setTimeout(function() { win.print(); }, 500);
            };
        } else {
            // Popup blocked — download as file
            var a = document.createElement('a');
            a.href = url;
            a.download = 'Referencias_OXXO_Voltika.html';
            a.click();
        }
    },

    _showOXXOError: function(msg) {
        var html = '<div style="background:#FFEBEE;border-radius:10px;padding:16px;margin-top:12px;border:1px solid #E53935;">';
        html += '<div style="font-size:14px;font-weight:700;color:#C62828;margin-bottom:8px;">&#9888; Error al generar referencia OXXO</div>';
        html += '<p style="font-size:13px;color:#555;margin:0 0 12px;">' + msg + '</p>';
        html += '<button id="vk-enganche-oxxo-retry" style="display:block;width:100%;padding:12px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">Intentar de nuevo</button>';
        html += '</div>';
        jQuery('#vk-oxxo-section').html(html).show();
        var self = this;
        jQuery(document).off('click', '#vk-enganche-oxxo-retry').on('click', '#vk-enganche-oxxo-retry', function() {
            self._handleOXXO();
        });
    },

    _showError: function(msg) {
        var friendlyMsg = 'Tu banco rechaz\u00f3 el cargo, por favor intenta con otra tarjeta.';
        jQuery('#vk-enganche-error').text(friendlyMsg).slideDown(200);
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
