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
        html += VkUI.renderCreditoStepBar(2);

        html += '<h2 class="vk-paso__titulo">C\u00f3digo postal de tu domicilio</h2>';

        // Show previously selected delivery point with full detail
        if (state.codigoPostal || state.centroEntrega) {
            var centro = state.centroEntrega;
            html += '<div style="background:#F0F7FF;border:1.5px solid #B3D4FC;border-radius:12px;padding:14px;margin-bottom:16px;">';
            html += '<div style="font-size:13px;font-weight:700;color:#1a3a5c;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><img src="' + (window.VK_BASE_PATH || '') + 'img/entrega.png" alt="" style="width:20px;height:20px;object-fit:contain;"> Punto de entrega seleccionado</div>';

            if (centro && centro.nombre) {
                // Show centro name
                html += '<div style="font-size:16px;font-weight:800;color:#333;margin-bottom:4px;">' + centro.nombre + '</div>';
                // Show address if available
                if (centro.direccion) {
                    html += '<div style="font-size:13px;color:#555;margin-bottom:2px;">' + centro.direccion + '</div>';
                }
                if (centro.colonia) {
                    html += '<div style="font-size:13px;color:#555;margin-bottom:2px;">' + centro.colonia + '</div>';
                }
                // CP + City
                var ubicacion = '';
                if (state.codigoPostal) ubicacion += state.codigoPostal + ' \u2014 ';
                ubicacion += (centro.ciudad || state.ciudad || '') + ', ' + (centro.estado || state.estado || '');
                html += '<div style="font-size:14px;font-weight:700;color:#333;">' + ubicacion + '</div>';
            } else {
                // Fallback: just CP + city
                html += '<div style="font-size:16px;font-weight:800;color:#333;">' + state.codigoPostal;
                var cpInfo = VOLTIKA_CP._buscar(state.codigoPostal);
                if (cpInfo) html += ' \u2014 ' + cpInfo.ciudad + ', ' + cpInfo.estado;
                html += '</div>';
            }

            html += '<div style="font-size:11px;font-weight:800;color:#039fe1;text-transform:uppercase;margin-top:6px;">(este es para recibir tu Voltika)</div>';
            html += '</div>';
        }

        html += '<p class="vk-paso__subtitulo">Ahora, confirmar el c\u00f3digo postal de tu domicilio (Como aparece en tu INE o comprobante), este puede ser diferente al de la entrega.</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">C\u00f3digo postal</label>';
        html += '<input type="text" class="vk-form-input" id="vk-ccp-cp" ' +
            'placeholder="Ej: 44100" maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.cpDomicilio || '') + '">';
        html += '<div class="vk-hint" style="text-align:left;margin-top:4px;">Solo toma unos segundos</div>';
        html += '</div>';

        html += '<div id="vk-ccp-estado" style="display:none;padding:10px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
        html += '<strong id="vk-ccp-estado-text"></strong>';
        html += '</div>';

        // Colonia dropdown (populated after CP lookup)
        html += '<div id="vk-ccp-colonia-wrap" style="display:none;" class="vk-form-group">';
        html += '<label class="vk-form-label">Colonia</label>';
        html += '<select id="vk-ccp-colonia" class="vk-form-input" style="font-size:15px;padding:12px 14px;appearance:auto;">';
        html += '<option value="">Selecciona tu colonia</option>';
        html += '</select>';
        html += '</div>';

        html += '<div class="vk-trust">';
        html += '<div><span class="vk-check vk-check--sm"></span> Validaci\u00f3n segura</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> Aprobaci\u00f3n en menos de 2 minutos</div>';
        html += '</div>';

        html += '<div id="vk-ccp-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-ccp-continuar" disabled>CONTINUAR \u2192</button>';

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
                    self.app.state.estadoDomicilio = resultado.estado;
                    self.app.state.ciudadDomicilio = resultado.ciudad;

                    // Load colonias
                    jQuery.ajax({
                        url: (window.VK_BASE_PATH || '') + 'php/buscar-colonias.php',
                        data: { cp: val },
                        dataType: 'json',
                        timeout: 10000,
                        success: function(data) {
                            if (data && data.ok && data.colonias && data.colonias.length) {
                                var savedColonia = self.app.state.colonia || '';
                                var opts = '<option value="">Selecciona tu colonia</option>';
                                for (var i = 0; i < data.colonias.length; i++) {
                                    var sel = (data.colonias[i] === savedColonia) ? ' selected' : '';
                                    opts += '<option value="' + data.colonias[i] + '"' + sel + '>' + data.colonias[i] + '</option>';
                                }
                                jQuery('#vk-ccp-colonia').html(opts);
                                jQuery('#vk-ccp-colonia-wrap').slideDown(200);
                                // Enable continue if colonia already selected
                                if (savedColonia) {
                                    jQuery('#vk-ccp-continuar').prop('disabled', false);
                                }
                            } else {
                                PasoCreditoCPDom._coloniaFallback();
                            }
                        },
                        error: function() {
                            PasoCreditoCPDom._coloniaFallback();
                        }
                    });
                } else {
                    jQuery('#vk-ccp-estado').hide();
                    jQuery('#vk-ccp-colonia-wrap').hide();
                    jQuery('#vk-ccp-continuar').prop('disabled', true);
                }
            } else {
                jQuery('#vk-ccp-estado').hide();
                jQuery('#vk-ccp-colonia-wrap').hide();
                jQuery('#vk-ccp-continuar').prop('disabled', true);
            }
        });

        // Colonia selection enables continue
        jQuery(document).off('change', '#vk-ccp-colonia');
        jQuery(document).on('change', '#vk-ccp-colonia', function() {
            var colonia = jQuery(this).val();
            jQuery('#vk-ccp-continuar').prop('disabled', !colonia);
        });

        // Colonia fallback (text input) enables continue on typing
        jQuery(document).off('input', '#vk-ccp-colonia');
        jQuery(document).on('input', '#vk-ccp-colonia', function() {
            var colonia = jQuery(this).val().trim();
            jQuery('#vk-ccp-continuar').prop('disabled', !colonia);
        });

        jQuery(document).on('click', '#vk-ccp-continuar', function() {
            var cp = jQuery('#vk-ccp-cp').val().trim();
            var colonia = jQuery('#vk-ccp-colonia').val().trim();
            if (!cp || cp.length !== 5) {
                jQuery('#vk-ccp-error').text('Ingresa un C\u00f3digo Postal v\u00e1lido.').show();
                return;
            }
            if (!colonia) {
                jQuery('#vk-ccp-error').text('Selecciona tu colonia.').show();
                return;
            }
            jQuery('#vk-ccp-error').hide();

            self.app.state.cpDomicilio = cp;
            self.app.state.colonia = colonia;
            self.app.irAPaso('credito-domicilio');
        });
    },

    _coloniaFallback: function() {
        var saved = this.app ? (this.app.state.colonia || '') : '';
        jQuery('#vk-ccp-colonia').replaceWith(
            '<input type="text" class="vk-form-input" id="vk-ccp-colonia" ' +
            'placeholder="Ej: Centro" value="' + saved + '">');
        jQuery('#vk-ccp-colonia-wrap').slideDown(200);
        if (saved) jQuery('#vk-ccp-continuar').prop('disabled', false);
    }
};
