/* ==========================================================================
   Voltika - Crédito Screen 7: Fecha de nacimiento
   Per Dibujo.pdf: Day/Month/Year calendar input
   ========================================================================== */

var PasoCreditoNacimiento = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-nombre');

        html += '<h2 class="vk-paso__titulo">Fecha de nacimiento</h2>';
        html += '<p class="vk-paso__subtitulo">Requerida para tu solicitud de cr\u00e9dito</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Fecha (D\u00eda/Mes/A\u00f1o)</label>';
        html += '<input type="date" class="vk-form-input" id="vk-cnac-fecha" ' +
            'value="' + (state.fechaNacimiento || '') + '" ' +
            'max="2008-01-01" min="1940-01-01">';
        html += '</div>';

        html += '<div id="vk-cnac-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cnac-continuar">CONTINUAR</button>';

        html += '</div>';

        jQuery('#vk-credito-nacimiento-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-cnac-continuar');
        jQuery(document).on('click', '#vk-cnac-continuar', function() {
            var fecha = jQuery('#vk-cnac-fecha').val();

            if (!fecha) {
                jQuery('#vk-cnac-error').text('Ingresa tu fecha de nacimiento.').show();
                return;
            }
            jQuery('#vk-cnac-error').hide();

            self.app.state.fechaNacimiento = fecha;
            self.app.irAPaso('credito-cp-dom');
        });
    }
};
