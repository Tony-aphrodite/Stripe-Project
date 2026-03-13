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
        if (esCredito && state.creditoAprobado) backTarget = 'credito-resultado';
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
        html += '<div style="font-weight:800;font-size:15px;">Quiero cotizar y activar el seguro con <span style="color:#6B2D8B;font-weight:900;font-style:italic;">Qu\u00e1litas</span> desde la entrega</div>';
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
            .off('click', '.vk-select-centro').off('click', '#vk-otros-centros-toggle');

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
        });
        $(document).on('change', '#vk-check-seguro', function() {
            self.app.state.seguro = this.checked;
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

        // Confirm
        $(document).on('click', '#vk-paso3-confirmar', function() {
            var cp = $('#vk-cp-input').val();
            var colonia = $('#vk-cp-colonia').val();
            if (VkValidacion.codigoPostal(cp) && self.app.state.ciudad && colonia) {
                $('#vk-cp-error').hide();
                self.app.state.codigoPostal = cp;
                self.app.state.colonia = colonia;
                self.app.irAPaso('resumen');
            } else {
                $('#vk-cp-error').show();
                $('#vk-cp-input').focus();
                $('#vk-cp-input').css('border-color', '#D32F2F');
                setTimeout(function() { $('#vk-cp-input').css('border-color', ''); }, 3000);
            }
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

    _renderCentros: function(cp) {
        var self = this;
        var esCredito = self.app.state.metodoPago === 'credito';

        // Check if VOLTIKA_CENTROS is available
        if (typeof VOLTIKA_CENTROS === 'undefined' || !VOLTIKA_CENTROS.buscar) {
            $('#vk-centros-section').hide();
            return;
        }

        var centros = VOLTIKA_CENTROS.buscar(cp);
        if (!centros || centros.length === 0) {
            $('#vk-centros-section').hide();
            return;
        }

        // Render recommended center (first result)
        var rec = centros[0];
        var recHtml = '';
        recHtml += '<div style="font-size:14px;font-weight:700;color:var(--vk-text-primary);margin-bottom:8px;">Centro de entrega recomendado</div>';
        recHtml += '<div class="vk-card" style="padding:16px;border:2px solid var(--vk-green-primary);border-radius:12px;">';
        recHtml += '<div style="font-weight:800;font-size:16px;margin-bottom:6px;">' + rec.nombre + '</div>';
        recHtml += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">' + rec.direccion + '</div>';
        recHtml += '<div style="font-size:12px;color:var(--vk-text-muted);margin-bottom:10px;">' + rec.horarios + '</div>';

        // Badges
        recHtml += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">';
        recHtml += '<span style="display:inline-block;font-size:11px;font-weight:600;color:var(--vk-green-primary);background:var(--vk-green-light);padding:4px 10px;border-radius:20px;">&#10003; Centro Voltika autorizado</span>';
        recHtml += '<span style="display:inline-block;font-size:11px;font-weight:600;color:var(--vk-green-primary);background:var(--vk-green-light);padding:4px 10px;border-radius:20px;">&#10003; Revisión y activación profesional</span>';
        if (esCredito) {
            recHtml += '<span style="display:inline-block;font-size:11px;font-weight:600;color:var(--vk-green-primary);background:var(--vk-green-light);padding:4px 10px;border-radius:20px;">&#10003; Flete incluido en Crédito Voltika</span>';
        }
        recHtml += '</div>';

        recHtml += '<button class="vk-btn vk-btn--primary vk-select-centro" data-centro-id="' + rec.id + '" style="font-size:14px;font-weight:700;padding:12px;">CONTINUAR CON ESTE CENTRO</button>';
        recHtml += '</div>';

        $('#vk-centro-recomendado').html(recHtml);

        // Other centers
        if (centros.length > 1) {
            var otrosHtml = '';
            for (var i = 1; i < centros.length; i++) {
                var c = centros[i];
                otrosHtml += '<div class="vk-card" style="padding:14px;border-radius:10px;margin-bottom:8px;">';
                otrosHtml += '<div style="display:flex;align-items:center;justify-content:space-between;">';
                otrosHtml += '<div style="flex:1;min-width:0;">';
                otrosHtml += '<div style="font-weight:700;font-size:14px;margin-bottom:4px;">' + c.nombre + '</div>';
                otrosHtml += '<div style="font-size:12px;color:var(--vk-text-secondary);">' + c.direccion + '</div>';
                otrosHtml += '<div style="font-size:11px;color:var(--vk-text-muted);margin-top:2px;">' + c.horarios + '</div>';
                otrosHtml += '</div>';
                otrosHtml += '<button class="vk-select-centro" data-centro-id="' + c.id + '" style="flex-shrink:0;margin-left:12px;padding:8px 16px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Seleccionar</button>';
                otrosHtml += '</div>';
                otrosHtml += '</div>';
            }
            $('#vk-otros-centros-list').html(otrosHtml);
            $('#vk-otros-centros-wrapper').show();
        } else {
            $('#vk-otros-centros-wrapper').hide();
        }

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
            // Visual feedback — highlight selected
            $('.vk-select-centro').css({ 'opacity': '0.6' });
            $('.vk-select-centro[data-centro-id="' + centroId + '"]')
                .css({ 'opacity': '1', 'background': 'var(--vk-green-dark)' })
                .text('✓ Seleccionado');
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
