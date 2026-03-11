/* ==========================================================================
   Voltika - Crédito Screen 9: Domicilio
   Per Dibujo.pdf: Calle, Número exterior, Colonia
   ========================================================================== */

var PasoCreditoDomicilio = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-cp-dom');
        html += VkUI.renderCreditoStepBar(2);

        html += '<h2 class="vk-paso__titulo">Direcci\u00f3n de tu domicilio</h2>';
        html += '<p class="vk-paso__subtitulo">Para continuar con tu solicitud de cr\u00e9dito (como aparece en tu INE o comprobante)</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Calle</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cdom-calle" ' +
            'placeholder="Ej: Av. Reforma" autocomplete="street-address" ' +
            'value="' + (state.calle || '') + '">';
        html += '</div>';

        html += '<div style="display:flex;gap:10px;">';
        html += '<div class="vk-form-group" style="flex:1;">';
        html += '<label class="vk-form-label">N\u00famero exterior</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cdom-numero" ' +
            'placeholder="123" ' +
            'value="' + (state.numeroExterior || '') + '">';
        html += '</div>';
        html += '<div class="vk-form-group" style="flex:1;">';
        html += '<label class="vk-form-label">N\u00famero interior <span style="font-weight:400;color:var(--vk-text-muted);">(opcional)</span></label>';
        html += '<input type="text" class="vk-form-input" id="vk-cdom-interior" ' +
            'placeholder="Ej: 4B" ' +
            'value="' + (state.numeroInterior || '') + '">';
        html += '</div>';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Colonia</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cdom-colonia" ' +
            'placeholder="Ej: Centro" ' +
            'value="' + (state.colonia || '') + '">';
        html += '</div>';

        html += '<div class="vk-trust"><div><span class="vk-check vk-check--sm"></span> Informaci\u00f3n protegida</div></div>';

        html += '<div id="vk-cdom-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cdom-continuar">CONTINUAR \u2192</button>';
        html += '<div class="vk-hint">Solo toma unos segundos</div>';

        html += '</div>';

        jQuery('#vk-credito-domicilio-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-cdom-continuar');
        jQuery(document).on('click', '#vk-cdom-continuar', function() {
            var calle    = jQuery('#vk-cdom-calle').val().trim();
            var numero   = jQuery('#vk-cdom-numero').val().trim();
            var interior = jQuery('#vk-cdom-interior').val().trim();
            var colonia  = jQuery('#vk-cdom-colonia').val().trim();

            var errores = [];
            if (!calle || calle.length < 3) errores.push('Ingresa tu calle.');
            if (!numero) errores.push('Ingresa tu n\u00famero exterior.');
            if (!colonia || colonia.length < 2) errores.push('Ingresa tu colonia.');

            if (errores.length) {
                jQuery('#vk-cdom-error').html(errores.join('<br>')).show();
                return;
            }
            jQuery('#vk-cdom-error').hide();

            self.app.state.calle = calle;
            self.app.state.numeroExterior = numero;
            self.app.state.numeroInterior = interior;
            self.app.state.colonia = colonia;

            self.app.irAPaso('credito-ingresos');
        });
    }
};
