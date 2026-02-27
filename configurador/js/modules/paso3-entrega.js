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
        var html = '';

        // Back button
        html += VkUI.renderBackButton(2);

        // Header
        html += '<h2 class="vk-paso__titulo">PASO 3</h2>';
        html += '<p class="vk-paso__subtitulo">Entrega en tu ciudad</p>';

        // Postal code form
        html += '<div class="vk-info-box">';
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label" for="vk-cp-input"><strong>Ingresa tu Codigo Postal</strong></label>';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" placeholder="C.P. 5 digitos" maxlength="5" inputmode="numeric" pattern="[0-9]*">';
        html += '</div>';

        // Results container (hidden initially)
        html += '<div id="vk-cp-results" style="display:none;"></div>';

        html += '</div>'; // end info-box

        // CTA (hidden until postal code is valid)
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="display:none;">CONFIRMAR PEDIDO</button>';

        // Microcopy
        html += '<p class="vk-card__footer-note" id="vk-paso3-footer" style="display:none;">' +
            'Recibiras confirmacion por WhatsApp y correo electronico.' +
            '</p>';

        $('#vk-entrega-container').html(html);
    },

    renderResults: function(data) {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;

        var costoEnvio = esCredito ? 0 : config.costoLogistico;
        state.costoLogistico = costoEnvio;
        state.ciudad = data.ciudad;
        state.estado = data.estado;

        var html = '';

        // City name
        html += '<div class="vk-info-box__title">' +
            '<span class="vk-info-box__title-icon">&#9745;</span> ' +
            '<strong>' + data.ciudad + ', ' + data.estado + '</strong>' +
            '</div>';

        // Centro info
        html += '<div style="background:var(--vk-green-soft);padding:12px;border-radius:8px;margin-bottom:12px;">';
        html += '<div style="font-weight:700;font-size:14px;">Entrega en <strong>Centro Voltika Autorizado</strong></div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Centro certificado para revision y entrega profesional.' +
            '</div>';
        html += '</div>';

        // Shipping cost
        html += '<div class="vk-info-box__detail">';
        html += '<span class="vk-info-box__detail-icon">&#10004;</span> ';
        if (esCredito) {
            html += '<strong>En Credito Voltika: Flete incluido</strong>';
        } else {
            html += 'Costo logistico: <strong>' + VkUI.formatPrecio(costoEnvio) + ' MXN</strong>';
        }
        html += '</div>';

        // Delivery time
        html += '<div class="vk-info-box__detail">' +
            '<span style="font-weight:700;">Entrega estimada: ' + config.entregaDiasHabiles + ' dias habiles</span>' +
            '</div>';

        // Advisor note
        html += '<div class="vk-info-box__note">' +
            'Nuestro equipo de logistica confirmara contigo el Centro Voltika mas cercano dentro de las proximas ' + config.contactoHoras + ' horas.' +
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
