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
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 3 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:0;">Entrega en tu ciudad</h2>';
        html += '</div>';

        // Postal code input with search icon
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="font-weight:700;font-size:16px;margin-bottom:10px;">Ingresa tu C\u00f3digo Postal</div>';
        html += '<div class="vk-form-group" style="position:relative;margin-bottom:4px;">';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" ' +
            'placeholder="C.P. 5 d\u00edgitos" ' +
            'maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.codigoPostal || '') + '" ' +
            'style="padding-right:40px;font-size:16px;">';
        html += '<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--vk-text-muted);font-size:18px;">&#128269;</span>';
        html += '</div>';
        html += '<div id="vk-cp-error" style="display:none;color:#D32F2F;font-size:13px;margin-bottom:10px;">&#9888; Ingresa tu C\u00f3digo Postal y colonia para continuar.</div>';
        html += '<div id="vk-cp-not-found" style="display:none;color:#D32F2F;font-size:13px;margin-bottom:10px;background:#FFEBEE;border-radius:6px;padding:10px;">C\u00f3digo no encontrado. Verifica e intenta de nuevo.</div>';

        // Estado / Ciudad / Colonia fields (filled dynamically after CP lookup)
        html += '<div id="vk-cp-city" style="display:none;margin-bottom:16px;">';

        // Estado (read-only, auto-filled)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Estado</label>';
        html += '<input type="text" id="vk-cp-estado" class="vk-form-input" readonly ' +
            'style="font-size:15px;padding:12px 14px;background:#f5f5f5;color:#111;">';
        html += '</div>';

        // Ciudad (read-only, auto-filled)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Ciudad</label>';
        html += '<input type="text" id="vk-cp-ciudad" class="vk-form-input" readonly ' +
            'style="font-size:15px;padding:12px 14px;background:#f5f5f5;color:#111;">';
        html += '</div>';

        // Colonia (user types)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Colonia</label>';
        html += '<input type="text" id="vk-cp-colonia" class="vk-form-input" ' +
            'placeholder="Escribe tu colonia" ' +
            'value="' + (state.colonia || '') + '" ' +
            'style="font-size:15px;padding:12px 14px;">';
        html += '</div>';

        html += '</div>';

        // Centro Voltika Autorizado section
        html += '<div style="background:var(--vk-bg-light);border-radius:10px;padding:16px;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/voltika_shield.svg" alt="Voltika" style="width:30px;height:30px;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:6px;">Centro Voltika Autorizado</div>';
        html += '<div style="font-size:14px;color:var(--vk-text-secondary);margin-bottom:4px;">&#10003; Revisi\u00f3n y activaci\u00f3n profesional</div>';
        if (esCredito) {
            html += '<div style="font-size:14px;color:var(--vk-green-primary);font-weight:600;">&#10003; Flete incluido en Cr\u00e9dito Voltika</div>';
        } else {
            html += '<div id="vk-cp-logistics" style="display:none;font-size:14px;color:var(--vk-text-secondary);">';
            html += '&#10003; Costo log\u00edstico: <strong id="vk-cp-logistics-price"></strong> MXN';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Entrega Garantizada section
        html += '<div style="background:#E0F4FD;border-radius:10px;padding:16px;margin-bottom:14px;border-left:4px solid #039fe1;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:8px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/delivery_icon.jpg" alt="" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:4px;">Entrega Garantizada</div>';
        html += '<div style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">Entrega garantizada a m\u00e1s tardar el <strong style="color:#039fe1;">' + fechaEntrega + '</strong></div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Tu Asesor Voltika section
        html += '<div style="background:var(--vk-bg-light);border-radius:10px;padding:16px;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/asesor_icon.jpg" alt="" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:4px;">Tu Asesor Voltika</div>';
        html += '<div style="font-size:14px;color:var(--vk-text-secondary);">Estar\u00e1 contigo desde la entrega. M\u00e1x. <strong>48 horas</strong> para confirmarte el punto autorizado.</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        html += '<p style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin:4px 0 14px;text-align:center;">Recibir\u00e1s confirmaci\u00f3n por <strong>WhatsApp</strong> y <strong>correo electr\u00f3nico</strong>.</p>';

        html += '</div>'; // end card

        // ── 2 Interactive Checkboxes (always visible) ──
        html += '<div style="margin-top:16px;">';

        // Checkbox 1: Asesoría placas
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:16px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:10px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-placas" class="vk-checkbox" style="margin-top:3px;"' +
            (state.asesoriaPlacos ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:15px;">Quiero asesor\u00eda para placas en mi estado</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Te conectamos con gestores verificados. Pago directo al gestor.</div>';
        html += '</div>';
        html += '</label>';

        // Checkbox 2: Seguro
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:16px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:16px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-seguro" class="vk-checkbox" style="margin-top:3px;"' +
            (state.seguro ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:15px;">Quiero cotizar y activar el seguro con <span style="color:#6B2D8B;font-weight:900;font-style:italic;">Qu\u00e1litas</span> desde la entrega</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Cotizamos y enviamos tu p\u00f3liza. Pago directo a la aseguradora.</div>';
        html += '</div>';
        html += '</label>';

        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="font-size:16px;font-weight:800;letter-spacing:0.5px;">' +
            'CONFIRMAR ENTREGA OFICIAL' +
            '</button>';

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
            $('#vk-cp-error').hide();
            $('#vk-cp-not-found').hide();

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
            var colonia = $.trim($('#vk-cp-colonia').val());
            if (VkValidacion.codigoPostal(cp) && self.app.state.ciudad && colonia) {
                $('#vk-cp-error').hide();
                self.app.state.codigoPostal = cp;
                self.app.state.colonia = colonia;
                self.app.irAPaso('resumen');
            } else {
                $('#vk-cp-error').show();
                $('#vk-cp-input').focus();
                $('#vk-cp-input').css('border-color', '#D32F2F');
                setTimeout(function() { $('#vk-cp-input').css('border-color', ''); }, 3000);
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

            // Fill Estado, Ciudad fields
            $('#vk-cp-estado').val(resultado.estado);
            $('#vk-cp-ciudad').val(resultado.ciudad);
            $('#vk-cp-colonia').val(state.colonia || '');
            $('#vk-cp-city').slideDown(200);

            // Show logistics cost (contado/msi only)
            if (!esCredito) {
                $('#vk-cp-logistics-price').text(VkUI.formatPrecio(config.costoLogistico));
                $('#vk-cp-logistics').show();
            }

            // Enable confirm button
            $('#vk-paso3-confirmar').prop('disabled', false);
        } else {
            $('#vk-cp-logistics').hide();
            $('#vk-paso3-confirmar').prop('disabled', true);
            this.app.state.ciudad = null;

            $('#vk-cp-city').hide();
            $('#vk-cp-not-found').show();
        }
    }
};
