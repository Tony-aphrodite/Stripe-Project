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

        // Title
        html += '<h2 class="vk-cpago-title">Tu Voltika est\u00e1 lista</h2>';

        // Subtitle
        html += '<p class="vk-cpago-subtitle">';
        html += 'Realiza el <strong>pago de tu enganche</strong> para preparar tu Voltika y programar la entrega.';
        html += '</p>';

        // Info note — plain text, no box
        html += '<p style="text-align:center;font-size:13px;color:var(--vk-text-muted);margin-bottom:20px;">';
        html += 'Este pago se aplica directamente a tu financiamiento.';
        html += '</p>';

        // Checkboxes — pre-checked
        html += '<div class="vk-cpago-checks">';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check1" checked>';
        html += '<span class="vk-cpago-check__box"></span>';
        html += '<span class="vk-cpago-check__text">Confirmo que deseo <strong>continuar</strong> con mi cr\u00e9dito Voltika</span>';
        html += '</label>';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check2" checked>';
        html += '<span class="vk-cpago-check__box"></span>';
        html += '<span class="vk-cpago-check__text">Acepto los t\u00e9rminos del <strong>cr\u00e9dito</strong> y registro de m\u00e9todo de pago</span>';
        html += '</label>';

        html += '</div>';

        // Error
        html += '<div id="vk-cpago-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA button — enabled by default (both pre-checked)
        html += '<button id="vk-cpago-continuar" style="display:block;width:100%;padding:16px;background:#1b5e3b;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">';
        html += 'PAGAR ENGANCHE &rsaquo;';
        html += '</button>';

        // Card logos footer
        html += '<div class="vk-cpago-footer">';
        html += 'Pago cifrado SSL &middot; ' + VkUI.renderCardLogos();
        html += '</div>';

        jQuery('#vk-credito-pago-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Disable button if either checkbox is unchecked
        jQuery(document).off('change', '#vk-cpago-check1, #vk-cpago-check2');
        jQuery(document).on('change', '#vk-cpago-check1, #vk-cpago-check2', function() {
            var both = jQuery('#vk-cpago-check1').is(':checked') &&
                       jQuery('#vk-cpago-check2').is(':checked');
            jQuery('#vk-cpago-continuar').prop('disabled', !both)
                .css('opacity', both ? '1' : '0.5');
        });

        // Proceed to Stripe payment
        jQuery(document).off('click', '#vk-cpago-continuar');
        jQuery(document).on('click', '#vk-cpago-continuar', function() {
            self.app.irAPaso('credito-enganche');
        });
    }
};
