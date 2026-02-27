/* ==========================================================================
   Voltika - PASO 3: Postal Code / Delivery
   Postal code lookup, Centro Voltika display, shipping cost
   ========================================================================== */

var Paso3 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;
        var html = '';

        // Back button
        html += VkUI.renderBackButton(2);

        // Header
        html += '<h2 class="vk-paso__titulo">PASO 3</h2>';
        html += '<p class="vk-paso__subtitulo">Entrega en tu ciudad</p>';

        // Postal code form
        html += '<div class="vk-info-box">';
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label" for="vk-cp-input"><strong>Ingresa tu C\u00f3digo Postal</strong></label>';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" placeholder="C.P. 5 d\u00edgitos" maxlength="5" inputmode="numeric" pattern="[0-9]*">';
        html += '</div>';

        // City result (hidden until CP found)
        html += '<div id="vk-cp-results" style="display:none;"></div>';

        // Static delivery info — always visible
        html += '<div style="margin-top:14px;">';

        html += '<div style="background:var(--vk-green-soft);padding:12px;border-radius:8px;margin-bottom:10px;">';
        html += '<div style="font-weight:700;font-size:14px;">Entrega en <strong>Centro Voltika Autorizado</strong></div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Centro certificado para revisi\u00f3n y entrega profesional.' +
            '</div>';
        html += '</div>';

        // Logistics cost
        html += '<div class="vk-info-box__detail">';
        html += '<span class="vk-info-box__detail-icon">&#10004;</span> ';
        if (esCredito) {
            html += '<strong>En Cr\u00e9dito Voltika: Flete incluido</strong>';
        } else {
            html += 'Costo log\u00edstico: <strong>' + VkUI.formatPrecio(config.costoLogistico) + ' MXN</strong>';
        }
        html += '</div>';

        // Delivery time
        html += '<div class="vk-info-box__detail">' +
            '<span style="font-weight:700;">Entrega estimada: ' + config.entregaDiasHabiles + ' d\u00edas h\u00e1biles</span>' +
            '</div>';

        // Advisor note
        html += '<div class="vk-info-box__note">' +
            'Nuestro equipo de log\u00edstica confirmar\u00e1 contigo el Centro Voltika m\u00e1s cercano dentro de las pr\u00f3ximas ' + config.contactoHoras + ' horas.' +
            '</div>';

        html += '</div>'; // end static info

        html += '</div>'; // end info-box

        // CTA (hidden until postal code is valid)
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="display:none;">CONFIRMAR PEDIDO</button>';

        // Microcopy
        html += '<p class="vk-card__footer-note" id="vk-paso3-footer" style="display:none;">' +
            'Recibir\u00e1s confirmaci\u00f3n por WhatsApp y correo electr\u00f3nico.' +
            '</p>';

        $('#vk-entrega-container').html(html);
    },

    renderResults: function(data) {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;

        state.costoLogistico = esCredito ? 0 : config.costoLogistico;
        state.ciudad = data.ciudad;
        state.estado = data.estado;

        // Show city confirmation
        var html = '<div class="vk-info-box__title">' +
            '<span class="vk-info-box__title-icon">&#9745;</span> ' +
            '<strong>' + data.ciudad + ', ' + data.estado + '</strong>' +
            '</div>';

        $('#vk-cp-results').html(html).slideDown(300);
        $('#vk-paso3-confirmar').fadeIn(300);
        $('#vk-paso3-footer').fadeIn(300);
    },

    bindEvents: function() {
        var self = this;

        // Remove previous handlers to prevent duplicates on re-init
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

        // Confirm order
        $(document).on('click', '#vk-paso3-confirmar', function() {
            var cp = $('#vk-cp-input').val();
            if (VkValidacion.codigoPostal(cp)) {
                self.app.state.codigoPostal = cp;
                self.app.irAPaso(4);
            }
        });
    },

    buscarCP: function(cp) {
        // Phase 1: Local lookup
        var resultado = VOLTIKA_CP._buscar(cp);

        if (resultado) {
            this.renderResults(resultado);
        } else {
            // Show not found message
            var html = '<div style="text-align:center;padding:16px;color:var(--vk-text-muted);">' +
                '<p>No encontramos informacion para el codigo postal <strong>' + cp + '</strong>.</p>' +
                '<p style="font-size:12px;margin-top:8px;">Verifica el codigo e intenta de nuevo.</p>' +
                '</div>';
            $('#vk-cp-results').html(html).slideDown(300);
            $('#vk-paso3-confirmar').fadeOut(200);
        }
    }
};
