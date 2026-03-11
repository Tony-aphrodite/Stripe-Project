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

        // Step progress bar (Paso 1 de 5)
        html += '<div style="margin-bottom:16px;overflow:hidden;">';
        html += '<div style="font-size:12px;color:#999;margin-bottom:6px;">Paso 1 de 5</div>';
        html += '<div style="display:flex;align-items:center;gap:2px;">';
        var steps = [
            {num:1, label:'Datos'},
            {num:2, label:'Tel'},
            {num:3, label:'ID'},
            {num:4, label:'Entrega'},
            {num:5, label:'Pago'}
        ];
        for (var i = 0; i < steps.length; i++) {
            var s = steps[i];
            var isActive = s.num === 1;
            html += '<div style="display:flex;align-items:center;gap:2px;flex-shrink:0;">';
            html += '<span style="width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;' +
                (isActive ? 'background:#039fe1;color:#fff;' : 'background:#e5e7eb;color:#999;') + '">' + s.num + '</span>';
            html += '<span style="font-size:10px;font-weight:' + (isActive ? '700' : '400') + ';color:' + (isActive ? '#039fe1' : '#999') + ';">' + s.label + '</span>';
            html += '</div>';
            if (i < steps.length - 1) {
                html += '<div style="flex:1;height:1px;background:#e5e7eb;min-width:4px;"></div>';
            }
        }
        html += '</div>';
        html += '</div>';

        html += '<h2 class="vk-paso__titulo">\u00bfCu\u00e1l es tu fecha de nacimiento?</h2>';
        html += '<p class="vk-paso__subtitulo">Nos ayuda a validar tu identidad para aprobar tu cr\u00e9dito Voltika.</p>';
        html += '<p class="vk-trust-highlight"><span class="vk-check"></span> Tu aprobaci\u00f3n tarda <strong>menos de 2 minutos</strong></p>';

        // Today's date as default
        var today = new Date().toISOString().split('T')[0];
        var defaultFecha = state.fechaNacimiento || today;

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Fecha de nacimiento</label>';
        html += '<input type="date" class="vk-form-input" id="vk-cnac-fecha" ' +
            'value="' + defaultFecha + '" ' +
            'max="2008-01-01" min="1940-01-01" ' +
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
            jQuery('#vk-cnac-error').hide();

            self.app.state.fechaNacimiento = fecha;
            self.app.irAPaso('credito-cp-dom');
        });
    }
};
