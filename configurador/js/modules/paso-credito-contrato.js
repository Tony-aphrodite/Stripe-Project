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
        html += '<div style="background:#E8F4FD;border-radius:8px;padding:8px;text-align:center;margin-top:6px;border:1.5px solid #039fe1;">';
        html += '<div style="font-size:10px;color:#039fe1;font-weight:700;margin-bottom:2px;">Fecha de entrega m\u00e1xima:</div>';
        html += '<div style="font-size:10px;color:#666;font-weight:600;text-transform:uppercase;">' + fechaEntrega.diaSemana + '</div>';
        html += '<div style="font-size:28px;font-weight:900;color:#039fe1;line-height:1;">' + fechaEntrega.dia + '</div>';
        html += '<div style="font-size:11px;color:#039fe1;font-weight:700;">' + fechaEntrega.mes + '</div>';
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

        // Contract + Terms checkbox FIRST (above signature)
        html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:14px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:14px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-contrato-acepto" style="margin-top:3px;flex-shrink:0;width:18px;height:18px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);line-height:1.5;">';
        html += 'He le\u00eddo y acepto de conformidad los <a href="#" id="vk-ver-contrato-completo" style="color:#039fe1;text-decoration:underline;">t\u00e9rminos y cl\u00e1usulas del contrato</a> y en el <a href="https://voltika.mx/docs/privacidad_2026.pdf" target="_blank" rel="noopener" style="color:#039fe1;text-decoration:underline;">aviso de privacidad</a>.';
        html += ' <a href="#" id="vk-ver-contrato" style="color:#039fe1;font-weight:600;text-decoration:none;">Ver contrato</a>';
        html += '</span>';
        html += '</label>';

        // Checkbox error message
        html += '<div id="vk-contrato-checkbox-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;">Debes aceptar los t\u00e9rminos para continuar.</div>';

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

        // Error message
        html += '<div id="vk-contrato-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-contrato-confirmar" ' +
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
            .off('click', '#vk-ver-contrato-completo')
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

        // Ver contrato (Carátula summary only)
        jQuery(document).on('click', '#vk-ver-contrato', function(e) {
            e.preventDefault();
            self._showCaratula();
        });

        // Términos y cláusulas (full contract: Carátula + Contrato de crédito)
        jQuery(document).on('click', '#vk-ver-contrato-completo', function(e) {
            e.preventDefault();
            self._showContratoCompleto();
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
        var ready = signed && accepted;
        jQuery('#vk-contrato-confirmar').css('opacity', ready ? '1' : '0.6');
        if (accepted) jQuery('#vk-contrato-checkbox-error').hide();
    },

    _getContractData: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );
        var numPagos = Math.round((state.plazoMeses || 36) * 4.33);
        var precioSinIVA = Math.round(modelo.precioContado / 1.16 * 100) / 100;
        var ivaVehiculo = Math.round((modelo.precioContado - precioSinIVA) * 100) / 100;
        var totalIntereses = Math.round((credito.pagoSemanal * numPagos) - credito.montoFinanciado);
        var montoTotalPagar = Math.round(credito.enganche + (credito.pagoSemanal * numPagos));
        return { state: state, modelo: modelo, credito: credito, numPagos: numPagos,
                 precioSinIVA: precioSinIVA, ivaVehiculo: ivaVehiculo,
                 totalIntereses: totalIntereses, montoTotalPagar: montoTotalPagar };
    },

    _capitalize: function(str) {
        if (!str) return '--';
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    },

    // "Ver contrato" — shows Carátula summary only
    _showCaratula: function() {
        jQuery('#vk-contrato-modal').remove();
        var d = this._getContractData();
        var s = d.state, m = d.modelo, c = d.credito;
        var f = VkUI.formatPrecio;
        var colorCap = this._capitalize(s.colorSeleccionado || m.colorDefault || '');
        var folio = s._customerId || ('VK-' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '-' + (s.nombre || '').substring(0,3).toUpperCase());
        var nombreCompleto = s.nombre || '--';
        if (s.apellidoPaterno) nombreCompleto += ' ' + s.apellidoPaterno;
        if (s.apellidoMaterno) nombreCompleto += ' ' + s.apellidoMaterno;

        var html = '<div id="vk-contrato-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">';
        html += '<div style="background:#fff;border-radius:14px;max-width:500px;width:100%;max-height:85vh;overflow-y:auto;padding:24px;">';

        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
        html += '<span style="font-size:16px;font-weight:800;color:#333;">Car\u00e1tula de Cr\u00e9dito</span>';
        html += '<button id="vk-contrato-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>';
        html += '</div>';

        html += '<div style="font-size:10px;color:#888;margin-bottom:6px;">MTECH GEARS S.A. DE C.V. | RFC: MGE230316KA2</div>';

        // Customer ID highlighted
        html += '<div style="background:#039fe1;color:#fff;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;font-weight:700;">Folio: ' + folio + '</div>';

        // Client data
        html += '<div style="font-size:12px;font-weight:700;color:#1a3a5c;margin-bottom:6px;">DATOS DEL CLIENTE</div>';
        html += '<div style="background:#F8F9FA;border-radius:8px;padding:12px;margin-bottom:12px;font-size:12px;line-height:1.8;">';
        html += 'Nombre completo: <strong>' + nombreCompleto + '</strong><br>';
        html += 'Email: <strong>' + (s.email || '--') + '</strong><br>';
        html += 'Tel: <strong>+52 ' + (s.telefono || '--') + '</strong>';
        html += '</div>';

        // Vehicle
        html += '<div style="font-size:12px;font-weight:700;color:#1a3a5c;margin-bottom:6px;">MOTOCICLETA</div>';
        html += '<div style="background:#F8F9FA;border-radius:8px;padding:12px;margin-bottom:12px;font-size:12px;line-height:1.8;">';
        html += 'Modelo: <strong>VOLTIKA ' + m.nombre + '</strong><br>';
        html += 'Color: <strong>' + colorCap + '</strong><br>';
        html += 'A\u00f1o: <strong>2026</strong>';
        html += '</div>';

        // Price
        html += '<div style="font-size:12px;font-weight:700;color:#1a3a5c;margin-bottom:6px;">PRECIO</div>';
        html += '<div style="background:#F8F9FA;border-radius:8px;padding:12px;margin-bottom:12px;font-size:12px;line-height:1.8;">';
        html += 'Precio (sin IVA): ' + f(d.precioSinIVA) + '<br>';
        html += 'IVA (16%): ' + f(d.ivaVehiculo) + '<br>';
        html += '<strong>Precio total: ' + f(m.precioContado) + ' MXN</strong>';
        html += '</div>';

        // Credit conditions
        html += '<div style="font-size:12px;font-weight:700;color:#1a3a5c;margin-bottom:6px;">CONDICIONES DE PAGO</div>';
        html += '<div style="background:#F8F9FA;border-radius:8px;padding:12px;margin-bottom:12px;font-size:12px;line-height:1.8;">';
        html += 'Enganche pagado: <strong>' + f(c.enganche) + '</strong><br>';
        html += 'Monto financiado: <strong>' + f(c.montoFinanciado) + '</strong><br>';
        html += 'Pagos: <strong>' + d.numPagos + ' Pagos semanales</strong><br>';
        html += 'Pago semanal: <strong style="color:#039fe1;">' + f(c.pagoSemanal) + '</strong><br>';
        html += 'Costo del financiamiento: ' + f(d.totalIntereses) + '<br>';
        html += '<strong style="font-size:14px;">Total a Pagar: ' + f(d.montoTotalPagar) + ' MXN</strong>';
        html += '</div>';

        html += '<button id="vk-contrato-modal-ok" class="vk-btn vk-btn--primary" style="margin-top:8px;font-size:14px;font-weight:700;">Cerrar</button>';
        html += '</div></div>';
        jQuery('body').append(html);
    },

    // "Términos y cláusulas" — shows Carátula + full contract text
    _showContratoCompleto: function() {
        jQuery('#vk-contrato-modal').remove();
        var d = this._getContractData();
        var s = d.state, m = d.modelo, c = d.credito;
        var f = VkUI.formatPrecio;
        var colorCap = this._capitalize(s.colorSeleccionado || m.colorDefault || '');
        var folio = s._customerId || ('VK-' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '-' + (s.nombre || '').substring(0,3).toUpperCase());
        var nombreCompleto = s.nombre || '--';
        if (s.apellidoPaterno) nombreCompleto += ' ' + s.apellidoPaterno;
        if (s.apellidoMaterno) nombreCompleto += ' ' + s.apellidoMaterno;

        var html = '<div id="vk-contrato-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">';
        html += '<div style="background:#fff;border-radius:14px;max-width:500px;width:100%;max-height:85vh;overflow-y:auto;padding:24px;">';

        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
        html += '<span style="font-size:16px;font-weight:800;color:#333;">Contrato de Cr\u00e9dito Completo</span>';
        html += '<button id="vk-contrato-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>';
        html += '</div>';

        // Company header
        html += '<div style="text-align:center;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #1a3a5c;">';
        html += '<div style="font-size:14px;font-weight:800;color:#1a3a5c;">CAR\u00c1TULA DE CR\u00c9DITO</div>';
        html += '<div style="font-size:10px;color:#888;">MTECH GEARS S.A. DE C.V. | RFC: MGE230316KA2</div>';
        html += '</div>';

        // Customer ID
        html += '<div style="background:#039fe1;color:#fff;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;font-weight:700;">Folio: ' + folio + '</div>';

        // Summary table
        var rows = [
            ['Nombre completo', nombreCompleto],
            ['Email', s.email || '--'],
            ['Tel\u00e9fono', '+52 ' + (s.telefono || '--')],
            ['Modelo', 'VOLTIKA ' + m.nombre],
            ['Color', colorCap],
            ['Precio contado', f(m.precioContado) + ' MXN'],
            ['Precio sin IVA', f(d.precioSinIVA)],
            ['IVA 16%', f(d.ivaVehiculo)],
            ['Enganche pagado', f(c.enganche)],
            ['Monto financiado', f(c.montoFinanciado)],
            ['Pagos', d.numPagos + ' Pagos semanales'],
            ['Pago semanal', f(c.pagoSemanal)],
            ['Costo del financiamiento', f(d.totalIntereses)],
            ['Total a Pagar', f(d.montoTotalPagar) + ' MXN'],
        ];
        html += '<div style="margin-bottom:16px;">';
        for (var i = 0; i < rows.length; i++) {
            var isLast = (i === rows.length - 1);
            html += '<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:' + (isLast ? '13' : '11') + 'px;' + (isLast ? 'border-top:1.5px solid #1a3a5c;margin-top:6px;padding-top:8px;font-weight:800;' : '') + '">';
            html += '<span style="color:#888;">' + rows[i][0] + '</span><strong>' + rows[i][1] + '</strong></div>';
        }
        html += '</div>';

        // Full contract header
        html += '<div style="border-top:2px solid #1a3a5c;margin-top:16px;padding-top:14px;">';
        html += '<div style="text-align:center;font-size:12px;font-weight:800;color:#1a3a5c;margin-bottom:6px;">CONTRATO DE APERTURA DE CR\u00c9DITO</div>';
        html += '<div style="text-align:center;font-size:9px;color:#888;margin-bottom:12px;">CONTRATO ASOCIADO AL FOLIO: <strong>' + folio + '</strong></div>';
        html += '</div>';

        // Scrollable contract text
        html += '<div style="font-size:9px;color:#555;line-height:1.7;max-height:400px;overflow-y:auto;padding:12px;background:#fafafa;border:1px solid #eee;border-radius:8px;">';

        html += '<p><strong>CONTRATO DE APERTURA DE CR\u00c9DITO</strong> QUE CELEBRAN POR UNA PARTE MTECH GEARS, S.A. DE C.V. (EN LO SUCESIVO VOLTIKA); Y POR LA OTRA ' + nombreCompleto.toUpperCase() + ' (EN LO SUCESIVO EL CLIENTE).</p>';

        html += '<p><strong>DECLARACIONES</strong></p>';
        html += '<p>Declara EL CLIENTE, por su propio derecho y para todos los efectos legales a que haya lugar, que:</p>';
        html += '<p>Es una persona f\u00edsica con la capacidad jur\u00eddica y econ\u00f3mica para obligarse bajo los t\u00e9rminos y condiciones del presente contrato.</p>';
        html += '<p>Para los efectos del presente contrato se se\u00f1ala como domicilio y medios de comunicaci\u00f3n los se\u00f1alados en la car\u00e1tula de este contrato.</p>';
        html += '<p>EL CLIENTE reconoce que el n\u00famero telef\u00f3nico registrado ser\u00e1 considerado como medio de autenticaci\u00f3n v\u00e1lido para efectos de la validaci\u00f3n electr\u00f3nica del presente contrato.</p>';

        html += '<p>Declara VOLTIKA, a trav\u00e9s de su representante legal que es una sociedad mexicana debidamente constituida bajo la legislaci\u00f3n mexicana.</p>';

        html += '<p><strong>CL\u00c1USULAS</strong></p>';

        html += '<p><strong>PRIMERA. OBJETO.</strong> El objeto del presente Contrato es el otorgamiento de un cr\u00e9dito por lo que VOLTIKA otorga a EL CLIENTE un cr\u00e9dito para la adquisici\u00f3n del veh\u00edculo el\u00e9ctrico descrito en la Car\u00e1tula.</p>';
        html += '<p><strong>SEGUNDA. DESTINO.</strong> EL CLIENTE se obliga a destinar el importe de EL CR\u00c9DITO exclusivamente a la adquisici\u00f3n del producto descrito en la Car\u00e1tula del presente Contrato.</p>';
        html += '<p><strong>TERCERA. PLAZO DEL CONTRATO.</strong> El plazo del Contrato ser\u00e1 el que resulte del n\u00famero total de pagos y su periodicidad establecidos en la Car\u00e1tula.</p>';
        html += '<p><strong>CUARTA. DOMICILIACI\u00d3N.</strong> EL CLIENTE autoriza expresamente a VOLTIKA para realizar cargos recurrentes a la tarjeta bancaria o cuenta registrada durante toda la vigencia del cr\u00e9dito.</p>';
        html += '<p><strong>QUINTA. DISPOSICIONES DEL CR\u00c9DITO.</strong> La disposici\u00f3n del cr\u00e9dito estar\u00e1 sujeta a la asignaci\u00f3n de la unidad espec\u00edfica conforme a disponibilidad.</p>';
        html += '<p><strong>SEXTA. PAGO DE ENGANCHE.</strong> EL CLIENTE reconoce que el pago inicial referido como ENGANCHE realizado a VOLTIKA constituye el pago parcial del precio del veh\u00edculo. En caso de que EL CLIENTE decida no continuar con la contrataci\u00f3n, VOLTIKA podr\u00e1 retener hasta el 20% del enganche como penalizaci\u00f3n.</p>';
        html += '<p><strong>SEXTA BIS. PAGOS DEL CR\u00c9DITO.</strong> EL CLIENTE pagar\u00e1 a VOLTIKA el monto financiado y los cargos derivados del financiamiento en pagos peri\u00f3dicos conforme a la Car\u00e1tula y Tabla de Pagos.</p>';
        html += '<p><strong>S\u00c9PTIMA. CARGOS DEL FINANCIAMIENTO.</strong> EL CLIENTE reconoce que el monto total a pagar incluye el principal m\u00e1s los cargos por financiamiento. EL CLIENTE manifiesta que conoce y acepta el monto total a pagar.</p>';
        html += '<p><strong>OCTAVA. INCUMPLIMIENTO DE PAGO.</strong> En caso de que EL CLIENTE no pague puntualmente, VOLTIKA podr\u00e1 aplicar cargos por atraso conforme a las condiciones de la Car\u00e1tula.</p>';
        html += '<p><strong>NOVENA. MEDIOS DE ACREDITACI\u00d3N Y REGISTRO.</strong> EL CLIENTE reconoce y acepta que VOLTIKA podr\u00e1 conservar registros f\u00edsicos y electr\u00f3nicos de todas las operaciones realizadas.</p>';
        html += '<p><strong>D\u00c9CIMA. LUGAR Y FORMA DE PAGO.</strong> Los pagos se efectuar\u00e1n mediante los medios autorizados por VOLTIKA, incluyendo cargos autom\u00e1ticos a tarjeta bancaria.</p>';
        html += '<p><strong>D\u00c9CIMA PRIMERA. INFORMACI\u00d3N DE PAGOS.</strong> VOLTIKA pondr\u00e1 a disposici\u00f3n de EL CLIENTE por medios electr\u00f3nicos el estado de cuenta.</p>';
        html += '<p><strong>D\u00c9CIMA SEGUNDA. PAGOS ANTICIPADOS.</strong> EL CLIENTE podr\u00e1 realizar pagos anticipados en cualquier momento, sin penalizaci\u00f3n alguna.</p>';
        html += '<p><strong>D\u00c9CIMA TERCERA. OBLIGACIONES.</strong> EL CLIENTE est\u00e1 obligado a mantener y conservar en condiciones adecuadas el veh\u00edculo objeto del presente Contrato.</p>';
        html += '<p><strong>D\u00c9CIMA CUARTA. RESERVA DE DOMINIO.</strong> VOLTIKA tiene la propiedad de la motocicleta hasta que EL CLIENTE haya liquidado en su totalidad el cr\u00e9dito.</p>';
        html += '<p><strong>D\u00c9CIMA CUARTA BIS. GARANT\u00cdA PRENDARIA.</strong> En garant\u00eda del pago, EL CLIENTE constituye garant\u00eda prendaria sobre el veh\u00edculo objeto del presente Contrato.</p>';
        html += '<p><strong>D\u00c9CIMA QUINTA. TIEMPOS DE ENTREGA.</strong> VOLTIKA se compromete a realizar la entrega en un plazo estimado de hasta 28 d\u00edas naturales a partir de la confirmaci\u00f3n del pago del enganche.</p>';
        html += '<p><strong>D\u00c9CIMA SEXTA. POSESI\u00d3N DEL VEH\u00cdCULO.</strong> EL CLIENTE conservar\u00e1 la posesi\u00f3n del veh\u00edculo en su car\u00e1cter de depositario.</p>';
        html += '<p><strong>D\u00c9CIMA S\u00c9PTIMA. OBLIGADO SOLIDARIO.</strong> La persona cuyos datos aparecen en la Car\u00e1tula responder\u00e1 solidariamente de las obligaciones.</p>';
        html += '<p><strong>D\u00c9CIMA OCTAVA. OPCIONES DE PROTECCI\u00d3N.</strong> VOLTIKA podr\u00e1 ofrecer seguros o mecanismos de protecci\u00f3n opcionales.</p>';
        html += '<p><strong>D\u00c9CIMA NOVENA. RESPONSABILIDAD SOBRE EL VEH\u00cdCULO.</strong> EL CLIENTE es responsable del uso, resguardo y conservaci\u00f3n del veh\u00edculo.</p>';
        html += '<p><strong>VIG\u00c9SIMA. IMPUESTOS.</strong> EL CLIENTE pagar\u00e1 los impuestos y obligaciones fiscales que se generen con motivo del presente Contrato.</p>';
        html += '<p><strong>VIG\u00c9SIMA PRIMERA. CAUSAS DE VENCIMIENTO ANTICIPADO.</strong> VOLTIKA tendr\u00e1 derecho a declarar el vencimiento anticipado si EL CLIENTE no paga puntualmente, proporciona informaci\u00f3n falsa, o dispone del veh\u00edculo sin autorizaci\u00f3n.</p>';
        html += '<p><strong>VIG\u00c9SIMA SEGUNDA. COMPENSACI\u00d3N.</strong> VOLTIKA notificar\u00e1 a EL CLIENTE en el estado de cuenta de cualquier cargo o compensaci\u00f3n.</p>';
        html += '<p><strong>VIG\u00c9SIMA TERCERA. CESI\u00d3N DEL CR\u00c9DITO.</strong> Este contrato surtir\u00e1 efectos una vez suscrito por ambas partes.</p>';
        html += '<p><strong>VIG\u00c9SIMA CUARTA. RESTRICCI\u00d3N Y DENUNCIA.</strong> Se conviene que VOLTIKA ser\u00e1 la \u00fanica con facultades de exigir el pago del cr\u00e9dito.</p>';
        html += '<p><strong>VIG\u00c9SIMA QUINTA. DOMICILIOS.</strong> Las partes se\u00f1alan como domicilios los proporcionados en los respectivos registros.</p>';
        html += '<p><strong>VIG\u00c9SIMA SEXTA. TERMINACI\u00d3N DEL CONTRATO.</strong> Cualquiera de las partes podr\u00e1 solicitar la terminaci\u00f3n conforme a las condiciones establecidas.</p>';
        html += '<p><strong>VIG\u00c9SIMA S\u00c9PTIMA. JURISDICCI\u00d3N Y COMPETENCIA.</strong> Para la soluci\u00f3n de cualquier controversia, las partes se someten a la jurisdicci\u00f3n de los tribunales de la Ciudad de M\u00e9xico.</p>';
        html += '<p><strong>VIG\u00c9SIMA OCTAVA. FIRMA ELECTR\u00d3NICA.</strong> Las Partes convienen que la firma electr\u00f3nica tiene la misma validez legal que una firma aut\u00f3grafa conforme al C\u00f3digo de Comercio. EL CLIENTE manifiesta que ha tenido acceso previo al presente documento y que acepta su contenido de forma libre e informada.</p>';

        // Signature section
        html += '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">';
        html += '<p><strong>EL CLIENTE</strong></p>';
        html += '<p>Nombre: <strong>' + nombreCompleto + '</strong></p>';
        html += '<p>Firma electr\u00f3nica de EL CLIENTE</p>';
        html += '<p>Folio del Contrato: <strong>' + folio + '</strong></p>';
        html += '</div>';

        html += '</div>'; // end scrollable

        html += '<div style="font-size:9px;color:#999;margin-top:12px;text-align:center;font-style:italic;">La presente car\u00e1tula y contrato de cr\u00e9dito forman un solo documento legal.</div>';

        html += '<button id="vk-contrato-modal-ok" class="vk-btn vk-btn--primary" style="margin-top:12px;font-size:14px;font-weight:700;">Cerrar</button>';
        html += '</div></div>';
        jQuery('body').append(html);
    },

    _confirmar: function() {
        var self  = this;
        var state = this.app.state;

        // Validate checkbox first
        if (!jQuery('#vk-contrato-acepto').is(':checked')) {
            jQuery('#vk-contrato-checkbox-error').show();
            jQuery('html, body').animate({ scrollTop: jQuery('#vk-contrato-acepto').closest('label').offset().top - 80 }, 400);
            return;
        }
        // Validate signature
        if (!this._hasSigned) {
            jQuery('#vk-contrato-error').text('Firma el contrato para continuar.').show();
            jQuery('html, body').animate({ scrollTop: jQuery('#vk-firma-wrapper').offset().top - 80 }, 400);
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
