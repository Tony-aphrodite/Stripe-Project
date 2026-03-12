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

        html += VkUI.renderCreditoStepBar(1);

        html += '<h2 class="vk-paso__titulo">\u00bfCu\u00e1l es tu fecha de nacimiento?</h2>';
        html += '<p class="vk-paso__subtitulo">Nos ayuda a validar tu identidad para aprobar tu cr\u00e9dito Voltika.</p>';
        html += '<p class="vk-trust-highlight"><span class="vk-check"></span> Tu aprobaci\u00f3n tarda <strong>menos de 2 minutos</strong></p>';

        // Calculate max date (must be 18+ years old)
        var hoy = new Date();
        var maxDate = new Date(hoy.getFullYear() - 18, hoy.getMonth(), hoy.getDate());
        var maxDateStr = maxDate.toISOString().split('T')[0];
        var defaultFecha = state.fechaNacimiento || '';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Fecha de nacimiento</label>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Debes ser mayor de 18 a\u00f1os para solicitar un cr\u00e9dito.</div>';
        html += '<input type="date" class="vk-form-input" id="vk-cnac-fecha" ' +
            'value="' + defaultFecha + '" ' +
            'max="' + maxDateStr + '" min="1940-01-01" ' +
            'style="color:#111;font-size:15px;padding:12px 14px;cursor:pointer;">';
        html += '</div>';

        html += '<div id="vk-cnac-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cnac-continuar">CONTINUAR</button>';

        html += '<div class="vk-trust">';
        html += '<div><span class="vk-check vk-check--sm"></span> Proceso seguro</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> No afecta tu historial crediticio</div>';
        html += '</div>';

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

            // Validate age >= 18
            var nacimiento = new Date(fecha + 'T00:00:00');
            var hoy = new Date();
            var edad = hoy.getFullYear() - nacimiento.getFullYear();
            var mesDiff = hoy.getMonth() - nacimiento.getMonth();
            if (mesDiff < 0 || (mesDiff === 0 && hoy.getDate() < nacimiento.getDate())) {
                edad--;
            }
            if (edad < 18) {
                jQuery('#vk-cnac-error').text('Debes tener al menos 18 a\u00f1os para solicitar un cr\u00e9dito Voltika.').show();
                return;
            }

            jQuery('#vk-cnac-error').hide();

            self.app.state.fechaNacimiento = fecha;
            self.app.irAPaso('credito-cp-dom');
        });
    }
};
