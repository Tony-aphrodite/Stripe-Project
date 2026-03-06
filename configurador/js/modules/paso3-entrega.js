/* ==========================================================================
   Voltika - PASO 3: Postal Code / Delivery
   Per Dibujo.pdf spec:
   - CP input + 2 interactive checkboxes (Asesoría placas, Seguro)
   - Basic info visible BEFORE entering CP
   - CP fills city/state/cost dynamically
   - For crédito: shipping cost included
   - For contado/msi: shipping cost calculated by state
   ========================================================================== */

var Paso3 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
        // Auto-search if CP already in state (revisiting this step)
        var self = this;
        if (app.state.codigoPostal && app.state.codigoPostal.length === 5) {
            setTimeout(function() { self.buscarCP(app.state.codigoPostal); }, 200);
        }
    },

    _calcFechaEntrega: function() {
        var d = new Date();
        d.setDate(d.getDate() + 15);
        var m = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return d.getDate() + ' de ' + m[d.getMonth()] + ' de ' + d.getFullYear();
    },

    render: function() {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var fechaEntrega = this._calcFechaEntrega();
        var html = '';

        // Back button: crédito → calculator (4B), contado/msi → color (2)
        var backTarget = esCredito ? 4 : 2;
        if (esCredito && state.creditoAprobado) backTarget = 'credito-resultado';
        html += VkUI.renderBackButton(backTarget);

        // Header
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 3 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:0;">Entrega en tu ciudad</h2>';
        html += '</div>';

        // Postal code input with search icon
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Ingresa tu C\u00f3digo Postal</div>';
        html += '<div class="vk-form-group" style="position:relative;margin-bottom:8px;">';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" ' +
            'placeholder="C.P. 5 d\u00edgitos" ' +
            'maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.codigoPostal || '') + '" ' +
            'style="padding-right:40px;">';
        html += '<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--vk-text-muted);font-size:18px;">&#128269;</span>';
        html += '</div>';

        // City/state display (filled dynamically)
        html += '<div id="vk-cp-city" style="display:none;margin-bottom:14px;background:var(--vk-green-soft);border-radius:8px;padding:12px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<span style="color:#D4A017;font-size:20px;">&#128205;</span>';
        html += '<div>';
        html += '<div id="vk-cp-city-name" style="font-weight:700;font-size:16px;color:var(--vk-text-primary);"></div>';
        html += '<div id="vk-cp-state-name" style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;"></div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Centro Voltika (always visible)
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:12px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:10px;">';
        html += '<span style="font-size:20px;">&#128737;</span>';
        html += '<div>';
        html += '<strong>Entrega en Centro Voltika Autorizado</strong>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Centro certificado para revisi\u00f3n y entrega profesional.</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Logistics cost / Flete info
        if (esCredito) {
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 12px;background:var(--vk-green-soft);border-radius:8px;">';
            html += '<span style="color:var(--vk-green-primary);">&#10004;</span>';
            html += '<span style="font-size:14px;font-weight:600;">En Cr\u00e9dito Voltika: <strong>Flete incluido</strong></span>';
            html += '</div>';
        } else {
            html += '<div id="vk-cp-logistics" style="display:none;margin-bottom:12px;padding:8px 12px;background:var(--vk-bg-light);border-radius:8px;">';
            html += '<div style="display:flex;align-items:center;gap:8px;">';
            html += '<span style="color:var(--vk-green-primary);">&#10004;</span>';
            html += '<span style="font-size:14px;font-weight:600;">Costo log\u00edstico: <strong id="vk-cp-logistics-price"></strong> MXN</span>';
            html += '</div>';
            html += '</div>';
        }

        // Delivery date (always visible)
        html += '<div style="margin-bottom:14px;">';
        html += '<strong>Entrega estimada: 7\u201310 d\u00edas h\u00e1biles</strong>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">' +
            'Nuestro equipo de log\u00edstica confirmar\u00e1 contigo el Centro Voltika m\u00e1s cercano dentro de las pr\u00f3ximas 24\u201348 horas.' +
            '</div>';
        html += '</div>';

        html += '</div>'; // end card

        // ── 2 Interactive Checkboxes (always visible) ──
        html += '<div style="margin-top:16px;">';

        // Checkbox 1: Asesoría placas
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1.5px solid var(--vk-border);border-radius:8px;margin-bottom:10px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-placas" class="vk-checkbox" style="margin-top:3px;"' +
            (state.asesoriaPlacos ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:700;font-size:14px;">Asesor\u00eda para placas en tu estado</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Te conectamos con gestores verificados. Pago directo al gestor.</div>';
        html += '</div>';
        html += '</label>';

        // Checkbox 2: Seguro
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1.5px solid var(--vk-border);border-radius:8px;margin-bottom:16px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-seguro" class="vk-checkbox" style="margin-top:3px;"' +
            (state.seguro ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:700;font-size:14px;">Seguro activo Qual\u00edtas desde la entrega</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Cotizamos y enviamos tu p\u00f3liza. Pago directo a la aseguradora.</div>';
        html += '</div>';
        html += '</label>';

        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" disabled>' +
            'CONFIRMAR PEDIDO' +
            '</button>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
            'Recibir\u00e1s confirmaci\u00f3n por WhatsApp y correo electr\u00f3nico.' +
            '</p>';

        $('#vk-entrega-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        $(document).off('input', '#vk-cp-input').off('click', '#vk-paso3-confirmar')
            .off('change', '#vk-check-placas').off('change', '#vk-check-seguro');

        // Postal code input
        $(document).on('input', '#vk-cp-input', function() {
            var val = $(this).val().replace(/\D/g, '');
            $(this).val(val);

            if (val.length === 5) {
                self.buscarCP(val);
            } else {
                $('#vk-cp-city').hide();
                $('#vk-cp-logistics').hide();
                $('#vk-paso3-confirmar').prop('disabled', true);
                self.app.state.ciudad = null;
            }
        });

        // Checkboxes
        $(document).on('change', '#vk-check-placas', function() {
            self.app.state.asesoriaPlacos = this.checked;
        });
        $(document).on('change', '#vk-check-seguro', function() {
            self.app.state.seguro = this.checked;
        });

        // Confirm
        $(document).on('click', '#vk-paso3-confirmar', function() {
            var cp = $('#vk-cp-input').val();
            if (VkValidacion.codigoPostal(cp) && self.app.state.ciudad) {
                self.app.state.codigoPostal = cp;
                self.app.irAPaso('resumen');
            } else {
                $('#vk-cp-input').focus();
                $('#vk-cp-input').css('border-color', 'red');
                setTimeout(function() { $('#vk-cp-input').css('border-color', ''); }, 2000);
            }
        });
    },

    buscarCP: function(cp) {
        var resultado = VOLTIKA_CP._buscar(cp);

        if (resultado) {
            var state = this.app.state;
            var esCredito = state.metodoPago === 'credito';
            var config = VOLTIKA_PRODUCTOS.config;

            state.ciudad = resultado.ciudad;
            state.estado = resultado.estado;
            state.costoLogistico = esCredito ? 0 : config.costoLogistico;

            // Show city + state
            $('#vk-cp-city-name').text(resultado.ciudad);
            $('#vk-cp-state-name').text(resultado.estado + ' \u00b7 C.P. ' + cp);
            $('#vk-cp-city').css('background', 'var(--vk-green-soft)').slideDown(200);

            // Show logistics cost (contado/msi only)
            if (!esCredito) {
                $('#vk-cp-logistics-price').text(VkUI.formatPrecio(config.costoLogistico));
                $('#vk-cp-logistics').slideDown(200);
            }

            // Enable confirm button
            $('#vk-paso3-confirmar').prop('disabled', false);
        } else {
            $('#vk-cp-city').hide();
            $('#vk-cp-logistics').hide();
            $('#vk-paso3-confirmar').prop('disabled', true);
            this.app.state.ciudad = null;

            $('#vk-cp-city-name').text('C\u00f3digo no encontrado');
            $('#vk-cp-state-name').text('Verifica el C.P. ' + cp + ' e intenta de nuevo.');
            $('#vk-cp-city').css('background', '#FFEBEE').slideDown(200);
        }
    }
};
