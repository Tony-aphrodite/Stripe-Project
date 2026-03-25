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

        html += VkUI.renderBackButton('credito-aprobado');

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
        html += '<div style="padding:18px;margin-bottom:12px;border:2.5px solid #1a3a5c;border-radius:14px;background:#f8fafd;">';
        // Header
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<span style="font-size:22px;">&#128179;</span>';
        html += '<span style="font-size:15px;font-weight:700;color:#1a3a5c;">Tarjeta de cr\u00e9dito / d\u00e9bito</span>';
        html += '</div>';
        html += '<span>' + VkUI.renderCardLogos() + '</span>';
        html += '</div>';
        // Benefits
        html += '<div style="margin-bottom:14px;">';
        html += '<div style="font-size:15px;margin-bottom:10px;font-weight:700;"><span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#00C851;color:#fff;font-size:12px;">&#10003;</span> <strong style="color:#00C851;">Forma m\u00e1s r\u00e1pida</strong> de asegurar tu Voltika</div>';
        html += '<div style="font-size:14px;margin-bottom:6px;"><span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#039fe1;color:#fff;font-size:11px;font-weight:700;">&#10148;</span> Aparta tu Voltika <strong>en segundos</strong></div>';
        html += '<div style="font-size:14px;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><span style="color:#00C851;font-size:16px;">&#10004;</span> Pago <strong>inmediato y seguro</strong></div>';
        html += '<div style="font-size:14px;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><span style="color:#00C851;font-size:16px;">&#10004;</span> Confirmaci\u00f3n <strong>al instante</strong></div>';
        html += '</div>';
        // Stripe card element mount point
        html += '<div id="vk-enganche-card-element" style="padding:12px;border:1px solid #ddd;border-radius:8px;margin-bottom:10px;background:#fff;"></div>';
        html += '<div id="vk-enganche-card-errors" style="display:none;color:#C62828;font-size:12px;margin-bottom:8px;"></div>';
        html += '<div id="vk-enganche-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:10px;"></div>';
        // Protected badge
        html += '<div style="display:flex;align-items:center;gap:6px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;">';
        html += '<span style="font-size:16px;">&#128274;</span> <span style="font-size:13px;font-weight:600;color:#1a3a5c;">Pago <strong>100% protegido</strong></span>';
        html += '</div>';
        // Pay button
        html += '<button class="vk-btn vk-btn--primary" id="vk-enganche-pagar" style="font-size:16px;font-weight:800;padding:16px;border-radius:10px;">';
        html += '<span class="vk-pay-btn__label">Pagar en segundos</span>';
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

        // SPEI payment (toggle)
        jQuery(document).on('click', '#vk-enganche-spei', function(e) {
            e.preventDefault();
            // If already showing SPEI, toggle off
            if (jQuery('#vk-spei-section').is(':visible') && jQuery('#vk-spei-section').html().length > 10) {
                jQuery('#vk-spei-section').slideUp(200);
                jQuery('#vk-enganche-spei').css({ 'border-color': '#ccc', 'background': '#fff' });
                return;
            }
            jQuery('#vk-enganche-spei').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            jQuery('#vk-enganche-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
            jQuery('#vk-oxxo-section').slideUp(200);
            self._handleSPEI();
        });

        // OXXO payment (toggle)
        jQuery(document).on('click', '#vk-enganche-oxxo', function(e) {
            e.preventDefault();
            // If already showing OXXO, toggle off
            if (jQuery('#vk-oxxo-section').is(':visible') && jQuery('#vk-oxxo-section').html().length > 10) {
                jQuery('#vk-oxxo-section').slideUp(200);
                jQuery('#vk-enganche-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
                return;
            }
            jQuery('#vk-enganche-oxxo').css({ 'border-color': '#039fe1', 'background': '#E8F4FD' });
            jQuery('#vk-enganche-spei').css({ 'border-color': '#ccc', 'background': '#fff' });
            jQuery('#vk-spei-section').slideUp(200);
            self._handleOXXO();
        });

        // Close buttons for SPEI/OXXO results
        jQuery(document).off('click', '.vk-close-payment-result');
        jQuery(document).on('click', '.vk-close-payment-result', function(e) {
            e.preventDefault();
            var target = jQuery(this).data('target');
            jQuery('#' + target).slideUp(200);
            jQuery('#vk-enganche-spei, #vk-enganche-oxxo').css({ 'border-color': '#ccc', 'background': '#fff' });
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
        // Update SPEI button to selected state
        jQuery('#vk-enganche-spei').prop('disabled', false)
            .html('&#10003; SPEI Transferencia')
            .css({ 'background': '#00C851', 'color': '#fff', 'border-color': '#00C851', 'opacity': '1' });
        var base = window.VK_BASE_PATH || '';
        var html = '<div style="background:#E8F4FD;border-radius:10px;padding:16px;border:1px solid #B3D4FC;">';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:center;gap:10px;">';
        html += '<img src="' + base + 'img/logo_spei.png" alt="SPEI" style="height:28px;">';
        html += '<span style="font-size:14px;font-weight:700;color:#1a3a5c;">Datos para transferencia SPEI</span>';
        html += '</div>';
        html += '<button class="vk-close-payment-result" data-target="vk-spei-section" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;padding:0 4px;">&times;</button>';
        html += '</div>';
        // CLABE highlighted with copy button
        if (speiData.clabe) {
            html += '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
            html += '<div style="font-size:12px;color:#888;margin-bottom:4px;">CLABE Interbancaria:</div>';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';
            html += '<div id="vk-spei-clabe-text" style="font-size:18px;font-weight:900;color:#333;letter-spacing:1px;">' + speiData.clabe + '</div>';
            html += '<button id="vk-spei-copy-clabe" data-clabe="' + speiData.clabe + '" style="flex-shrink:0;padding:6px 12px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Copiar</button>';
            html += '</div>';
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
        // Checkmarks
        html += '<div style="margin-top:12px;">';
        html += '<div style="font-size:13px;margin-bottom:4px;display:flex;align-items:center;gap:6px;"><span style="color:#00C851;">&#10004;</span> Env\u00eda exactamente <strong>' + VkUI.formatPrecio(enganche) + ' MXN</strong> desde cualquier banco</div>';
        html += '<div style="font-size:13px;display:flex;align-items:center;gap:6px;"><span style="color:#00C851;">&#10004;</span> Guarda el comprobante y espera la confirmaci\u00f3n autom\u00e1tica.</div>';
        html += '</div>';
        html += '</div>';
        // Continue button
        html += '<button id="vk-spei-continuar" style="display:block;width:100%;padding:16px;margin-top:14px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.5px;">CONTINUAR CON COMPRA</button>';
        jQuery('#vk-spei-section').html(html).show();
        var self = this;
        // Copy CLABE to clipboard
        jQuery(document).off('click', '#vk-spei-copy-clabe').on('click', '#vk-spei-copy-clabe', function() {
            var clabe = jQuery(this).data('clabe');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(clabe).then(function() {
                    jQuery('#vk-spei-copy-clabe').text('\u2713 Copiado').css('background', '#00C851');
                    setTimeout(function() { jQuery('#vk-spei-copy-clabe').text('Copiar').css('background', '#039fe1'); }, 2000);
                });
            } else {
                // Fallback for older browsers
                var ta = document.createElement('textarea');
                ta.value = clabe; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                jQuery('#vk-spei-copy-clabe').text('\u2713 Copiado').css('background', '#00C851');
                setTimeout(function() { jQuery('#vk-spei-copy-clabe').text('Copiar').css('background', '#039fe1'); }, 2000);
            }
        });
        // Continue to next step
        jQuery(document).off('click', '#vk-spei-continuar').on('click', '#vk-spei-continuar', function() {
            self.app.irAPaso('credito-contrato');
        });
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
        // Update OXXO button to selected state
        jQuery('#vk-enganche-oxxo').prop('disabled', false)
            .html('&#10003; OXXO Efectivo')
            .css({ 'background': '#00C851', 'color': '#fff', 'border-color': '#00C851', 'opacity': '1' });
        var base = window.VK_BASE_PATH || '';
        var refs = Array.isArray(oxxoData) ? oxxoData : [oxxoData];
        var html = '<div id="vk-oxxo-voucher" style="background:#FFF8E1;border-radius:10px;padding:16px;margin-top:12px;border:1px solid #FFE082;">';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:center;gap:10px;">';
        html += '<img src="' + base + 'img/oxxo_logo.png" alt="OXXO" style="height:30px;">';
        html += '<span style="font-size:14px;font-weight:700;color:#333;">Referencia' + (refs.length > 1 ? 's' : '') + ' de pago OXXO</span>';
        html += '</div>';
        html += '<button class="vk-close-payment-result" data-target="vk-oxxo-section" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;padding:0 4px;">&times;</button>';
        html += '</div>';
        if (refs.length > 1) {
            html += '<div style="font-size:12px;color:#555;background:#fff;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-style:italic;">Dividimos tu pago por l\u00edmites de OXXO para que puedas completarlo f\u00e1cilmente</div>';
        }
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
                html += '<a href="' + ref.hosted_voucher_url + '" ' +
                    'style="display:block;text-align:center;margin-top:10px;padding:10px;background:#E53935;color:#fff;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;">' +
                    '&#128179; Ver voucher con c\u00f3digo de barras</a>';
            }
            html += '</div>';
        }
        html += '<div style="font-size:13px;color:#555;font-weight:700;">Total: <strong>' + VkUI.formatPrecio(enganche) + ' MXN</strong></div>';
        html += '<p style="font-size:12px;color:#888;margin:10px 0 0;">Presenta el voucher con c\u00f3digo de barras en cualquier tienda OXXO. Confirmaci\u00f3n autom\u00e1tica al pagar.</p>';
        html += '</div>';
        // Download PDF button
        html += '<button id="vk-oxxo-download-pdf" style="display:block;width:100%;padding:14px;margin-top:12px;background:#E53935;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">&#128196; Descargar referencias PDF</button>';
        // Continue button
        html += '<button id="vk-oxxo-continuar" style="display:block;width:100%;padding:16px;margin-top:10px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.5px;">CONTINUAR CON COMPRA</button>';
        jQuery('#vk-oxxo-section').html(html).show();
        var self = this;
        // Download PDF
        jQuery(document).off('click', '#vk-oxxo-download-pdf').on('click', '#vk-oxxo-download-pdf', function() {
            self._downloadOXXOPDF(refs, enganche);
        });
        // Continue to next step
        jQuery(document).off('click', '#vk-oxxo-continuar').on('click', '#vk-oxxo-continuar', function() {
            self.app.irAPaso('credito-contrato');
        });
    },

    _downloadOXXOPDF: function(refs, enganche) {
        // Save current URL for Volver button
        window._vkReturnUrl = window.location.href;
        // Collect all hosted voucher URLs
        var voucherUrls = [];
        for (var i = 0; i < refs.length; i++) {
            if (refs[i].hosted_voucher_url) voucherUrls.push({ url: refs[i].hosted_voucher_url, num: i + 1 });
        }

        if (voucherUrls.length === 0) {
            alert('No se encontraron vouchers con c\u00f3digo de barras.');
            return;
        }

        // If only 1 voucher, show in same page with back button
        if (voucherUrls.length === 1) {
            this._showVoucherPage(voucherUrls, enganche);
            return;
        }

        // Multiple vouchers — show index page with links to each
        this._showVoucherPage(voucherUrls, enganche);
    },

    _showVoucherPage: function(voucherUrls, enganche) {
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vouchers OXXO - Voltika</title>';
        html += '<style>';
        html += 'body{font-family:Arial,sans-serif;margin:0;padding:20px;background:#f5f5f5;}';
        html += '.header{text-align:center;margin-bottom:20px;padding:16px;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.05);}';
        html += '.voucher-link{display:block;width:100%;padding:16px;margin-bottom:12px;background:#FFF8E1;border:2px solid #FFE082;border-radius:10px;text-align:center;font-size:16px;font-weight:700;color:#333;text-decoration:none;cursor:pointer;}';
        html += '.voucher-link:hover{background:#FFF3C4;}';
        html += '.voucher-frame{width:100%;min-height:700px;border:none;margin-bottom:16px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);}';
        html += '.actions{text-align:center;margin:16px 0;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}';
        html += '.actions button,.actions a{padding:14px 24px;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;}';
        html += '.btn-back{background:#666;color:#fff;}';
        html += '.btn-print{background:#039fe1;color:#fff;}';
        html += '.btn-nav{background:#1a3a5c;color:#fff;display:block;width:100%;text-align:center;margin-bottom:10px;padding:14px;}';
        html += '@media print{.actions,.nav-section{display:none!important;}.voucher-frame{break-after:page;min-height:95vh;}}';
        html += '</style></head><body>';

        // Header
        html += '<div class="header">';
        html += '<h2 style="margin:0 0 4px;color:#333;">Vouchers de Pago OXXO</h2>';
        html += '<p style="margin:0;color:#888;font-size:13px;">Voltika - Total: $' + Math.round(enganche).toLocaleString('es-MX') + ' MXN (' + voucherUrls.length + ' referencia' + (voucherUrls.length > 1 ? 's' : '') + ')</p>';
        if (voucherUrls.length > 1) {
            html += '<p style="margin:4px 0 0;font-size:12px;color:#555;">Dividimos tu pago por l\u00edmites de OXXO para que puedas completarlo f\u00e1cilmente</p>';
        }
        html += '</div>';

        // Top actions
        html += '<div class="actions">';
        html += '<button class="btn-back" onclick="window.location.href=window._vkReturnUrl||\u0027\u0027">\u2190 Volver</button>';
        html += '<button class="btn-print" onclick="window.print()">Imprimir / Guardar PDF</button>';
        html += '</div>';

        // All vouchers as iframes (each in its own section)
        for (var j = 0; j < voucherUrls.length; j++) {
            html += '<div style="margin-bottom:20px;">';
            if (voucherUrls.length > 1) {
                html += '<div style="font-size:14px;font-weight:700;color:#039fe1;margin-bottom:8px;text-align:center;">Referencia ' + voucherUrls[j].num + ' de ' + voucherUrls.length + '</div>';
            }
            html += '<iframe class="voucher-frame" src="' + voucherUrls[j].url + '" loading="eager"></iframe>';
            html += '</div>';
        }

        // Navigation links between vouchers — open in wrapper page with back/print buttons
        if (voucherUrls.length > 1) {
            html += '<div class="nav-section" style="margin:20px 0;">';
            html += '<div style="font-size:14px;font-weight:700;color:#333;text-align:center;margin-bottom:10px;">Abrir cada referencia por separado:</div>';
            for (var k = 0; k < voucherUrls.length; k++) {
                html += '<a href="#" onclick="window._vkOpenSingleRef(\u0027' + voucherUrls[k].url + '\u0027,' + voucherUrls[k].num + ',' + voucherUrls.length + ');return false;" class="btn-nav">Referencia ' + voucherUrls[k].num + ' de ' + voucherUrls.length + ' \u203a</a>';
            }
            html += '</div>';
        }

        // Script for opening individual reference in wrapper
        html += '<script>';
        html += 'window._vkOpenSingleRef=function(url,num,total){';
        html += 'var h="<!DOCTYPE html><html><head><meta charset=utf-8><meta name=viewport content=\\"width=device-width,initial-scale=1\\"><title>Referencia "+num+" de "+total+"</title>';
        html += '<style>body{margin:0;font-family:Inter,Arial,sans-serif}.top-bar{position:sticky;top:0;z-index:10;background:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #eee;box-shadow:0 2px 8px rgba(0,0,0,0.08)}.top-bar .title{font-size:15px;font-weight:700;color:#1a3a5c}.btn-back2{background:#f5f5f5;border:1.5px solid #ccc;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer;color:#333}.btn-print2{background:#039fe1;color:#fff;border:none;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer}iframe{width:100%;height:calc(100vh - 60px);border:none}@media print{.top-bar{display:none}iframe{height:auto}}</style>';
        html += '</head><body>";';
        html += 'h+="<div class=top-bar><button class=btn-back2 onclick=\\"history.back()\\">\\u2190 Volver</button><span class=title>Referencia "+num+" de "+total+"</span><button class=btn-print2 onclick=\\"window.print()\\">Imprimir</button></div>";';
        html += 'h+="<iframe src=\\""+url+"\\" allowfullscreen></iframe>";';
        html += 'h+="</body></html>";';
        html += 'document.open();document.write(h);document.close();';
        html += '};';
        html += '<\/script>';

        // Bottom actions
        html += '<div class="actions">';
        html += '<button class="btn-back" onclick="window.location.href=window._vkReturnUrl||\u0027\u0027">\u2190 Volver</button>';
        html += '<button class="btn-print" onclick="window.print()">Imprimir / Guardar PDF</button>';
        html += '</div>';

        html += '</body></html>';

        // Replace current page content
        document.open();
        document.write(html);
        document.close();
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
