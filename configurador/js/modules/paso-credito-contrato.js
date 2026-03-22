/* ==========================================================================
   Voltika - Credito: Contrato y Firma Digital
   Image 2: Post-enganche contract signing screen
   Flow: enganche → contrato → autopago → exito
   - "Tu Voltika esta apartada" + enganche confirmation
   - Blue header "Tu financiamiento fue aprobado" + white body with moto/calendar
   - OTP confirmation (6-digit code sent to state.telefono)
   - Contract acceptance checkbox + Ver contrato modal
   - "Confirmar mi financiamiento" button
   - Info bullets + security key + next step info
   ========================================================================== */

var PasoCreditoContrato = {

    init: function(app) {
        this.app = app;
        // Verify previous step completed
        if (!app.state.enganchePagado) {
            console.warn('PasoCreditoContrato: enganche not paid, state may be incomplete');
        }
        this.render();
        this.bindEvents();
    },

    _calcFechaEntregaShort: function() {
        var d = new Date();
        d.setDate(d.getDate() + 15);
        var dias = ['Domingo','Lunes','Martes','Mi\u00e9rcoles','Jueves','Viernes','S\u00e1bado'];
        var m = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return {
            diaSemana: dias[d.getDay()],
            dia: d.getDate(),
            mes: m[d.getMonth()]
        };
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var base        = window.VK_BASE_PATH || '';
        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);
        var enganche    = credito.enganche;
        var colorId     = state.colorSeleccionado || modelo.colorDefault;
        var motoImg     = VkUI.getImagenMoto(modelo.id, colorId);
        var fechaEntrega = this._calcFechaEntregaShort();

        var html = '';

        // Back button
        html += VkUI.renderBackButton('credito-enganche');

        // === Header: Tu Voltika esta apartada (icon + text on one line) ===
        html += '<div style="text-align:center;margin-bottom:16px;padding-top:4px;">';
        html += '<h2 style="font-size:22px;font-weight:800;color:#333;margin:0 0 6px;">\u00a1Tu Voltika est\u00e1 apartada!</h2>';
        html += '<p style="font-size:14px;color:#555;margin:0;">Tu enganche de <strong style="color:#333;">' +
            VkUI.formatPrecio(enganche) + ' MXN</strong> fue recibido <strong style="color:#4CAF50;">correctamente</strong>.</p>';
        html += '</div>';

        // === Financiamiento aprobado card ===
        html += '<div style="border-radius:14px;overflow:hidden;margin-bottom:20px;border:1.5px solid #039fe1;">';

        // Blue header: rocket + title only
        html += '<div style="background:#039fe1;padding:14px 20px;display:flex;align-items:center;gap:8px;">';
        html += '<span style="font-size:18px;font-weight:800;color:#fff;">\u00a1Tu financiamiento fue aprobado!</span>';
        html += '</div>';

        // White body: moto + info
        html += '<div style="background:#fff;padding:20px;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';

        // Moto image + calendar
        html += '<div style="flex-shrink:0;width:120px;">';
        html += '<img src="' + base + motoImg + '" alt="Voltika" style="width:100%;height:auto;">';
        html += '<div style="background:#F5F5F5;border-radius:8px;padding:6px;text-align:center;margin-top:6px;border:1px solid #eee;">';
        html += '<div style="font-size:9px;color:#999;font-weight:600;text-transform:uppercase;">' + fechaEntrega.diaSemana + '</div>';
        html += '<div style="font-size:24px;font-weight:900;color:#333;line-height:1;">' + fechaEntrega.dia + '</div>';
        html += '<div style="font-size:9px;color:#039fe1;font-weight:700;">' + fechaEntrega.mes + '</div>';
        html += '</div>';
        html += '</div>';

        // Info text
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="display:flex;align-items:flex-start;gap:6px;margin-bottom:10px;">';
        html += '<span style="font-size:13px;color:#333;"><strong>Tu moto est\u00e1</strong> reservada y comenzaremos a preparar tu entrega.</span>';
        html += '</div>';

        html += '<div style="display:flex;align-items:flex-start;gap:6px;margin-bottom:8px;">';
        html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#4CAF50;flex-shrink:0;margin-top:1px;">';
        html += '<span style="color:#fff;font-size:9px;">&#10003;</span></span>';
        html += '<span style="font-size:13px;color:#333;"><strong>Un asesor Voltika</strong> te contactar\u00e1 en m\u00e1ximo <strong>48 horas</strong> para:</span>';
        html += '</div>';

        html += '<div style="padding-left:22px;font-size:12px;color:#555;line-height:1.8;">';
        html += '&#8226; <strong>Confirmar</strong> el punto de entrega<br>';
        html += '&#8226; <strong>Coordinar</strong> fecha y horario<br>';
        html += '&#8226; Resolver cualquier duda';
        html += '</div>';
        html += '</div>';

        html += '</div>'; // end flex
        html += '</div>'; // end white body
        html += '</div>'; // end card

        // === Contract signature section (Cincel NOM-151 flow) ===

        // Title
        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;">';
        html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#4CAF50;flex-shrink:0;margin-top:1px;">';
        html += '<span style="color:#fff;font-size:13px;">&#10003;</span></span>';
        html += '<span style="font-size:16px;color:#333;"><strong>Para finalizar</strong> tu cr\u00e9dito, firma tu contrato:</span>';
        html += '</div>';

        // Signature canvas area
        html += '<div style="margin-bottom:12px;">';
        html += '<div style="font-size:13px;font-weight:700;color:#555;margin-bottom:6px;">Tu firma</div>';
        html += '<div style="border:2px solid #ddd;border-radius:10px;overflow:hidden;background:#fff;position:relative;" id="vk-firma-wrapper">';
        html += '<canvas id="vk-firma-canvas" width="320" height="120" ' +
            'style="width:100%;height:120px;display:block;cursor:crosshair;touch-action:none;"></canvas>';
        html += '<div id="vk-firma-placeholder" style="position:absolute;top:50%;left:50%;' +
            'transform:translate(-50%,-50%);font-size:13px;color:#bbb;pointer-events:none;">' +
            'Firma aqu\u00ed con tu dedo o mouse</div>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">';
        html += '<span id="vk-firma-status" style="font-size:12px;color:#999;">&#9898; Pendiente de firma</span>';
        html += '<button id="vk-firma-limpiar" style="background:none;border:1px solid #ddd;border-radius:6px;font-size:11px;padding:4px 10px;color:#666;cursor:pointer;">Limpiar firma</button>';
        html += '</div>';
        html += '</div>';

        // NOM-151 info badge
        html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#F0F7FF;border-radius:8px;margin-bottom:16px;border:1px solid #B3D4FC;">';
        html += '<span style="font-size:14px;">&#128274;</span>';
        html += '<span style="font-size:12px;color:#1a3a5c;">Tu firma ser\u00e1 certificada con <strong>NOM-151</strong> mediante Cincel Digital</span>';
        html += '</div>';

        // Contract + Terms checkbox (single)
        html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:14px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-contrato-acepto" style="margin-top:3px;flex-shrink:0;width:18px;height:18px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);line-height:1.5;">';
        html += 'He le\u00eddo y acepto de conformidad los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:#039fe1;text-decoration:underline;">t\u00e9rminos y cl\u00e1usulas</a> establecidas en el contrato y en el <a href="https://voltika.mx/docs/privacidad_2026.pdf" target="_blank" style="color:#039fe1;text-decoration:underline;">aviso de privacidad</a>.';
        html += ' <a href="#" id="vk-ver-contrato" style="color:#039fe1;font-weight:600;text-decoration:none;">Ver contrato</a>';
        html += '</span>';
        html += '</label>';

        // Error message
        html += '<div id="vk-contrato-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-contrato-confirmar" disabled ' +
            'style="font-size:16px;font-weight:800;padding:16px;margin-bottom:10px;">';
        html += '<span id="vk-contrato-btn-label">Confirmar mi financiamiento</span>';
        html += '<span id="vk-contrato-btn-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';

        // Time note
        html += '<div style="text-align:center;font-size:12px;color:#888;margin-bottom:14px;">';
        html += '<span style="font-size:14px;vertical-align:middle;">&#9201;</span> Esto toma menos de <strong>10 segundos</strong>';
        html += '</div>';

        // === Info bullets ===
        html += '<div style="margin-bottom:16px;padding:0 4px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<span style="color:#4CAF50;font-size:16px;">&#10003;</span>';
        html += '<span style="font-size:14px;color:#333;">Tu cr\u00e9dito inicia cuando <strong>recibes tu Voltika</strong></span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<span style="color:#4CAF50;font-size:16px;">&#10003;</span>';
        html += '<span style="font-size:14px;color:#333;">Puedes pagar antes o <strong>adelantar pagos</strong> cuando quieras, sin penalizaci\u00f3n</span>';
        html += '</div>';
        html += '</div>';

        // === Security key section ===
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;">';
        html += '<span style="font-size:22px;">&#128272;</span>';
        html += '<div style="font-size:15px;font-weight:800;color:#333;">Tu tel\u00e9fono ser\u00e1 la <strong style="color:#039fe1;">llave de seguridad</strong> de tu entrega</div>';
        html += '</div>';
        html += '<p style="font-size:13px;color:#555;line-height:1.6;margin:0;">';
        html += 'El d\u00eda de la entrega enviaremos un c\u00f3digo <strong>SMS</strong> para autorizar la entrega de tu Voltika.';
        html += '</p>';
        html += '</div>';

        // === Next step info ===
        html += '<div style="background:#F0F7FF;border-radius:10px;padding:14px;margin-bottom:16px;border:1px solid #B3D4FC;">';
        html += '<p style="font-size:13px;color:#1a3a5c;margin:0;line-height:1.5;">';
        html += 'En el siguiente paso podr\u00e1s configurar tu <strong>m\u00e9todo de pago autom\u00e1tico</strong> para tu <strong>cr\u00e9dito Voltika</strong>.';
        html += '</p>';
        html += '</div>';

        jQuery('#vk-credito-contrato-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Cleanup all handlers
        jQuery(document).off('change', '#vk-contrato-acepto')
            .off('click', '#vk-contrato-confirmar')
            .off('click', '#vk-ver-contrato')
            .off('click', '#vk-firma-limpiar')
            .off('click', '#vk-contrato-modal-close, #vk-contrato-modal-ok')
            .off('click', '#vk-contrato-modal');

        // Init canvas signature after DOM render
        setTimeout(function() { self._initCanvas(); }, 300);

        // Clear signature
        jQuery(document).on('click', '#vk-firma-limpiar', function() {
            self._clearCanvas();
        });

        // Checkbox
        jQuery(document).on('change', '#vk-contrato-acepto', function() {
            jQuery('#vk-contrato-error').hide();
            self._checkCanConfirm();
        });

        // Ver contrato
        jQuery(document).on('click', '#vk-ver-contrato', function(e) {
            e.preventDefault();
            self._showContrato();
        });

        // Confirm button
        jQuery(document).on('click', '#vk-contrato-confirmar', function() {
            self._confirmar();
        });

        // Modal close handlers
        jQuery(document).on('click', '#vk-contrato-modal-close, #vk-contrato-modal-ok', function() {
            jQuery('#vk-contrato-modal').remove();
        });
        jQuery(document).on('click', '#vk-contrato-modal', function(e) {
            if (e.target === this) jQuery(this).remove();
        });
    },

    // === Canvas signature methods ===

    _canvas: null,
    _ctx: null,
    _isDrawing: false,
    _hasSigned: false,

    _initCanvas: function() {
        var self = this;
        var canvas = document.getElementById('vk-firma-canvas');
        if (!canvas) return;

        // Use CSS computed size, fallback to 320x120 if not yet laid out
        var rect = canvas.getBoundingClientRect();
        var w = rect.width > 0 ? rect.width : (canvas.parentElement ? canvas.parentElement.clientWidth : 320);
        var h = 120;

        // Set canvas resolution to match display size (1:1, no scaling)
        canvas.width  = w;
        canvas.height = h;
        canvas.style.width  = w + 'px';
        canvas.style.height = h + 'px';

        var ctx = canvas.getContext('2d');
        ctx.lineWidth   = 2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        ctx.strokeStyle = '#111827';

        self._canvas = canvas;
        self._ctx    = ctx;

        // Mouse events
        canvas.addEventListener('mousedown', function(e) { self._startDraw(e); });
        canvas.addEventListener('mousemove', function(e) { self._draw(e); });
        canvas.addEventListener('mouseup',   function()  { self._endDraw(); });
        canvas.addEventListener('mouseleave', function() { self._endDraw(); });

        // Touch events
        canvas.addEventListener('touchstart', function(e) {
            e.preventDefault();
            self._startDraw(e.touches[0]);
        }, { passive: false });
        canvas.addEventListener('touchmove', function(e) {
            e.preventDefault();
            self._draw(e.touches[0]);
        }, { passive: false });
        canvas.addEventListener('touchend', function() { self._endDraw(); });
    },

    _getPos: function(e) {
        var rect = this._canvas.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    },

    _startDraw: function(e) {
        this._isDrawing = true;
        var pos = this._getPos(e);
        this._ctx.beginPath();
        this._ctx.moveTo(pos.x, pos.y);
        jQuery('#vk-firma-placeholder').hide();
    },

    _draw: function(e) {
        if (!this._isDrawing) return;
        var pos = this._getPos(e);
        this._ctx.lineTo(pos.x, pos.y);
        this._ctx.stroke();
        if (!this._hasSigned) {
            this._hasSigned = true;
            jQuery('#vk-firma-status').html('&#9989; Firma capturada').css('color', '#4CAF50');
            jQuery('#vk-firma-wrapper').css('border-color', '#4CAF50');
        }
        this._checkCanConfirm();
    },

    _endDraw: function() {
        this._isDrawing = false;
    },

    _clearCanvas: function() {
        if (!this._ctx || !this._canvas) return;
        this._ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
        this._hasSigned = false;
        jQuery('#vk-firma-placeholder').show();
        jQuery('#vk-firma-status').html('&#9898; Pendiente de firma').css('color', '#999');
        jQuery('#vk-firma-wrapper').css('border-color', '#ddd');
        this._checkCanConfirm();
    },

    _getSignatureData: function() {
        if (!this._canvas || !this._hasSigned) return null;
        return this._canvas.toDataURL('image/png');
    },

    _checkCanConfirm: function() {
        var signed = this._hasSigned;
        var accepted = jQuery('#vk-contrato-acepto').is(':checked');
        jQuery('#vk-contrato-confirmar').prop('disabled', !(signed && accepted));
    },

    _showContrato: function() {
        // Remove existing modal if any
        jQuery('#vk-contrato-modal').remove();

        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        var html = '';
        html += '<div id="vk-contrato-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">';
        html += '<div style="background:#fff;border-radius:14px;max-width:500px;width:100%;max-height:80vh;overflow-y:auto;padding:24px;">';

        // Close button
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
        html += '<span style="font-size:18px;font-weight:800;color:#333;">Contrato de Cr\u00e9dito</span>';
        html += '<button id="vk-contrato-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>';
        html += '</div>';

        // Contract summary — using state data
        html += '<div style="background:#F5F5F5;border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Acreditado</span><strong>' + (state.nombre || '--') + '</strong></div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Modelo</span><strong>' + modelo.nombre + '</strong></div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Precio contado</span><span>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</span></div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Enganche pagado</span><strong style="color:#4CAF50;">' + VkUI.formatPrecio(credito.enganche) + ' MXN &#10004;</strong></div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Monto financiado</span><strong>' + VkUI.formatPrecio(credito.montoFinanciado) + ' MXN</strong></div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="color:#888;">Plazo</span><strong>' + (state.plazoMeses || 12) + ' meses (' + credito.numeroPagos + ' pagos)</strong></div>';
        html += '<div style="border-top:1px solid #ddd;margin:8px 0;"></div>';
        html += '<div style="display:flex;justify-content:space-between;"><strong>Pago semanal</strong><strong style="font-size:16px;color:#4CAF50;">' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong></div>';
        html += '</div>';

        // Contract text
        html += '<div style="font-size:11px;color:#666;line-height:1.7;">';
        html += '<p style="font-weight:700;margin-bottom:8px;">CONTRATO DE CR\u00c9DITO SIMPLE \u2014 VOLTIKA S.A. DE C.V.</p>';
        html += '<p>El presente contrato establece las condiciones del cr\u00e9dito otorgado por Voltika S.A. de C.V. ';
        html += '(en adelante "EL ACREDITANTE") al solicitante (en adelante "EL ACREDITADO") para la adquisici\u00f3n de ';
        html += 'un veh\u00edculo el\u00e9ctrico marca Voltika.</p>';
        html += '<p><strong>CL\u00c1USULA 1. OBJETO.</strong> El Acreditante otorga al Acreditado una l\u00ednea de cr\u00e9dito ';
        html += 'por el monto financiado indicado en el resumen anterior, pagadero en exhibiciones semanales.</p>';
        html += '<p><strong>CL\u00c1USULA 2. TASA DE INTER\u00c9S.</strong> La tasa de inter\u00e9s ordinaria ser\u00e1 la indicada en ';
        html += 'el resumen del contrato, calculada sobre saldos insolutos, m\u00e1s IVA correspondiente.</p>';
        html += '<p><strong>CL\u00c1USULA 3. FORMA DE PAGO.</strong> El Acreditado se obliga a realizar pagos semanales ';
        html += 'mediante domiciliaci\u00f3n bancaria, transferencia electr\u00f3nica o pago en efectivo en los puntos autorizados.</p>';
        html += '<p><strong>CL\u00c1USULA 4. GARANT\u00cdA.</strong> El veh\u00edculo adquirido servir\u00e1 como garant\u00eda prendaria ';
        html += 'hasta la liquidaci\u00f3n total del cr\u00e9dito.</p>';
        html += '<p><strong>CL\u00c1USULA 5. MORA.</strong> En caso de incumplimiento, se aplicar\u00e1 una tasa moratoria ';
        html += 'del 50% anual sobre el saldo vencido, m\u00e1s gastos de cobranza.</p>';
        html += '</div>';

        html += '<button id="vk-contrato-modal-ok" class="vk-btn vk-btn--primary" style="margin-top:16px;font-size:14px;font-weight:700;">Cerrar</button>';
        html += '</div></div>';

        jQuery('body').append(html);
    },

    _confirmar: function() {
        var self  = this;
        var state = this.app.state;

        // Validate signature
        if (!this._hasSigned) {
            jQuery('#vk-contrato-error').text('Firma el contrato para continuar.').show();
            return;
        }
        // Validate checkbox
        if (!jQuery('#vk-contrato-acepto').is(':checked')) {
            jQuery('#vk-contrato-error').text('Debes aceptar los t\u00e9rminos del contrato.').show();
            return;
        }

        // Show loading
        jQuery('#vk-contrato-confirmar').prop('disabled', true);
        jQuery('#vk-contrato-btn-label').hide();
        jQuery('#vk-contrato-btn-spinner').show();
        jQuery('#vk-contrato-error').hide();

        // =====================================================
        // CINCEL API INTEGRATION PLACEHOLDER
        // TODO: Replace OTP with Cincel NOM-151 digital signature
        // API: https://api.cincel.digital/v3
        // Sandbox: https://sandbox.api.cincel.digital/v3
        // Auth: JWT Bearer token
        // Flow: Create document → Add signer → Confirm → Timestamp
        // =====================================================

        var modelo  = self.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        // Get signature as base64
        var firmaData = self._getSignatureData();

        // Generate contract PDF + Cincel NOM-151 timestamp
        jQuery.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/generar-contrato-pdf.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:     state.nombre,
                email:      state.email,
                telefono:   state.telefono,
                modelo:     modelo.nombre,
                color:      state.colorSeleccionado || modelo.colorDefault,
                metodoPago: state.metodoPago,
                ciudad:     state.ciudad,
                estado:     state.estado,
                cp:         state.codigoPostal,
                total:      credito.enganche,
                credito: {
                    enganchePct:     state.enganchePorcentaje,
                    plazoMeses:      state.plazoMeses,
                    pagoSemanal:     credito.pagoSemanal,
                    montoFinanciado: credito.montoFinanciado
                },
                firmaData:    firmaData,
                contrato:     true
            }),
            success: function(response) {
                state.contratoFirmado = true;
                state._firmaContrato = self._getSignatureData();

                // =====================================================
                // CINCEL TIMESTAMP FLOW (after backend generates PDF):
                // 1. Backend receives firma + contract data
                // 2. Backend generates PDF with signature embedded
                // 3. Backend POSTs PDF to Cincel API for NOM-151 timestamp
                //    POST https://sandbox.api.cincel.digital/v3/timestamps
                //    Auth: JWT Bearer or Basic Auth
                // 4. Cincel returns timestamped document
                // 5. Timestamped PDF sent to customer by email
                // =====================================================

                self.app.irAPaso('credito-autopago');
            },
            error: function() {
                // Backend not available — still proceed (testing mode)
                console.warn('confirmar-pedido.php not available, proceeding anyway');
                state.contratoFirmado = true;
                state._firmaContrato = self._getSignatureData();
                self.app.irAPaso('credito-autopago');
            }
        });
    }
};
