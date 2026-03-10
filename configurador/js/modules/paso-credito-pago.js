/* ==========================================================================
   Voltika - Crédito: Tu Voltika está lista
   Confirmation screen before Stripe enganche payment
   ========================================================================== */

var PasoCreditoPago = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var enganchePct = state.enganchePorcentaje || 0.30;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, state.plazoMeses || 12);
        var enganche    = credito.enganche;

        var html = '';

        // Logo
        html += '<div class="vk-cpago-logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';

        // Title
        html += '<h2 class="vk-cpago-title">Tu Voltika est\u00e1 lista</h2>';

        // Subtitle
        html += '<p class="vk-cpago-subtitle">';
        html += 'Realiza el <strong>pago de tu enganche</strong> para preparar tu Voltika y programar la entrega.';
        html += '</p>';

        // Info note
        html += '<div class="vk-cpago-note">';
        html += '&#128274; Este pago se aplica directamente a tu financiamiento.';
        html += '</div>';

        // Enganche amount summary
        html += '<div class="vk-cpago-amount">';
        html += '<div class="vk-cpago-amount__label">Enganche (' + Math.round(enganchePct * 100) + '%) &mdash; ' + modelo.nombre + '</div>';
        html += '<div class="vk-cpago-amount__value">' + VkUI.formatPrecio(enganche) + ' <span>MXN</span></div>';
        html += '<div class="vk-cpago-amount__detail">';
        html += 'Plazo: ' + (state.plazoMeses || 12) + ' meses &middot; ';
        html += 'Pago semanal: ' + VkUI.formatPrecio(credito.pagoSemanal);
        html += '</div>';
        html += '</div>';

        // Checkboxes
        html += '<div class="vk-cpago-checks">';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check1">';
        html += '<span class="vk-cpago-check__box"></span>';
        html += '<span class="vk-cpago-check__text">Confirmo que deseo <strong>continuar</strong> con mi cr\u00e9dito Voltika</span>';
        html += '</label>';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check2">';
        html += '<span class="vk-cpago-check__box"></span>';
        html += '<span class="vk-cpago-check__text">Acepto los t\u00e9rminos del <strong>cr\u00e9dito</strong> y registro de m\u00e9todo de pago</span>';
        html += '</label>';

        html += '</div>';

        // Error
        html += '<div id="vk-cpago-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA button (disabled until both checked)
        html += '<button class="vk-btn vk-btn--primary vk-cpago-btn" id="vk-cpago-continuar" disabled>';
        html += '&#128274; PAGAR ENGANCHE ' + VkUI.formatPrecio(enganche) + ' MXN &rsaquo;';
        html += '</button>';

        // Card logos footer
        html += '<div class="vk-cpago-footer">';
        html += 'Pago cifrado SSL &middot; ' + VkUI.renderCardLogos();
        html += '</div>';

        jQuery('#vk-credito-pago-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Enable/disable button based on both checkboxes
        jQuery(document).off('change', '#vk-cpago-check1, #vk-cpago-check2');
        jQuery(document).on('change', '#vk-cpago-check1, #vk-cpago-check2', function() {
            var both = jQuery('#vk-cpago-check1').is(':checked') &&
                       jQuery('#vk-cpago-check2').is(':checked');
            jQuery('#vk-cpago-continuar').prop('disabled', !both);
        });

        // Proceed to Stripe payment
        jQuery(document).off('click', '#vk-cpago-continuar');
        jQuery(document).on('click', '#vk-cpago-continuar', function() {
            self.app.irAPaso('credito-enganche');
        });
    }
};
