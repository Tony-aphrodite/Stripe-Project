/* ==========================================================================
   Voltika - Crédito: Firma de Contrato Digital
   Touch/mouse signature capture + contract summary
   ========================================================================== */

var PasoCreditoContrato = {

    _canvas: null,
    _ctx: null,
    _isDrawing: false,
    _hasSigned: false,

    init: function(app) {
        this.app = app;
        this._hasSigned = false;
        this.render();
        this.bindEvents();
        this._initCanvas();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);

        var html = '';

        html += VkUI.renderBackButton('credito-enganche');

        html += '<h2 class="vk-paso__titulo">Contrato de crédito</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:20px;">';

        // Contract summary
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Resumen del contrato</div>';

        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;">';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Acreditado</span>';
        html += '<span style="font-weight:600;">' + (state.nombre || '--') + '</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Modelo</span>';
        html += '<span style="font-weight:600;">' + modelo.nombre + '</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Precio contado</span>';
        html += '<span>' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Enganche pagado</span>';
        html += '<span style="color:var(--vk-green-primary);font-weight:700;">' +
            VkUI.formatPrecio(credito.enganche) + ' MXN &#10004;</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Monto financiado</span>';
        html += '<span style="font-weight:600;">' + VkUI.formatPrecio(credito.montoFinanciado) + ' MXN</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Plazo</span>';
        html += '<span style="font-weight:600;">' + (state.plazoMeses || 12) + ' meses (' +
            credito.numeroPagos + ' pagos semanales)</span>';
        html += '</div>';

        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="color:var(--vk-text-secondary);">Tasa anual</span>';
        html += '<span style="font-weight:600;">' + Math.round(credito.tasaAnual) + '%</span>';
        html += '</div>';

        html += '<div style="border-top:1px solid var(--vk-border);margin:8px 0;"></div>';

        html += '<div style="display:flex;justify-content:space-between;">';
        html += '<span style="font-weight:700;">Pago semanal</span>';
        html += '<span style="font-weight:800;font-size:16px;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(credito.pagoSemanal) + '</span>';
        html += '</div>';

        html += '</div>'; // end summary box

        // Contract terms
        html += '<div style="max-height:150px;overflow-y:auto;border:1px solid var(--vk-border);' +
            'border-radius:8px;padding:12px;margin-bottom:16px;font-size:11px;color:var(--vk-text-secondary);' +
            'line-height:1.6;background:#FAFAFA;">';
        html += '<p style="font-weight:700;margin-bottom:8px;">CONTRATO DE CRÉDITO SIMPLE — VOLTIKA S.A. DE C.V.</p>';
        html += '<p>El presente contrato establece las condiciones del crédito otorgado por Voltika S.A. de C.V. ' +
            '(en adelante "EL ACREDITANTE") al solicitante (en adelante "EL ACREDITADO") para la adquisición de ' +
            'un vehículo eléctrico marca Voltika.</p>';
        html += '<p><strong>CLÁUSULA 1. OBJETO.</strong> El Acreditante otorga al Acreditado una línea de crédito ' +
            'por el monto financiado indicado en el resumen anterior, pagadero en exhibiciones semanales.</p>';
        html += '<p><strong>CLÁUSULA 2. TASA DE INTERÉS.</strong> La tasa de interés ordinaria será la indicada en ' +
            'el resumen del contrato, calculada sobre saldos insolutos, más IVA correspondiente.</p>';
        html += '<p><strong>CLÁUSULA 3. FORMA DE PAGO.</strong> El Acreditado se obliga a realizar pagos semanales ' +
            'mediante domiciliación bancaria, transferencia electrónica o pago en efectivo en los puntos autorizados.</p>';
        html += '<p><strong>CLÁUSULA 4. GARANTÍA.</strong> El vehículo adquirido servirá como garantía prendaria ' +
            'hasta la liquidación total del crédito.</p>';
        html += '<p><strong>CLÁUSULA 5. MORA.</strong> En caso de incumplimiento, se aplicará una tasa moratoria ' +
            'del 50% anual sobre el saldo vencido, más gastos de cobranza.</p>';
        html += '</div>';

        // Consent
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-contrato-acepto">';
        html += '<label class="vk-checkbox-label" for="vk-contrato-acepto" style="font-size:12px;">' +
            'He leído y acepto los términos del contrato de crédito y el ' +
            '<a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" style="color:var(--vk-green-primary);">' +
            'aviso de privacidad</a>.' +
            '</label>';
        html += '</div>';

        // Signature area
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:8px;">' +
            'Tu firma</div>';
        html += '<div style="border:2px solid var(--vk-border);border-radius:8px;overflow:hidden;' +
            'margin-bottom:8px;background:#fff;position:relative;" id="vk-firma-wrapper">';
        html += '<canvas id="vk-firma-canvas" width="320" height="120" ' +
            'style="width:100%;height:120px;display:block;cursor:crosshair;touch-action:none;"></canvas>';
        html += '<div id="vk-firma-placeholder" style="position:absolute;top:50%;left:50%;' +
            'transform:translate(-50%,-50%);font-size:13px;color:#ccc;pointer-events:none;">' +
            'Firma aquí con tu dedo o mouse</div>';
        html += '</div>';

        html += '<div style="text-align:right;margin-bottom:16px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-firma-limpiar" ' +
            'style="font-size:11px;padding:4px 12px;">Limpiar firma</button>';
        html += '</div>';

        // Error
        html += '<div id="vk-contrato-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-contrato-firmar" disabled>' +
            '<span id="vk-contrato-label">&#9998; Firmar contrato</span>' +
            '<span id="vk-contrato-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>' +
            '</button>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-contrato-container').html(html);
    },

    _initCanvas: function() {
        var self    = this;
        var canvas  = document.getElementById('vk-firma-canvas');
        if (!canvas) return;

        // Set actual pixel dimensions
        var rect = canvas.getBoundingClientRect();
        canvas.width  = rect.width * 2;
        canvas.height = rect.height * 2;

        var ctx = canvas.getContext('2d');
        ctx.scale(2, 2);
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
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
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
        this._hasSigned = true;
        this._checkCanSign();
    },

    _endDraw: function() {
        this._isDrawing = false;
    },

    _clearCanvas: function() {
        if (!this._ctx || !this._canvas) return;
        this._ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
        this._hasSigned = false;
        jQuery('#vk-firma-placeholder').show();
        this._checkCanSign();
    },

    _checkCanSign: function() {
        var accepted = jQuery('#vk-contrato-acepto').is(':checked');
        jQuery('#vk-contrato-firmar').prop('disabled', !(accepted && this._hasSigned));
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-firma-limpiar');
        jQuery(document).on('click', '#vk-firma-limpiar', function() {
            self._clearCanvas();
        });

        jQuery(document).off('change', '#vk-contrato-acepto');
        jQuery(document).on('change', '#vk-contrato-acepto', function() {
            self._checkCanSign();
        });

        jQuery(document).off('click', '#vk-contrato-firmar');
        jQuery(document).on('click', '#vk-contrato-firmar', function() {
            self._firmar();
        });
    },

    _firmar: function() {
        var self  = this;
        var state = this.app.state;

        if (!jQuery('#vk-contrato-acepto').is(':checked')) {
            jQuery('#vk-contrato-error').text('Debes aceptar los términos del contrato.').show();
            return;
        }
        if (!this._hasSigned) {
            jQuery('#vk-contrato-error').text('Firma el contrato para continuar.').show();
            return;
        }

        jQuery('#vk-contrato-firmar').prop('disabled', true);
        jQuery('#vk-contrato-label').hide();
        jQuery('#vk-contrato-spinner').show();
        jQuery('#vk-contrato-error').hide();

        // Get signature as base64
        var firmaData = this._canvas ? this._canvas.toDataURL('image/png') : '';
        state._firmaContrato = firmaData;
        state.contratoFirmado = true;

        // Save contract data to backend
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        jQuery.ajax({
            url: 'php/confirmar-pedido.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:     state.nombre,
                email:      state.email,
                telefono:   state.telefono,
                modelo:     modelo.nombre,
                color:      state.colorSeleccionado || modelo.colorDefault,
                metodoPago: 'credito',
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
                firma:       firmaData ? true : false,
                contrato:    true
            }),
            complete: function() {
                self.app.irAPaso('exito');
            }
        });
    }
};
