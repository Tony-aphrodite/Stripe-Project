/* ==========================================================================
   Voltika - PASO 3: Postal Code / Delivery
   Full redesign per client mockup: Centro Voltika, logistics, Qualitas, placas
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
        var html = '';

        // Back button
        var backTarget = (esCredito && state.creditoAprobado) ? 'credito-resultado' : 2;
        html += VkUI.renderBackButton(backTarget);

        // Header
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 3 \u00b7</div>';
        if (!esCredito) {
            html += '<div style="font-size:14px;font-weight:600;color:var(--vk-text-primary);margin-bottom:2px;">Pago de Contado o MSI</div>';
        }
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:0;">Confirma tu punto de entrega oficial</h2>';
        html += '</div>';

        // Postal code input with search icon
        html += '<div class="vk-form-group" style="position:relative;">';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" ' +
            'placeholder="Ingresa tu C\u00f3digo Postal (C.P 5 d\u00edgitos)" ' +
            'maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.codigoPostal || '') + '" ' +
            'style="padding-right:40px;">';
        html += '<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--vk-text-muted);font-size:18px;">&#128269;</span>';
        html += '</div>';

        // Note below input
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin:-8px 0 12px;">' +
            'Red nacional Voltika con cobertura en todo M\u00e9xico.' +
            '</p>';

        // Results container (hidden until CP found)
        html += '<div id="vk-cp-results" style="display:none;"></div>';

        // CTA (hidden until CP found)
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="display:none;">' +
            (esCredito ? 'CONFIRMAR ENTREGA OFICIAL' : 'CONFIRMAR ENTREGA EN MI CIUDAD') +
            '</button>';

        // Social proof (hidden until CP found)
        html += '<p id="vk-paso3-footer" style="display:none;text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
            '+ M\u00e1s de 1,000 clientes ya recibieron su Voltika' +
            '</p>';

        $('#vk-entrega-container').html(html);
    },

    renderResults: function(data) {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;
        var fechaEntrega = this._calcFechaEntrega();

        state.costoLogistico = esCredito ? 0 : config.costoLogistico;
        state.ciudad = data.ciudad;
        state.estado = data.estado;

        var html = '';

        // City/State with location pin
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon" style="color:#D4A017;">&#128205;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>' + data.ciudad + ', ' + data.estado + '</strong>';
        html += '</div>';
        html += '</div>';

        // Centro Voltika Autorizado
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon vk-paso3-shield">&#128737;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>Centro Voltika Autorizado</strong>';
        html += '<div class="vk-paso3-sub">\u00b7 Revisi\u00f3n y activaci\u00f3n profesional</div>';
        if (esCredito) {
            html += '<div class="vk-paso3-sub">\u00b7 <strong>Flete incluido en Cr\u00e9dito Voltika</strong></div>';
        } else {
            html += '<div class="vk-paso3-sub">\u00b7 Log\u00edstica a tu ciudad</div>';
        }
        html += '</div>';
        html += '</div>';

        // Costo logístico (only for contado/MSI)
        if (!esCredito) {
            html += '<div class="vk-paso3-section">';
            html += '<div class="vk-paso3-section__icon">&#128666;</div>';
            html += '<div class="vk-paso3-section__content">';
            html += '<strong>Costo log\u00edstico para tu zona: ' + VkUI.formatPrecio(config.costoLogistico) + ' MXN</strong>';
            html += '<div class="vk-paso3-sub">\u00b7 Transporte especializado</div>';
            html += '<div class="vk-paso3-sub">\u00b7 Revisi\u00f3n y activaci\u00f3n profesional</div>';
            html += '<div class="vk-paso3-sub">\u00b7 Entrega personalizada</div>';
            html += '</div>';
            html += '</div>';
        }

        // Entrega garantizada
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon">&#128197;</div>';
        html += '<div class="vk-paso3-section__content">';
        if (esCredito) {
            html += '<strong>Entrega Garantizada</strong><br>';
        }
        html += '<strong>Entrega garantizada antes del ' + fechaEntrega + '</strong>';
        html += '<div class="vk-paso3-sub">Tu <strong>Asesor Personal Voltika</strong> confirmar\u00e1 contigo el punto exacto de entrega en <strong>m\u00e1x. 48 horas</strong>.</div>';
        html += '</div>';
        html += '</div>';

        // Asesoría para placas
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon vk-paso3-check">&#9745;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>Asesor\u00eda para placas en tu estado</strong>';
        html += '<div class="vk-paso3-sub">Te conectamos con gestores verificados.</div>';
        html += '<div class="vk-paso3-sub">Pago directo al gestor.</div>';
        html += '</div>';
        html += '</div>';

        // Seguro Qualitas
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon vk-paso3-check">&#9745;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>Seguro activo Qual\u00edtas desde la entrega</strong> <img src="img/qualitas-logo.png" alt="Qualitas" style="height:18px;vertical-align:middle;margin-left:6px;" onerror="this.outerHTML=\'<span style=font-size:12px;color:var(--vk-text-muted);font-weight:600;margin-left:6px;>|| Qual\u00edtas</span>\'">';
        html += '<div class="vk-paso3-sub">Cotizamos y enviamos tu p\u00f3liza.</div>';
        html += '<div class="vk-paso3-sub">Pago directo a la aseguradora.</div>';
        html += '</div>';
        html += '</div>';

        $('#vk-cp-results').html(html).slideDown(300);
        $('#vk-paso3-confirmar').fadeIn(300);
        $('#vk-paso3-footer').fadeIn(300);
    },

    bindEvents: function() {
        var self = this;

        $(document).off('input', '#vk-cp-input').off('click', '#vk-paso3-confirmar');

        // Postal code input
        $(document).on('input', '#vk-cp-input', function() {
            var val = $(this).val().replace(/\D/g, '');
            $(this).val(val);

            if (val.length === 5) {
                self.buscarCP(val);
            } else {
                $('#vk-cp-results').slideUp(200);
                $('#vk-paso3-confirmar').fadeOut(200);
                $('#vk-paso3-footer').fadeOut(200);
            }
        });

        // Confirm — goes to Resumen
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
            this.renderResults(resultado);
        } else {
            var html = '<div style="text-align:center;padding:16px;color:var(--vk-text-muted);">' +
                '<p>No encontramos informaci\u00f3n para el c\u00f3digo postal <strong>' + cp + '</strong>.</p>' +
                '<p style="font-size:12px;margin-top:8px;">Verifica el c\u00f3digo e intenta de nuevo.</p>' +
                '</div>';
            $('#vk-cp-results').html(html).slideDown(300);
            $('#vk-paso3-confirmar').fadeOut(200);
        }
    }
};
