/* ==========================================================================
   Voltika - Crédito Screen 6: Nombre completo
   Per Dibujo.pdf: Nombre(s), Apellido Paterno, Apellido Materno
   → Sent to Círculo de Crédito
   ========================================================================== */

var PasoCreditoNombre = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('resumen');

        // Step progress bar
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

        html += '<h2 class="vk-paso__titulo">Verifica tu identidad</h2>';
        html += '<p class="vk-paso__subtitulo">Tal como aparece en tu INE</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre(s)</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cn-nombre" ' +
            'placeholder="Ej: Juan Carlos" autocomplete="given-name" ' +
            'value="' + (state.nombre || '') + '">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Apellido Paterno</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cn-paterno" ' +
            'placeholder="Ej: P\u00e9rez" autocomplete="family-name" ' +
            'value="' + (state.apellidoPaterno || '') + '">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Apellido Materno</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cn-materno" ' +
            'placeholder="Ej: L\u00f3pez" ' +
            'value="' + (state.apellidoMaterno || '') + '">';
        html += '</div>';

        html += '<div id="vk-cn-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cn-continuar">CONTINUAR</button>';

        html += '</div>';

        jQuery('#vk-credito-nombre-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-cn-continuar');
        jQuery(document).on('click', '#vk-cn-continuar', function() {
            var nombre  = jQuery('#vk-cn-nombre').val().trim();
            var paterno = jQuery('#vk-cn-paterno').val().trim();
            var materno = jQuery('#vk-cn-materno').val().trim();

            var errores = [];
            if (!nombre || nombre.length < 2) errores.push('Ingresa tu nombre.');
            if (!paterno || paterno.length < 2) errores.push('Ingresa tu apellido paterno.');
            if (!materno || materno.length < 2) errores.push('Ingresa tu apellido materno.');

            if (errores.length) {
                jQuery('#vk-cn-error').html(errores.join('<br>')).show();
                return;
            }
            jQuery('#vk-cn-error').hide();

            self.app.state.nombre = nombre;
            self.app.state.apellidoPaterno = paterno;
            self.app.state.apellidoMaterno = materno;

            self.app.irAPaso('credito-nacimiento');
        });
    }
};
