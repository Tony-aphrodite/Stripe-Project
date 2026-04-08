/* ==========================================================================
   Voltika - Facturación (post-payment for contado/MSI)
   1. Payment success summary (MSI or contado details)
   2. Invoice question: ¿Requieres factura?
      - Sí → show all invoice fields (mandatory)
      - No → show CONTINUAR button directly
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
        var msiPago = modelo && modelo.tieneMSI ? Math.round(((modelo.precioMSI * 9) + (state.costoLogistico || 0)) / 9) : 0;
        var pagoTipo = state._pagoTipo || 'unico'; // 'msi' or 'unico'

        var html = '';

        // 1. Payment success banner
        html += '<div style="background:#E8F5E9;border:2px solid #4CAF50;border-radius:12px;padding:16px 20px;margin-bottom:20px;text-align:center;">';
        html += '<div style="font-size:36px;">&#10004;</div>';
        html += '<div style="font-weight:800;font-size:18px;color:#4CAF50;">\u00a1Pago realizado con \u00e9xito!</div>';
        if (modelo) {
            if (pagoTipo === 'msi') {
                html += '<div style="font-size:15px;font-weight:700;margin-top:6px;">' + VkUI.formatPrecio(msiPago) + ' MXN por 9 meses</div>';
                html += '<div style="font-size:12px;color:#555;">9 MSI sin intereses</div>';
            } else {
                html += '<div style="font-size:15px;font-weight:700;margin-top:6px;">' + VkUI.formatPrecio(total) + ' MXN un solo cargo de contado</div>';
            }
        }
        html += '</div>';

        // 2. Invoice section
        html += '<div class="vk-card" style="padding:20px;">';

        // Question: ¿Requieres factura?
        html += '<div style="font-weight:700;font-size:16px;margin-bottom:12px;">\u00bfRequieres factura?</div>';

        html += '<label style="display:flex;align-items:center;gap:10px;margin-bottom:8px;cursor:pointer;">';
        html += '<input type="radio" name="vk-factura-opcion" value="si" id="vk-factura-si" style="width:18px;height:18px;accent-color:#4CAF50;">';
        html += '<span style="font-size:14px;font-weight:600;">S\u00ed, deseo factura</span>';
        html += '</label>';

        html += '<label style="display:flex;align-items:center;gap:10px;margin-bottom:16px;cursor:pointer;">';
        html += '<input type="radio" name="vk-factura-opcion" value="no" id="vk-factura-no" style="width:18px;height:18px;accent-color:#4CAF50;">';
        html += '<span style="font-size:14px;font-weight:600;">No, compra sin factura</span>';
        html += '</label>';

        // Invoice fields (hidden by default)
        html += '<div id="vk-factura-fields" style="display:none;">';

        // RFC
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label" style="font-weight:700;">RFC (obligatorio)</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-rfc" placeholder="Ingrese su RFC" maxlength="13" style="text-transform:uppercase;">';
        html += '</div>';

        // Nombre o Razón Social
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label" style="font-weight:700;">Nombre o Raz\u00f3n Social</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-razon" placeholder="Como aparece en el SAT" value="' + (state.nombre || '') + '">';
        html += '</div>';

        // Régimen fiscal
        html += '<div style="margin-bottom:14px;">';
        html += '<div style="font-weight:700;font-size:13px;margin-bottom:8px;">Selecciona tu r\u00e9gimen fiscal:</div>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-regimen" value="fisica" style="accent-color:#4CAF50;"> <span style="font-size:13px;">Persona f\u00edsica</span></label>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-regimen" value="fisica_empresarial" style="accent-color:#4CAF50;"> <span style="font-size:13px;">Persona f\u00edsica con actividad empresarial</span></label>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-regimen" value="moral" style="accent-color:#4CAF50;"> <span style="font-size:13px;">Persona moral (empresa)</span></label>';
        html += '</div>';

        // ¿Usarás la moto para generar ingresos?
        html += '<div style="margin-bottom:14px;">';
        html += '<div style="font-weight:700;font-size:13px;margin-bottom:8px;">\u00bfUsar\u00e1s la moto para generar ingresos?</div>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-ingresos" value="si" style="accent-color:#4CAF50;"> <span style="font-size:13px;">S\u00ed</span></label>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-ingresos" value="no" style="accent-color:#4CAF50;"> <span style="font-size:13px;">No</span></label>';
        html += '</div>';

        // Uso de CFDI
        html += '<div style="margin-bottom:14px;">';
        html += '<div style="font-weight:700;font-size:13px;margin-bottom:8px;">Uso de CFDI:</div>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-cfdi" value="G03" checked style="accent-color:#4CAF50;"> <span style="font-size:13px;"><strong>G03</strong> \u2013 Gastos en general <span style="color:#4CAF50;font-size:11px;">(recomendado)</span></span></label>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-cfdi" value="S01" style="accent-color:#4CAF50;"> <span style="font-size:13px;"><strong>S01</strong> \u2013 Sin efectos fiscales</span></label>';
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"><input type="radio" name="vk-cfdi" value="I04" style="accent-color:#4CAF50;"> <span style="font-size:13px;"><strong>I04</strong> \u2013 Equipo de transporte <span style="color:#888;font-size:11px;">(uso empresarial)</span></span></label>';
        html += '</div>';

        // Código Postal fiscal
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label" style="font-weight:700;">C\u00f3digo Postal (obligatorio)</label>';
        html += '<input type="text" class="vk-form-input" id="vk-fac-cp" placeholder="C\u00f3digo Postal (obligatorio)" maxlength="5" inputmode="numeric" value="' + (state.codigoPostal || '') + '">';
        html += '</div>';

        // Confirmation checkbox
        html += '<label style="display:flex;align-items:flex-start;gap:10px;margin:14px 0;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-fac-confirmo" style="margin-top:3px;width:18px;height:18px;accent-color:#4CAF50;flex-shrink:0;">';
        html += '<span style="font-size:13px;color:#555;line-height:1.5;">Confirmo que los datos fiscales proporcionados son correctos y que el uso del CFDI fue seleccionado bajo mi responsabilidad.</span>';
        html += '</label>';

        // Note
        html += '<p style="font-size:12px;color:#888;text-align:center;font-style:italic;margin:10px 0;">La factura se emitir\u00e1 una vez asignada tu moto espec\u00edfica y ser\u00e1 enviada a tu correo electr\u00f3nico antes de la entrega.</p>';

        html += '</div>'; // end #vk-factura-fields

        // Error
        html += '<div id="vk-fac-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin:12px 0;text-align:center;font-weight:600;"></div>';

        // Button (changes based on selection)
        html += '<button class="vk-btn vk-btn--primary" id="vk-fac-submit" style="font-size:15px;font-weight:700;padding:14px;margin-top:12px;display:none;">CONTINUAR</button>';

        html += '</div>'; // end card

        jQuery('#vk-facturacion-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        jQuery(document)
            .off('change', 'input[name="vk-factura-opcion"]')
            .off('click', '#vk-fac-submit');

        // Toggle fields based on factura selection
        jQuery(document).on('change', 'input[name="vk-factura-opcion"]', function() {
            var val = jQuery(this).val();
            if (val === 'si') {
                jQuery('#vk-factura-fields').slideDown(200);
                jQuery('#vk-fac-submit').text('Generar factura').show();
            } else {
                jQuery('#vk-factura-fields').slideUp(200);
                jQuery('#vk-fac-submit').text('CONTINUAR').show();
            }
            jQuery('#vk-fac-error').hide();
        });

        // Submit
        jQuery(document).on('click', '#vk-fac-submit', function() {
            self._submit();
        });
    },

    _submit: function() {
        var wantsFactura = jQuery('#vk-factura-si').is(':checked');

        if (!wantsFactura && !jQuery('#vk-factura-no').is(':checked')) {
            jQuery('#vk-fac-error').text('Selecciona si deseas factura o no.').show();
            return;
        }

        if (wantsFactura) {
            // Validate all fields
            var rfc = jQuery('#vk-fac-rfc').val().trim().toUpperCase();
            var razon = jQuery('#vk-fac-razon').val().trim();
            var regimen = jQuery('input[name="vk-regimen"]:checked').val();
            var ingresos = jQuery('input[name="vk-ingresos"]:checked').val();
            var cfdi = jQuery('input[name="vk-cfdi"]:checked').val();
            var cpFiscal = jQuery('#vk-fac-cp').val().trim();
            var confirmo = jQuery('#vk-fac-confirmo').is(':checked');

            var errores = [];
            if (!rfc || rfc.length < 12) errores.push('Ingresa tu RFC v\u00e1lido.');
            if (!razon) errores.push('Ingresa tu Nombre o Raz\u00f3n Social.');
            if (!regimen) errores.push('Selecciona tu r\u00e9gimen fiscal.');
            if (!ingresos) errores.push('Indica si usar\u00e1s la moto para generar ingresos.');
            if (!cfdi) errores.push('Selecciona el uso de CFDI.');
            if (!cpFiscal || cpFiscal.length !== 5) errores.push('Ingresa tu C\u00f3digo Postal fiscal.');
            if (!confirmo) errores.push('Debes confirmar que los datos fiscales son correctos.');

            if (errores.length) {
                jQuery('#vk-fac-error').html(errores.join('<br>')).show();
                return;
            }

            // Save to state
            this.app.state.facturacion = {
                requiere: true,
                rfc: rfc,
                razonSocial: razon,
                regimen: regimen,
                generaIngresos: ingresos,
                cfdi: cfdi,
                cpFiscal: cpFiscal
            };
        } else {
            this.app.state.facturacion = { requiere: false };
        }

        jQuery('#vk-fac-error').hide();
        var $btn = jQuery('#vk-fac-submit');
        $btn.prop('disabled', true).text('Guardando...');

        var self = this;
        var state = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);

        // Send to backend
        jQuery.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/confirmar-facturacion.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:      state.nombre,
                email:       state.email,
                telefono:    state.telefono,
                modelo:      modelo ? modelo.nombre : '',
                color:       state.colorSeleccionado || '',
                total:       state.totalPagado || 0,
                facturacion: state.facturacion
            }),
            complete: function() {
                $btn.prop('disabled', false);
                self.app.irAPaso('exito');
            }
        });
    }
};
