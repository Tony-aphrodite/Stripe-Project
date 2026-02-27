/* ==========================================================================
   Voltika - Facturación y Envío (post-payment)
   Billing and shipping details collected after successful Stripe payment
   ========================================================================== */

var PasoFacturacion = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var total  = state.totalPagado || 0;

        var html = '';

        // No back button — payment is done
        html += '<div style="background:var(--vk-green-soft);border:2px solid var(--vk-green-primary);' +
            'border-radius:12px;padding:16px 20px;margin-bottom:20px;text-align:center;">';
        html += '<div style="font-size:36px;">&#10004;</div>';
        html += '<div style="font-weight:800;font-size:18px;color:var(--vk-green-primary);">' +
            '\u00a1Pago realizado con \u00e9xito!</div>';
        if (modelo) {
            html += '<div style="font-size:13px;margin-top:4px;">' +
                modelo.nombre + ' &middot; ' + VkUI.formatPrecio(total) + ' MXN</div>';
        }
        html += '</div>';

        html += '<h2 class="vk-paso__titulo">Datos para facturaci\u00f3n</h2>';
        html += '<p class="vk-paso__subtitulo">Completa tus datos para tu factura y env\u00edo</p>';

        html += '<div class="vk-card">';
        html += '<div style="padding:16px 20px;">';

        // Billing section
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:12px;">Datos de facturaci\u00f3n</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">RFC</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-rfc" ' +
            'placeholder="XAXX010101000" maxlength="13" ' +
            'style="text-transform:uppercase;">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Raz\u00f3n social / Nombre fiscal</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-razon" ' +
            'placeholder="JUAN PEREZ GARCIA" value="' + (state.nombre || '') + '">';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Uso de CFDI</label>';
        html += '<select class="vk-form-input" id="vk-fac-cfdi">';
        html += '<option value="G03">G03 - Gastos en general</option>';
        html += '<option value="D06">D06 - Aportaciones voluntarias al SAR</option>';
        html += '<option value="S01">S01 - Sin efectos fiscales</option>';
        html += '</select>';
        html += '</div>';

        html += '<div style="border-top:1px solid var(--vk-border);margin:16px 0;"></div>';

        // Shipping section
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:12px;">Datos de env\u00edo</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Calle y n\u00famero</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-calle" ' +
            'placeholder="Av. Ejemplo 123 Col. Centro" autocomplete="street-address">';
        html += '</div>';

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">C.P.</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-cp" ' +
            'placeholder="45000" maxlength="5" inputmode="numeric" ' +
            'value="' + (state.codigoPostal || '') + '">';
        html += '</div>';
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Ciudad</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-ciudad" ' +
            'placeholder="Ciudad" value="' + (state.ciudad || '') + '">';
        html += '</div>';
        html += '</div>';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Estado</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-estado" ' +
            'placeholder="Estado" value="' + (state.estado || '') + '">';
        html += '</div>';

        html += '<div id="vk-fac-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-fac-confirmar">' +
            'Confirmar datos &#10140;</button>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin-top:8px;">' +
            'Recibirás tu factura por correo en 24-48 horas.' +
            '</p>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-facturacion-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-fac-confirmar');
        jQuery(document).on('click', '#vk-fac-confirmar', function() {
            self._submit();
        });
    },

    _submit: function() {
        var rfc     = jQuery('#vk-fac-rfc').val().trim().toUpperCase();
        var calle   = jQuery('#vk-fac-calle').val().trim();
        var cp      = jQuery('#vk-fac-cp').val().trim();
        var ciudad  = jQuery('#vk-fac-ciudad').val().trim();
        var estado  = jQuery('#vk-fac-estado').val().trim();
        var errores = [];

        if (!calle) errores.push('Ingresa tu calle y n\u00famero.');
        if (!cp || cp.length !== 5) errores.push('Ingresa tu C\u00f3digo Postal de env\u00edo.');
        if (!ciudad) errores.push('Ingresa tu ciudad.');

        if (errores.length) {
            jQuery('#vk-fac-error').html(errores.join('<br>')).show();
            return;
        }
        jQuery('#vk-fac-error').hide();

        var $btn = jQuery('#vk-fac-confirmar');
        $btn.prop('disabled', true).text('Guardando...');

        var self  = this;
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);

        jQuery.ajax({
            url: 'php/confirmar-facturacion.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:   state.nombre,
                email:    state.email,
                telefono: state.telefono,
                modelo:   modelo ? modelo.nombre : '',
                color:    state.colorSeleccionado || '',
                ciudad:   ciudad,
                estado:   estado,
                cp:       cp,
                calle:    calle,
                rfc:      rfc,
                cfdi:     jQuery('#vk-fac-cfdi').val(),
                razon:    jQuery('#vk-fac-razon').val().trim(),
                total:    state.totalPagado || 0
            }),
            complete: function() {
                $btn.prop('disabled', false).text('Confirmar datos \u2192');
                self.app.irAPaso('exito');
            }
        });
    }
};
