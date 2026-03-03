/* ==========================================================================
   Voltika - PASO 3: Entrega / Codigo Postal
   Matches client reference: simplified Centro Voltika + logistics layout
   ========================================================================== */

var Paso3 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
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
        var backTarget = (esCredito && state.creditoAprobado) ? 'credito-otp' : 2;
        html += VkUI.renderBackButton(backTarget);

        // Step header
        html += '<div class="vk-paso-header">';
        html += '<div class="vk-paso-header__step">PASO 3</div>';
        html += '<h2 class="vk-paso-header__title">Entrega en tu ciudad</h2>';
        html += '</div>';

        // Postal code input with search icon
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<label class="vk-form-label"><strong>Ingresa tu C\u00f3digo Postal</strong></label>';
        html += '<div class="vk-form-group" style="position:relative;">';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" ' +
            'placeholder="C.P. 5 d\u00edgitos" ' +
            'maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'style="padding-right:40px;">';
        html += '<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--vk-text-muted);font-size:18px;">&#128269;</span>';
        html += '</div>';

        // Results container
        html += '<div id="vk-cp-results" style="display:none;"></div>';

        // CTA (hidden until CP found)
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="display:none;">CONFIRMAR PEDIDO</button>';

        // Microcopy
        html += '<p id="vk-paso3-footer" style="display:none;text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
            'Recibir\u00e1s confirmaci\u00f3n por WhatsApp y correo electr\u00f3nico.' +
            '</p>';

        html += '</div>'; // end card

        $('#vk-entrega-container').html(html);
    },

    renderResults: function(data) {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;

        state.costoLogistico = esCredito ? 0 : config.costoLogistico;
        state.ciudad = data.ciudad;
        state.estado = data.estado;

        var html = '';

        // City/State with shield icon
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon vk-paso3-shield">&#128737;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>' + data.ciudad + ', ' + data.estado + '</strong>';
        html += '</div>';
        html += '</div>';

        // Centro Voltika Autorizado
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon vk-paso3-shield">&#128737;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>Entrega en Centro Voltika Autorizado</strong>';
        html += '<div class="vk-paso3-sub">Centro certificado para revisi\u00f3n y entrega profesional.</div>';
        html += '</div>';
        html += '</div>';

        // Costo logistico
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon">&#10004;</div>';
        html += '<div class="vk-paso3-section__content">';
        if (esCredito) {
            html += '<strong>Costo log\u00edstico: incluido en cr\u00e9dito</strong>';
        } else {
            html += '<strong>Costo log\u00edstico: ' + VkUI.formatPrecio(config.costoLogistico) + ' MXN</strong>';
        }
        html += '</div>';
        html += '</div>';

        // Entrega estimada
        html += '<div class="vk-paso3-section">';
        html += '<div class="vk-paso3-section__icon">&#128197;</div>';
        html += '<div class="vk-paso3-section__content">';
        html += '<strong>Entrega estimada: ' + config.entregaDiasHabiles + ' d\u00edas h\u00e1biles</strong>';
        html += '</div>';
        html += '</div>';

        // Contact info
        html += '<div class="vk-paso3-note">';
        html += 'Nuestro equipo de log\u00edstica confirmar\u00e1 contigo el Centro Voltika m\u00e1s cercano dentro de las pr\u00f3ximas <strong>' + config.contactoHoras + ' horas</strong>.';
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
