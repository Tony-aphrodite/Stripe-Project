/* ==========================================================================
   Voltika - Crédito Screen 8: Código Postal (domicilio)
   Per Dibujo.pdf: 5 digits, auto-fill state from database
   ========================================================================== */

var PasoCreditoCPDom = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-nacimiento');

        html += '<h2 class="vk-paso__titulo">Tu domicilio actual</h2>';
        html += '<p class="vk-paso__subtitulo">C\u00f3digo Postal de tu domicilio</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">C\u00f3digo Postal (5 d\u00edgitos)</label>';
        html += '<input type="text" class="vk-form-input" id="vk-ccp-cp" ' +
            'placeholder="Ej: 44100" maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.cpDomicilio || '') + '">';
        html += '</div>';

        html += '<div id="vk-ccp-estado" style="display:none;padding:10px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
        html += '<strong id="vk-ccp-estado-text"></strong>';
        html += '</div>';

        html += '<div id="vk-ccp-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-ccp-continuar" disabled>CONTINUAR</button>';

        html += '</div>';

        jQuery('#vk-credito-cp-dom-container').html(html);

        // Auto-lookup if already have CP
        if (state.cpDomicilio && state.cpDomicilio.length === 5) {
            setTimeout(function() {
                jQuery('#vk-ccp-cp').trigger('input');
            }, 200);
        }
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('input', '#vk-ccp-cp').off('click', '#vk-ccp-continuar');

        jQuery(document).on('input', '#vk-ccp-cp', function() {
            var val = jQuery(this).val().replace(/\D/g, '');
            jQuery(this).val(val);

            if (val.length === 5) {
                var resultado = VOLTIKA_CP._buscar(val);
                if (resultado) {
                    jQuery('#vk-ccp-estado-text').text(resultado.ciudad + ', ' + resultado.estado);
                    jQuery('#vk-ccp-estado').slideDown(200);
                    jQuery('#vk-ccp-continuar').prop('disabled', false);
                    self.app.state.estadoDomicilio = resultado.estado;
                } else {
                    jQuery('#vk-ccp-estado').hide();
                    jQuery('#vk-ccp-continuar').prop('disabled', true);
                }
            } else {
                jQuery('#vk-ccp-estado').hide();
                jQuery('#vk-ccp-continuar').prop('disabled', true);
            }
        });

        jQuery(document).on('click', '#vk-ccp-continuar', function() {
            var cp = jQuery('#vk-ccp-cp').val().trim();
            if (!cp || cp.length !== 5) {
                jQuery('#vk-ccp-error').text('Ingresa un C\u00f3digo Postal v\u00e1lido.').show();
                return;
            }
            jQuery('#vk-ccp-error').hide();

            self.app.state.cpDomicilio = cp;
            self.app.irAPaso('credito-domicilio');
        });
    }
};
