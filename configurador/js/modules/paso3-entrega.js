/* ==========================================================================
   Voltika - PASO 3: Postal Code / Delivery
   Per Dibujo.pdf spec:
   - CP input + 2 interactive checkboxes (Asesoría placas, Seguro)
   - Basic info visible BEFORE entering CP
   - CP fills city/state/cost dynamically
   - For crédito: shipping cost included
   - For contado/msi: shipping cost calculated by state
   ========================================================================== */

var Paso3 = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
        // Auto-search if CP already in state (revisiting this step)
        var self = this;
        if (app.state.codigoPostal && app.state.codigoPostal.length === 5) {
            setTimeout(function() { self.buscarCP(app.state.codigoPostal); }, 200);
        }
    },

    _calcFechaEntrega: function() {
        var d = new Date();
        d.setDate(d.getDate() + 15);
        var m = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return d.getDate() + ' de ' + m[d.getMonth()] + ' de ' + d.getFullYear();
    },

    render: function() {
        var state = this.app.state;
        var esCredito = state.metodoPago === 'credito';
        var fechaEntrega = this._calcFechaEntrega();
        var html = '';

        // Back button: crédito → calculator (4B), contado/msi → color (2)
        var backTarget = esCredito ? 4 : 2;
        if (esCredito && state.creditoAprobado) backTarget = 'credito-enganche';
        html += VkUI.renderBackButton(backTarget);

        // Header
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 3 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:0;">Entrega en tu ciudad</h2>';
        html += '</div>';

        // Postal code input with search icon
        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div style="font-weight:700;font-size:16px;margin-bottom:10px;">Ingresa tu C\u00f3digo Postal</div>';
        html += '<div class="vk-form-group" style="position:relative;margin-bottom:4px;">';
        html += '<input type="text" id="vk-cp-input" class="vk-form-input" ' +
            'placeholder="C.P. 5 d\u00edgitos" ' +
            'maxlength="5" inputmode="numeric" pattern="[0-9]*" ' +
            'value="' + (state.codigoPostal || '') + '" ' +
            'style="padding-right:40px;font-size:16px;">';
        html += '<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--vk-text-muted);font-size:18px;">&#128269;</span>';
        html += '</div>';
        html += '<div id="vk-cp-error" style="display:none;color:#D32F2F;font-size:13px;margin-bottom:10px;">&#9888; Ingresa tu C\u00f3digo Postal y colonia para continuar.</div>';
        html += '<div id="vk-cp-not-found" style="display:none;color:#D32F2F;font-size:13px;margin-bottom:10px;background:#FFEBEE;border-radius:6px;padding:10px;">C\u00f3digo no encontrado. Verifica e intenta de nuevo.</div>';

        // Estado / Ciudad / Colonia fields (filled dynamically after CP lookup)
        html += '<div id="vk-cp-city" style="display:none;margin-bottom:16px;">';

        // Estado (read-only, auto-filled)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Estado</label>';
        html += '<input type="text" id="vk-cp-estado" class="vk-form-input" readonly ' +
            'style="font-size:15px;padding:12px 14px;background:#f5f5f5;color:#111;">';
        html += '</div>';

        // Ciudad (read-only, auto-filled)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Ciudad</label>';
        html += '<input type="text" id="vk-cp-ciudad" class="vk-form-input" readonly ' +
            'style="font-size:15px;padding:12px 14px;background:#f5f5f5;color:#111;">';
        html += '</div>';

        // Colonia (select dropdown, populated from API)
        html += '<div class="vk-form-group" style="margin-bottom:10px;">';
        html += '<label style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);margin-bottom:4px;display:block;">Colonia</label>';
        html += '<select id="vk-cp-colonia" class="vk-form-input" ' +
            'style="font-size:15px;padding:12px 14px;appearance:auto;">';
        html += '<option value="">Selecciona tu colonia</option>';
        html += '</select>';
        html += '<div id="vk-cp-colonia-loading" style="display:none;font-size:12px;color:var(--vk-text-muted);margin-top:4px;">Cargando colonias...</div>';
        html += '</div>';

        html += '</div>';

        // Delivery points section (hidden until CP lookup)
        html += '<div id="vk-centros-section" style="display:none;margin-top:16px;">';

        // Recommended center (filled dynamically)
        html += '<div id="vk-centro-recomendado"></div>';

        // Other centers expandable
        html += '<div id="vk-otros-centros-wrapper" style="display:none;margin-top:12px;">';
        html += '<div id="vk-otros-centros-toggle" style="font-size:14px;font-weight:700;color:var(--vk-green-primary);cursor:pointer;margin-bottom:10px;">Otros centros cercanos &#9660;</div>';
        html += '<div id="vk-otros-centros-list" style="display:none;"></div>';
        html += '</div>';

        html += '</div>';

        // Centro Voltika Autorizado info section
        html += '<div style="background:var(--vk-bg-light);border-radius:10px;padding:16px;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/voltika_shield.svg" alt="Voltika" style="width:30px;height:30px;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:6px;">Centro Voltika Autorizado</div>';
        html += '<div style="font-size:14px;color:var(--vk-text-secondary);margin-bottom:4px;">&#10003; Revisi\u00f3n y activaci\u00f3n profesional</div>';
        if (esCredito) {
            html += '<div style="font-size:14px;color:var(--vk-green-primary);font-weight:600;">&#10003; Flete incluido en Cr\u00e9dito Voltika</div>';
        } else {
            html += '<div id="vk-cp-logistics" style="display:none;font-size:14px;color:var(--vk-text-secondary);">';
            html += '&#10003; Costo log\u00edstico: <strong id="vk-cp-logistics-price"></strong> MXN';
            html += '</div>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Entrega Garantizada section
        html += '<div style="background:#E0F4FD;border-radius:10px;padding:16px;margin-bottom:14px;border-left:4px solid #039fe1;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:8px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/delivery_icon.jpg" alt="" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:4px;">Entrega Garantizada</div>';
        html += '<div style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">Entrega garantizada a m\u00e1s tardar el <strong style="color:#039fe1;">' + fechaEntrega + '</strong></div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Tu Asesor Voltika section
        html += '<div style="background:var(--vk-bg-light);border-radius:10px;padding:16px;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/asesor_icon.jpg" alt="" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:4px;">Tu Asesor Voltika</div>';
        html += '<div style="font-size:14px;color:var(--vk-text-secondary);">Estar\u00e1 contigo desde la entrega. M\u00e1x. <strong>48 horas</strong> para confirmarte el punto autorizado.</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        html += '<p style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin:4px 0 14px;text-align:center;">Recibir\u00e1s confirmaci\u00f3n por <strong>WhatsApp</strong> y <strong>correo electr\u00f3nico</strong>.</p>';

        html += '</div>'; // end card

        // ── 2 Interactive Checkboxes (always visible) ──
        html += '<div style="margin-top:16px;">';

        // Checkbox 1: Asesoría placas
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:16px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:10px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-placas" class="vk-checkbox" style="margin-top:3px;"' +
            (state.asesoriaPlacos ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:15px;">Quiero asesor\u00eda para placas en mi estado</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Te conectamos con gestores verificados. Pago directo al gestor.</div>';
        html += '</div>';
        html += '</label>';

        // Checkbox 2: Seguro
        html += '<label class="vk-checkbox-card" style="display:flex;align-items:flex-start;gap:12px;padding:16px;border:1.5px solid var(--vk-border);border-radius:10px;margin-bottom:16px;cursor:pointer;">';
        html += '<input type="checkbox" id="vk-check-seguro" class="vk-checkbox" style="margin-top:3px;"' +
            (state.seguro ? ' checked' : '') + '>';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:15px;">Quiero cotizar y activar el seguro con <img src="' + (window.VK_BASE_PATH || '') + 'img/qualitas-logo.png" alt="Qu\u00e1litas" style="height:24px;vertical-align:middle;margin:0 4px;"> desde la entrega</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">Cotizamos y enviamos tu p\u00f3liza. Pago directo a la aseguradora.</div>';
        html += '</div>';
        html += '</label>';

        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-paso3-confirmar" style="font-size:16px;font-weight:800;letter-spacing:0.5px;">' +
            'CONFIRMAR ENTREGA OFICIAL' +
            '</button>';

        $('#vk-entrega-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        $(document).off('input', '#vk-cp-input').off('click', '#vk-paso3-confirmar')
            .off('change', '#vk-check-placas').off('change', '#vk-check-seguro')
            .off('change', '#vk-cp-colonia')
            .off('click', '.vk-select-centro').off('click', '#vk-otros-centros-toggle')
            .off('click', '#vk-select-centro-cercano');

        // Postal code input
        $(document).on('input', '#vk-cp-input', function() {
            var val = $(this).val().replace(/\D/g, '');
            $(this).val(val);
            $('#vk-cp-error').hide();
            $('#vk-cp-not-found').hide();

            if (val.length === 5) {
                self.buscarCP(val);
            } else {
                $('#vk-cp-city').hide();
                $('#vk-cp-logistics').hide();
                $('#vk-paso3-confirmar').prop('disabled', true);
                self.app.state.ciudad = null;
            }
        });

        // Colonia select — enable confirm when selected
        $(document).on('change', '#vk-cp-colonia', function() {
            var val = $(this).val();
            self.app.state.colonia = val;
            $('#vk-paso3-confirmar').prop('disabled', !val);
        });

        // Checkboxes
        $(document).on('change', '#vk-check-placas', function() {
            self.app.state.asesoriaPlacos = this.checked;
            if (this.checked) $('#vk-checkbox-error').hide();
        });
        $(document).on('change', '#vk-check-seguro', function() {
            self.app.state.seguro = this.checked;
            if (this.checked) $('#vk-checkbox-error').hide();
        });

        // Select delivery center
        $(document).on('click', '.vk-select-centro', function(e) {
            e.preventDefault();
            var centroId = $(this).data('centro-id');
            self._selectCentro(centroId);
        });

        // Toggle other centers list
        $(document).on('click', '#vk-otros-centros-toggle', function() {
            var $list = $('#vk-otros-centros-list');
            if ($list.is(':visible')) {
                $list.slideUp(200);
                $(this).html('Otros centros cercanos &#9660;');
            } else {
                $list.slideDown(200);
                $(this).html('Otros centros cercanos &#9650;');
            }
        });

        // Select "Centro Voltika cercano" (virtual/nearest)
        $(document).on('click', '#vk-select-centro-cercano', function(e) {
            e.preventDefault();
            self.app.state.centroEntrega = {
                id: 'centro-cercano',
                nombre: 'Centro Voltika cercano',
                direccion: 'Por confirmar por tu asesor Voltika',
                ciudad: self.app.state.ciudad || '',
                estado: self.app.state.estado || '',
                tipo: 'cercano'
            };
            $('#vk-centro-error').hide();
            // Reset all radio circles, then fill cercano
            $('.vk-radio-circle').css({ 'background': 'transparent', 'border-color': '#ccc' }).html('');
            $('.vk-radio-circle[data-radio-id="centro-cercano"]')
                .css({ 'background': 'var(--vk-green-primary)', 'border-color': 'var(--vk-green-primary)' })
                .html('<span style="color:#fff;font-size:12px;line-height:1;">&#10003;</span>');
            // Visual feedback
            $('.vk-select-centro').css({ 'opacity': '0.6' }).each(function() {
                if (this.id !== 'vk-select-centro-cercano') $(this).text('SELECCIONAR ESTE CENTRO');
            });
            $(this).css({ 'opacity': '1', 'background': '#027ab8' }).text('\u2713 Centro cercano seleccionado');
        });

        // Confirm
        $(document).on('click', '#vk-paso3-confirmar', function() {
            var cp = $('#vk-cp-input').val();
            var colonia = $('#vk-cp-colonia').val();
            if (!VkValidacion.codigoPostal(cp) || !self.app.state.ciudad || !colonia) {
                $('#vk-cp-error').show();
                $('#vk-cp-input').focus();
                $('#vk-cp-input').css('border-color', '#D32F2F');
                setTimeout(function() { $('#vk-cp-input').css('border-color', ''); }, 3000);
                return;
            }
            // Check if a delivery center was selected
            if (!self.app.state.centroEntrega) {
                var $section = $('#vk-centros-section');
                if ($section.is(':visible')) {
                    $('html, body').animate({ scrollTop: $section.offset().top - 80 }, 400);
                    // Show centro error alert
                    if (!$('#vk-centro-error').length) {
                        $section.prepend('<div id="vk-centro-error" style="color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;">Selecciona un punto de entrega para continuar.</div>');
                    } else {
                        $('#vk-centro-error').show();
                    }
                }
                return;
            }
            // Check if at least one checkbox is selected
            var placas = $('#vk-check-placas').is(':checked');
            var seguro = $('#vk-check-seguro').is(':checked');
            if (!placas && !seguro) {
                var $checkSection = $('#vk-check-placas').closest('label').parent();
                $('html, body').animate({ scrollTop: $checkSection.offset().top - 80 }, 400);
                if (!$('#vk-checkbox-error').length) {
                    $checkSection.prepend('<div id="vk-checkbox-error" style="color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;">Selecciona al menos una opci\u00f3n para continuar.</div>');
                } else {
                    $('#vk-checkbox-error').show();
                }
                return;
            }
            $('#vk-cp-error').hide();
            $('#vk-centro-error').hide();
            $('#vk-checkbox-error').hide();
            self.app.state.codigoPostal = cp;
            self.app.state.colonia = colonia;
            self.app.irAPaso('resumen');
        });
    },

    buscarCP: function(cp) {
        var self = this;
        var resultado = VOLTIKA_CP._buscar(cp);

        if (!resultado) {
            $('#vk-cp-logistics').hide();
            $('#vk-paso3-confirmar').prop('disabled', true);
            self.app.state.ciudad = null;
            $('#vk-cp-city').hide();
            $('#vk-cp-not-found').show();
            return;
        }

        var state = self.app.state;
        var esCredito = state.metodoPago === 'credito';
        var config = VOLTIKA_PRODUCTOS.config;

        state.ciudad = resultado.ciudad;
        state.estado = resultado.estado;
        state.costoLogistico = esCredito ? 0 : config.costoLogistico;

        // Fill Estado, Ciudad
        $('#vk-cp-estado').val(resultado.estado);
        $('#vk-cp-ciudad').val(resultado.ciudad);
        $('#vk-cp-city').slideDown(200);

        // Show logistics cost (contado/msi only)
        if (!esCredito) {
            $('#vk-cp-logistics-price').text(VkUI.formatPrecio(config.costoLogistico));
            $('#vk-cp-logistics').show();
        }

        // Search and render delivery centers
        self._renderCentros(cp);

        // Fetch colonias from API
        var $select = $('#vk-cp-colonia');
        $select.html('<option value="">Cargando...</option>').prop('disabled', true);
        $('#vk-cp-colonia-loading').show();
        $('#vk-paso3-confirmar').prop('disabled', true);

        $.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/buscar-colonias.php',
            data: { cp: cp },
            dataType: 'json',
            timeout: 10000,
            success: function(data) {
                $('#vk-cp-colonia-loading').hide();
                $select.prop('disabled', false);

                if (data.ok && data.colonias && data.colonias.length > 0) {
                    // Update estado/ciudad from API if available
                    if (data.estado) {
                        $('#vk-cp-estado').val(data.estado);
                        state.estado = data.estado;
                    }
                    if (data.ciudad) {
                        $('#vk-cp-ciudad').val(data.ciudad);
                        state.ciudad = data.ciudad;
                    }

                    // Populate colonia select
                    var opts = '<option value="">Selecciona tu colonia</option>';
                    for (var i = 0; i < data.colonias.length; i++) {
                        var sel = (state.colonia === data.colonias[i]) ? ' selected' : '';
                        opts += '<option value="' + data.colonias[i] + '"' + sel + '>' + data.colonias[i] + '</option>';
                    }
                    $select.html(opts);

                    // If previously selected colonia matches, enable button
                    if (state.colonia && $select.val()) {
                        $('#vk-paso3-confirmar').prop('disabled', false);
                    }
                } else {
                    // API returned no colonias — show manual input fallback
                    self._coloniaFallback(state);
                }
            },
            error: function() {
                $('#vk-cp-colonia-loading').hide();
                // API failed — show manual input fallback
                self._coloniaFallback(state);
            }
        });
    },

    _greenCheck: function(size) {
        size = size || 16;
        return '<span style="display:inline-flex;align-items:center;justify-content:center;width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:var(--vk-green-primary);flex-shrink:0;">' +
            '<span style="color:#fff;font-size:' + Math.round(size * 0.6) + 'px;line-height:1;">&#10003;</span></span>';
    },

    _tagIcon: function(tag) {
        // SVG icons for universal rendering
        var sz = 14;
        var icons = {
            'Exhibici\u00f3n': '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
            'Entrega': '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
            'Servicio t\u00e9cnico': '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            'Activaci\u00f3n': '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>'
        };
        return icons[tag] || '&#10003;';
    },

    _renderCentroCard: function(centro, esCredito) {
        var esCompleto = (centro.tipo === 'completo');
        var self = this;
        var h = '';
        var mapsUrl = 'https://maps.google.com/?q=' + encodeURIComponent(centro.nombre + ' ' + centro.direccion + ' ' + centro.ciudad);

        // Card container — completo uses navy border
        h += '<div class="vk-card" style="padding:0;border-radius:14px;overflow:hidden;margin-bottom:14px;border:1.5px solid ' + (esCompleto ? '#1a3a5c' : '#ddd') + ';">';

        // Card body
        h += '<div style="padding:16px;">';

        // Header: radio circle + title + badge
        h += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">';
        // Radio circle — completo type starts checked (navy filled)
        if (esCompleto) {
            h += '<div class="vk-radio-circle" data-radio-id="' + centro.id + '" style="width:20px;height:20px;border-radius:50%;background:#1a3a5c;border:2px solid #1a3a5c;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;">';
            h += '<span style="color:#fff;font-size:12px;line-height:1;">&#10003;</span>';
            h += '</div>';
        } else {
            h += '<div class="vk-radio-circle" data-radio-id="' + centro.id + '" style="width:20px;height:20px;border-radius:50%;border:2px solid #ccc;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;">';
            h += '</div>';
        }
        // Title + subtitle
        h += '<div style="flex:1;min-width:0;">';
        h += '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
        h += '<div style="font-weight:800;font-size:15px;color:var(--vk-text-primary);">' + centro.nombre + '</div>';
        if (!esCompleto) {
            h += '<span style="font-size:11px;font-weight:600;padding:4px 10px;border-radius:12px;background:#F5E6C8;color:#8B6914;white-space:nowrap;">M\u00e1s cercano a ti</span>';
        }
        h += '</div>';
        h += '<div style="font-size:12px;color:var(--vk-green-primary);font-weight:600;display:flex;align-items:center;gap:4px;">';
        h += '&#128737; Centro Voltika ' + (esCompleto ? 'Autorizado' : 'de entrega y activaci\u00f3n');
        h += '</div>';
        h += '</div>';
        h += '</div>';

        // Location + address
        if (centro.ubicacion) {
            h += '<div style="font-size:14px;color:var(--vk-text-primary);margin-bottom:2px;"><strong>&#128205; ' + centro.ubicacion + '</strong></div>';
        }
        h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;padding-left:22px;">' + centro.direccion + '</div>';

        // Tags with specific icons
        h += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">';
        if (centro.tags && centro.tags.length) {
            for (var t = 0; t < centro.tags.length; t++) {
                var icon = self._tagIcon(centro.tags[t]);
                h += '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:6px 14px;border-radius:20px;background:var(--vk-green-light);color:var(--vk-green-primary);">';
                h += icon + ' ' + centro.tags[t];
                h += '</span>';
            }
        }
        if (esCredito) {
            h += '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:6px 12px;border-radius:8px;background:var(--vk-green-light);color:var(--vk-green-primary);">';
            h += '&#10003; Flete incluido</span>';
        }
        h += '</div>';

        // Bottom section: description + ver ubicación button
        h += '<div style="background:#F7F7F7;border-radius:10px;padding:14px;margin-bottom:4px;">';
        if (esCompleto) {
            h += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;display:flex;align-items:center;gap:6px;">' + self._greenCheck() + ' Centro Voltika verificado</div>';
        } else {
            h += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;display:flex;align-items:center;gap:6px;">' + self._greenCheck() + ' Punto autorizado para entrega Voltika</div>';
        }
        if (centro.descripcion) {
            h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;">' + centro.descripcion + '</div>';
        }
        h += '<a href="' + mapsUrl + '" target="_blank" rel="noopener" ' +
            'style="display:inline-block;font-size:13px;font-weight:700;color:var(--vk-text-primary);text-decoration:none;padding:8px 16px;border:1.5px solid #ccc;border-radius:8px;background:#fff;">' +
            '&#10095; Ver ubicaci\u00f3n</a>';
        h += '</div>';

        // Select button
        h += '<button class="vk-btn vk-btn--primary vk-select-centro" data-centro-id="' + centro.id + '" ' +
            'style="font-size:14px;font-weight:700;padding:12px;width:100%;margin-top:12px;">SELECCIONAR ESTE CENTRO</button>';

        h += '</div>'; // end body
        h += '</div>'; // end card

        return h;
    },

    _renderCentroCercanoCard: function() {
        var self = this;
        var h = '';

        h += '<div class="vk-card" style="padding:0;border-radius:14px;overflow:hidden;margin-bottom:14px;border:1.5px solid #ddd;">';

        // Card body
        h += '<div style="padding:16px;">';

        // Radio circle + title
        h += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">';
        h += '<div class="vk-radio-circle" data-radio-id="centro-cercano" style="width:20px;height:20px;border-radius:50%;border:2px solid #ccc;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;">';
        h += '</div>';
        h += '<div style="min-width:0;">';
        h += '<div style="font-weight:800;font-size:17px;color:var(--vk-text-primary);">Centro Voltika cercano</div>';
        h += '<div style="font-size:12px;color:#1a3a5c;font-weight:600;">M\u00e1s de 200 puntos aliados Voltika en expansi\u00f3n</div>';
        h += '</div>';
        h += '</div>';

        // Description
        h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:14px;line-height:1.5;">';
        h += 'Si ninguno de los centros mostrados queda cerca, <strong>un asesor Voltika</strong> confirmar\u00e1 el punto de entrega m\u00e1s conveniente en tu ciudad.';
        h += '</div>';

        // Green checks
        h += '<div style="margin-bottom:14px;">';
        var checkStyle = 'font-size:13px;color:var(--vk-green-primary);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px;';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Entrega y activaci\u00f3n</div>';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Servicio t\u00e9cnico autorizado</div>';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Soporte Voltika</div>';
        h += '</div>';

        // Cobertura badge
        h += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">';
        h += '<span style="font-size:12px;font-weight:600;color:#1a3a5c;">&#127758; Cobertura nacional</span>';
        h += '</div>';

        // CTA button — full width
        h += '<button class="vk-btn vk-select-centro" id="vk-select-centro-cercano" ' +
            'style="width:100%;font-size:12px;font-weight:700;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;cursor:pointer;margin-bottom:12px;">' +
            'Confirmar centro cercano &#10095;</button>';

        // Confirmation note — single line, light blue background
        h += '<div style="font-size:12px;color:#1a3a5c;background:#E8F4FD;border-radius:8px;padding:10px 14px;text-align:center;">';
        h += '&#128337; Confirmaci\u00f3n en m\u00e1ximo <strong>48 horas</strong> por WhatsApp o correo';
        h += '</div>';

        h += '</div>'; // end body
        h += '</div>'; // end card

        return h;
    },

    _renderCentros: function(cp) {
        var self = this;
        var esCredito = self.app.state.metodoPago === 'credito';

        if (typeof VOLTIKA_CENTROS === 'undefined' || !VOLTIKA_CENTROS.buscar) {
            $('#vk-centros-section').hide();
            return;
        }

        var centros = VOLTIKA_CENTROS.buscar(cp);
        if (!centros || centros.length === 0) {
            $('#vk-centros-section').hide();
            return;
        }

        // Sort: completo centers first
        centros.sort(function(a, b) {
            if (a.tipo === 'completo' && b.tipo !== 'completo') return -1;
            if (a.tipo !== 'completo' && b.tipo === 'completo') return 1;
            return 0;
        });

        // Render recommended (first)
        var recHtml = '';
        recHtml += '<div style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin-bottom:8px;">Centro de entrega recomendado</div>';
        recHtml += self._renderCentroCard(centros[0], esCredito);
        $('#vk-centro-recomendado').html(recHtml);

        // Other centers + Centro Voltika cercano
        var otrosHtml = '';
        for (var i = 1; i < centros.length; i++) {
            otrosHtml += self._renderCentroCard(centros[i], esCredito);
        }
        // Always add the "Centro Voltika cercano" option
        otrosHtml += self._renderCentroCercanoCard();

        $('#vk-otros-centros-list').html(otrosHtml);
        $('#vk-otros-centros-wrapper').show();

        $('#vk-centros-section').slideDown(200);
    },

    _selectCentro: function(centroId) {
        var centro = null;
        if (typeof VOLTIKA_CENTROS !== 'undefined') {
            for (var i = 0; i < VOLTIKA_CENTROS.length; i++) {
                if (VOLTIKA_CENTROS[i].id === centroId) {
                    centro = VOLTIKA_CENTROS[i];
                    break;
                }
            }
        }
        if (centro) {
            this.app.state.centroEntrega = centro;
            $('#vk-centro-error').hide();
            // Reset all radio circles to empty
            $('.vk-radio-circle').css({ 'background': 'transparent', 'border-color': '#ccc' }).html('');
            // Fill selected radio circle
            $('.vk-radio-circle[data-radio-id="' + centroId + '"]')
                .css({ 'background': 'var(--vk-green-primary)', 'border-color': 'var(--vk-green-primary)' })
                .html('<span style="color:#fff;font-size:12px;line-height:1;">&#10003;</span>');
            // Reset buttons
            $('.vk-select-centro').css({ 'opacity': '0.6' }).each(function() {
                if ($(this).data('centro-id')) $(this).text('SELECCIONAR ESTE CENTRO');
            });
            $('#vk-select-centro-cercano').css({ 'opacity': '0.6', 'background': '#039fe1' }).text('Confirmar centro cercano \u203a');
            $('.vk-select-centro[data-centro-id="' + centroId + '"]')
                .css({ 'opacity': '1', 'background': 'var(--vk-green-dark)' })
                .text('\u2713 Seleccionado');
        }
    },

    _coloniaFallback: function(state) {
        // Replace select with text input as fallback
        var $container = $('#vk-cp-colonia').parent();
        $('#vk-cp-colonia').remove();
        $container.find('#vk-cp-colonia-loading').hide();
        $container.append(
            '<input type="text" id="vk-cp-colonia" class="vk-form-input" ' +
            'placeholder="Escribe tu colonia" ' +
            'value="' + (state.colonia || '') + '" ' +
            'style="font-size:15px;padding:12px 14px;">'
        );
        $('#vk-paso3-confirmar').prop('disabled', false);
    }
};
