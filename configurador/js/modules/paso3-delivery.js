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

        // Entrega Garantizada section (top priority)
        html += '<div style="background:#E0F4FD;border-radius:10px;padding:16px;margin-bottom:14px;border-left:4px solid #039fe1;">';
        html += '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:8px;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/entrega.png" alt="" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-weight:800;font-size:17px;margin-bottom:4px;">Entrega Garantizada</div>';
        html += '<div style="font-size:15px;font-weight:700;color:var(--vk-text-primary);">Entrega garantizada a m\u00e1s tardar el <strong style="color:#039fe1;">' + fechaEntrega + '</strong></div>';
        html += '</div>';
        html += '</div>';
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
        html += '<div id="vk-colonia-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-top:6px;text-align:center;font-weight:600;">Selecciona tu colonia para continuar.</div>';
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
            html += '<div id="vk-cp-logistics" style="display:none;font-size:16px;color:var(--vk-text-primary);font-weight:700;">';
            html += '&#10003; Costo log\u00edstico: <strong style="color:#039fe1;font-size:18px;" id="vk-cp-logistics-price"></strong> <span style="color:#039fe1;font-size:18px;font-weight:700;">MXN</span>';
            html += '</div>';
        }
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
            .off('click', '.vk-centro-card');

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
                // Button always enabled — validation on click
            // $('#vk-paso3-confirmar').prop('disabled', true);
                self.app.state.ciudad = null;
            }
        });

        // Colonia select — enable confirm when selected
        $(document).on('change', '#vk-cp-colonia', function() {
            var val = $(this).val();
            self.app.state.colonia = val;
            // Don't disable button — let validation show error messages
            // $('#vk-paso3-confirmar').prop('disabled', !val);
            if (val) $('#vk-colonia-error').hide();
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


        // Click on centro card to select it (glow effect)
        $(document).on('click', '.vk-centro-card', function(e) {
            // Don't trigger if clicking a link or button inside
            if ($(e.target).is('a, button') || $(e.target).closest('a, button').length) return;
            var centroId = $(this).data('centro-id');
            if (centroId) {
                self._selectCentro(centroId);
            }
        });

        // Confirm
        $(document).on('click', '#vk-paso3-confirmar', function() {
            var cp = $('#vk-cp-input').val();
            var colonia = $('#vk-cp-colonia').val();
            if (!VkValidacion.codigoPostal(cp) || !self.app.state.ciudad) {
                $('#vk-cp-error').show();
                $('#vk-cp-input').focus();
                $('#vk-cp-input').css('border-color', '#D32F2F');
                setTimeout(function() { $('#vk-cp-input').css('border-color', ''); }, 3000);
                return;
            }
            if (!colonia) {
                $('#vk-colonia-error').show();
                $('#vk-cp-colonia').css('border-color', '#D32F2F');
                var $coloniaEl = $('#vk-cp-colonia');
                if ($coloniaEl.length && $coloniaEl.is(':visible') && $coloniaEl.offset()) {
                    $('html, body').animate({ scrollTop: $coloniaEl.offset().top - 100 }, 400);
                } else {
                    // Scroll to error message instead
                    var $errEl = $('#vk-colonia-error');
                    if ($errEl.length && $errEl.offset()) {
                        $('html, body').animate({ scrollTop: $errEl.offset().top - 100 }, 400);
                    }
                }
                setTimeout(function() { $('#vk-cp-colonia').css('border-color', ''); }, 3000);
                return;
            }
            $('#vk-colonia-error').hide();
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
            // Checkboxes are optional — no validation needed
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
            // Button always enabled — validation on click
            // $('#vk-paso3-confirmar').prop('disabled', true);
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

        // Calculate shipping cost by estado + modelo
        var envioData = config.envio || {};
        var modelo = self.app.getModelo(state.modeloSeleccionado);
        var esMC10 = modelo && (modelo.id === 'mc10');
        var envioArr = envioData[resultado.estado];
        var costoEnvio = envioArr ? (esMC10 ? envioArr[1] : envioArr[0]) : config.costoLogistico;
        state.costoLogistico = esCredito ? 0 : costoEnvio;

        // Fill Estado, Ciudad
        $('#vk-cp-estado').val(resultado.estado);
        $('#vk-cp-ciudad').val(resultado.ciudad);
        $('#vk-cp-city').slideDown(200);

        // Show logistics cost (contado/msi only)
        if (!esCredito) {
            $('#vk-cp-logistics-price').text(VkUI.formatPrecio(costoEnvio));
            $('#vk-cp-logistics').show();
        }

        // Don't render centers yet — wait for colonia API to confirm city name

        // Fetch colonias from API
        var $select = $('#vk-cp-colonia');
        $select.html('<option value="">Cargando...</option>').prop('disabled', true);
        $('#vk-cp-colonia-loading').show();
        // Button always enabled — validation on click
            // $('#vk-paso3-confirmar').prop('disabled', true);

        $.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/buscar-colonias.php',
            data: { cp: cp },
            dataType: 'json',
            timeout: 10000,
            success: function(data) {
                $('#vk-cp-colonia-loading').hide();
                $select.prop('disabled', false);

                if (data.ok && data.colonias && data.colonias.length > 0) {
                    // Update estado/ciudad from API (more accurate than local DB)
                    if (data.estado) {
                        $('#vk-cp-estado').val(data.estado);
                        state.estado = data.estado;
                        // Recalculate shipping with updated estado
                        if (!esCredito) {
                            var _envArr = (config.envio || {})[data.estado];
                            var _modelo = self.app.getModelo(state.modeloSeleccionado);
                            var _esMC10 = _modelo && (_modelo.id === 'mc10');
                            var _costo = _envArr ? (_esMC10 ? _envArr[1] : _envArr[0]) : config.costoLogistico;
                            state.costoLogistico = _costo;
                            $('#vk-cp-logistics-price').text(VkUI.formatPrecio(_costo));
                        }
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
                } else {
                    // API returned no colonias — show manual input fallback
                    self._coloniaFallback(state);
                }

                // Always render centers after city name is confirmed
                self._renderCentros(cp);
            },
            error: function() {
                $('#vk-cp-colonia-loading').hide();
                // API failed — show manual input fallback
                self._coloniaFallback(state);
                // Still render centers with local city name
                self._renderCentros(cp);
            }
        });
    },

    _greenCheck: function(size) {
        size = size || 16;
        return '<span style="display:inline-flex;align-items:center;justify-content:center;width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:var(--vk-green-primary);flex-shrink:0;">' +
            '<span style="color:#fff;font-size:' + Math.round(size * 0.6) + 'px;line-height:1;">&#10003;</span></span>';
    },

    _cssIcon: function(symbol, bg) {
        bg = bg || 'var(--vk-green-primary)';
        return '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:' + bg + ';flex-shrink:0;">' +
            '<span style="color:#fff;font-size:9px;line-height:1;font-weight:700;">' + symbol + '</span></span>';
    },

    _tagButton: function(tag) {
        var base = window.VK_BASE_PATH || '';
        var iconMap = {
            'Exhibici\u00f3n': base + 'img/iconos-01.svg',
            'Entrega': base + 'img/iconos-03.svg',
            'Servicio t\u00e9cnico': base + 'img/iconos-02.svg'
        };
        var iconSrc = iconMap[tag];
        var iconHtml;
        if (iconSrc) {
            iconHtml = '<img src="' + iconSrc + '" alt="" style="width:14px;height:14px;flex-shrink:0;">';
        } else {
            var symbols = { 'Activaci\u00f3n': '\u26A1', 'Pruebas de manejo': '\u26F5', 'Refacciones': '\u2699' };
            var sym = symbols[tag] || '\u2022';
            iconHtml = '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#1a3a5c;color:#fff;font-size:7px;flex-shrink:0;">' + sym + '</span>';
        }
        return '<span style="display:inline-flex;align-items:center;gap:3px;font-size:10px;-webkit-text-size-adjust:none;text-size-adjust:none;font-weight:600;padding:3px 6px;border-radius:5px;background:#EDF2F7;color:#1a3a5c;border:1px solid #D0D8E0;white-space:nowrap;">' +
            iconHtml + tag + '</span>';
    },

    _pinIcon: function() {
        return '<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxOCIgaGVpZ2h0PSIxOCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjRDMyRjJGIiBzdHJva2U9Im5vbmUiPjxwYXRoIGQ9Ik0xMiAyQzguMTMgMiA1IDUuMTMgNSA5YzAgNS4yNSA3IDEzIDcgMTNzNy03Ljc1IDctMTNjMC0zLjg3LTMuMTMtNy03LTd6bTAgOS41YTIuNSAyLjUgMCAxIDEgMC01IDIuNSAyLjUgMCAwIDEgMCA1eiIvPjwvc3ZnPg==" alt="" style="width:18px;height:18px;vertical-align:middle;">';
    },

    _shieldIcon: function() {
        return '<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSIjMDA4QzQ1IiBzdHJva2U9Im5vbmUiPjxwYXRoIGQ9Ik0xMiAxbC05IDR2NmMwIDUuNTUgMy44NCAxMC43NCA5IDEyIDUuMTYtMS4yNiA5LTYuNDUgOS0xMlY1bC05LTR6bS0yIDE2bC00LTRMOSA5bC0uNzEuNzFMMTAgMTMuMTdsNy01LjA0TDE3LjcxIDcuNDIgMTAgMTd6Ii8+PC9zdmc+" alt="" style="width:16px;height:16px;vertical-align:middle;">';
    },

    _globeIcon: function() {
        return '<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNCIgaGVpZ2h0PSIxNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiMxYTNhNWMiIHN0cm9rZS13aWR0aD0iMiI+PGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiLz48bGluZSB4MT0iMiIgeTE9IjEyIiB4Mj0iMjIiIHkyPSIxMiIvPjxwYXRoIGQ9Ik0xMiAyYTEzIDEzIDAgMCAxIDQgMTAgMTMgMTMgMCAwIDEtNCAxMCAxMyAxMyAwIDAgMS00LTEwIDEzIDEzIDAgMCAxIDQtMTAiLz48L3N2Zz4=" alt="" style="width:14px;height:14px;vertical-align:middle;">';
    },

    _clockIcon: function() {
        return '<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNCIgaGVpZ2h0PSIxNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiMxYTNhNWMiIHN0cm9rZS13aWR0aD0iMiI+PGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiLz48cGF0aCBkPSJNMTIgNnY2bDQgMiIvPjwvc3ZnPg==" alt="" style="width:14px;height:14px;vertical-align:middle;">';
    },

    _renderCentroCard: function(centro) {
        var esCenter = (centro.tipo === 'center');
        if (esCenter) return this._renderCenterCard(centro);
        return this._renderCertificadoCard(centro);
    },

    // Voltika Center card (tipo 'center') — premium, star icon, checklist, 2 buttons
    _renderCenterCard: function(centro) {
        var self = this;
        var h = '';
        var mapsUrl = 'https://maps.google.com/?q=' + encodeURIComponent(centro.nombre + ' ' + centro.direccion + ' ' + centro.ciudad);

        h += '<div class="vk-card vk-centro-card" data-centro-id="' + centro.id + '" style="padding:0;border-radius:14px;overflow:hidden;margin-bottom:14px;border:2px solid #1a3a5c;cursor:pointer;transition:box-shadow 0.3s,border-color 0.3s;">';
        h += '<div style="padding:20px;">';

        // Radio circle + Star + title
        h += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;">';
        h += '<div class="vk-radio-circle" data-radio-id="' + centro.id + '" style="width:20px;height:20px;border-radius:50%;background:transparent;border:2px solid #ccc;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;"></div>';
        h += '<div style="flex:1;min-width:0;">';
        h += '<div style="font-weight:800;font-size:18px;color:var(--vk-text-primary);">' + centro.nombre + '</div>';
        h += '</div>';
        h += '</div>';

        // Location
        if (centro.ubicacion) {
            h += '<div style="font-size:14px;color:var(--vk-text-primary);margin-bottom:2px;display:flex;align-items:center;gap:6px;">' + self._pinIcon() + ' <strong>' + centro.ubicacion + '</strong></div>';
        }
        h += '<div style="font-size:13px;color:var(--vk-text-secondary);padding-left:22px;">' + centro.direccion + '</div>';
        if (centro.colonia) {
            h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:12px;padding-left:22px;">' + centro.colonia + '</div>';
        } else {
            h += '<div style="margin-bottom:12px;"></div>';
        }

        // Services list with icons (no green checks)
        if (centro.servicios && centro.servicios.length) {
            var serviceIconFiles = {
                'Exhibici\u00f3n': 'iconos-01.svg', 'Entrega': 'iconos-03.svg',
                'Servicio': 'iconos-02.svg'
            };
            var serviceSymbols = {
                'Pruebas': '\u26F5', 'Refacciones': '\u2699'
            };
            h += '<div style="margin-bottom:14px;">';
            var svcStyle = 'font-size:13px;color:var(--vk-text-primary);font-weight:400;margin-bottom:6px;display:flex;align-items:center;gap:8px;';
            for (var s = 0; s < centro.servicios.length; s++) {
                var parts = centro.servicios[s].split(' ');
                var firstWord = parts[0];
                var rest = parts.slice(1).join(' ');
                var iconHtml;
                if (serviceIconFiles[firstWord]) {
                    iconHtml = '<img src="' + (window.VK_BASE_PATH || '') + 'img/' + serviceIconFiles[firstWord] + '" alt="" style="width:18px;height:18px;object-fit:contain;flex-shrink:0;">';
                } else {
                    var sym = serviceSymbols[firstWord] || '\u2022';
                    iconHtml = '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#039fe1;color:#fff;font-size:8px;flex-shrink:0;">' + sym + '</span>';
                }
                h += '<div style="' + svcStyle + '">' + iconHtml + ' <strong>' + firstWord + '</strong> ' + rest + '</div>';
            }
            h += '</div>';
        }

        // Delivery coverage
        if (centro.descripcion) {
            h += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;font-size:13px;font-weight:700;color:#1a3a5c;">';
            h += self._globeIcon() + ' ' + centro.descripcion;
            h += '</div>';
        }

        // VER UBICACIÓN button
        h += '<a href="' + mapsUrl + '" target="_blank" rel="noopener" ' +
            'style="display:block;text-align:center;font-size:13px;font-weight:700;color:#fff;text-decoration:none;padding:14px;border-radius:8px;background:#039fe1;margin-bottom:10px;">' +
            self._pinIcon() + ' VER UBICACI\u00d3N DEL VOLTIKA CENTER</a>';

        // Footer
        h += '<div style="font-size:12px;color:#1a3a5c;text-align:center;">';
        h += self._clockIcon() + ' ' + centro.horarios;
        h += '</div>';

        h += '</div>'; // end body
        h += '</div>'; // end card

        return h;
    },

    // Punto Voltika certificado card (tipo 'certificado') — shield icon, tags, select button
    _renderCertificadoCard: function(centro) {
        var self = this;
        var h = '';
        var mapsUrl = 'https://maps.google.com/?q=' + encodeURIComponent(centro.nombre + ' ' + centro.direccion + ' ' + centro.ciudad);

        h += '<div class="vk-card vk-centro-card" data-centro-id="' + centro.id + '" style="padding:0;border-radius:14px;overflow:hidden;margin-bottom:14px;border:1.5px solid #1a3a5c;cursor:pointer;transition:box-shadow 0.3s,border-color 0.3s;">';
        h += '<div style="padding:16px;">';

        // Header: radio circle + title
        h += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">';
        h += '<div class="vk-radio-circle" data-radio-id="' + centro.id + '" style="width:20px;height:20px;border-radius:50%;background:transparent;border:2px solid #ccc;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;">';
        h += '</div>';
        h += '<div style="flex:1;min-width:0;">';
        h += '<div style="font-weight:800;font-size:15px;color:var(--vk-text-primary);">' + centro.nombre + '</div>';
        h += '<div style="font-size:12px;color:var(--vk-green-primary);font-weight:600;display:flex;align-items:center;gap:4px;">';
        h += self._shieldIcon() + ' Punto Voltika certificado';
        h += '</div>';
        h += '</div>';
        h += '</div>';

        // Location + address
        if (centro.ubicacion) {
            h += '<div style="font-size:14px;color:var(--vk-text-primary);margin-bottom:2px;display:flex;align-items:center;gap:6px;">' + self._pinIcon() + ' <strong>' + centro.ubicacion + '</strong></div>';
        }
        h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;padding-left:22px;">' + centro.direccion + '</div>';

        // Tags
        h += '<div style="display:flex;flex-wrap:nowrap;gap:3px;margin-bottom:14px;-webkit-text-size-adjust:none;text-size-adjust:none;">';
        if (centro.tags && centro.tags.length) {
            for (var t = 0; t < centro.tags.length; t++) {
                h += self._tagButton(centro.tags[t]);
            }
        }
        h += '</div>';

        // Bottom section
        h += '<div style="background:#F7F7F7;border-radius:10px;padding:14px;margin-bottom:4px;">';
        h += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;display:flex;align-items:center;gap:6px;">' + self._greenCheck() + ' Punto Voltika verificado</div>';
        if (centro.descripcion) {
            h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;">' + centro.descripcion + '</div>';
        }
        h += '<a href="' + mapsUrl + '" target="_blank" rel="noopener" ' +
            'style="display:inline-block;font-size:13px;font-weight:700;color:var(--vk-text-primary);text-decoration:none;padding:8px 16px;border:1.5px solid #ccc;border-radius:8px;background:#fff;">' +
            '&#10095; Ver ubicaci\u00f3n</a>';
        h += '</div>';

        h += '</div>'; // end body
        h += '</div>'; // end card

        return h;
    },

    _renderCentroCercanoCard: function(ciudad) {
        var self = this;
        var h = '';
        var titleCiudad = ciudad ? ' en ' + ciudad : '';

        h += '<div class="vk-card vk-centro-card" data-centro-id="centro-cercano" style="padding:0;border-radius:14px;overflow:hidden;margin-bottom:14px;border:1.5px solid #ddd;cursor:pointer;transition:box-shadow 0.3s,border-color 0.3s;">';

        // Card body
        h += '<div style="padding:16px;">';

        // Radio circle + title (same as certificado cards)
        h += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">';
        h += '<div class="vk-radio-circle" data-radio-id="centro-cercano" style="width:20px;height:20px;border-radius:50%;background:transparent;border:2px solid #ccc;flex-shrink:0;margin-top:2px;display:flex;align-items:center;justify-content:center;">';
        h += '</div>';
        h += '<div style="min-width:0;">';
        h += '<div style="font-weight:800;font-size:17px;color:var(--vk-text-primary);">Centro Voltika cercano' + titleCiudad + '</div>';
        h += '<div style="font-size:12px;color:#1a3a5c;font-weight:600;">Red nacional de centros autorizados Voltika</div>';
        h += '</div>';
        h += '</div>';

        // Description
        h += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:14px;line-height:1.5;">';
        h += 'Si no ves un centro cercano, <strong>Voltika coordinar\u00e1</strong> la entrega y servicio autorizado m\u00e1s cercano a ti.';
        h += '</div>';

        // Green checks
        h += '<div style="margin-bottom:14px;">';
        var checkStyle = 'font-size:13px;color:var(--vk-green-primary);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px;';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Entrega y activaci\u00f3n de tu moto</div>';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Servicio t\u00e9cnico autorizado</div>';
        h += '<div style="' + checkStyle + '">' + self._greenCheck() + ' Refacciones y soporte Voltika</div>';
        h += '</div>';

        // Cobertura badge
        h += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;">';
        h += '<span style="font-size:13px;font-weight:700;color:#1a3a5c;">' + self._globeIcon() + ' Cobertura nacional Voltika</span>';
        h += '</div>';

        // Confirmation note
        h += '<div style="font-size:12px;color:#1a3a5c;background:#E8F4FD;border-radius:8px;padding:10px 14px;text-align:center;">';
        h += '' + self._clockIcon() + ' Confirmaci\u00f3n en menos de <strong>24 horas</strong> por WhatsApp o correo';
        h += '</div>';

        h += '</div>'; // end body
        h += '</div>'; // end card

        return h;
    },

    _renderCentros: function(cp) {
        var self = this;
        var ciudad = self.app.state.ciudad || '';

        if (typeof VOLTIKA_CENTROS === 'undefined' || !VOLTIKA_CENTROS.buscar) {
            // No centers module — show cercano fallback
            var recHtml = self._renderCentroCercanoCard(ciudad);
            $('#vk-centro-recomendado').html(recHtml);
            $('#vk-otros-centros-wrapper').hide();
            $('#vk-centros-section').slideDown(200);
            return;
        }

        var centros = VOLTIKA_CENTROS.buscar(cp);
        if (!centros || centros.length === 0) {
            // No matching centers — show only cercano card
            var recHtml = self._renderCentroCercanoCard(ciudad);
            $('#vk-centro-recomendado').html(recHtml);
            $('#vk-otros-centros-wrapper').hide();
            $('#vk-centros-section').slideDown(200);
            return;
        }

        // Sort: center first, then certificado, then entrega
        var tipoPriority = { 'center': 0, 'certificado': 1, 'entrega': 2 };
        centros.sort(function(a, b) {
            var pa = tipoPriority[a.tipo] !== undefined ? tipoPriority[a.tipo] : 3;
            var pb = tipoPriority[b.tipo] !== undefined ? tipoPriority[b.tipo] : 3;
            return pa - pb;
        });

        // Show first 3 centers directly (no expand needed)
        var maxVisible = 3;
        var recHtml = '';
        recHtml += '<div style="font-size:17px;font-weight:800;color:#1a3a5c;margin-bottom:12px;">Selecciona donde quieres recibir tu Voltika:</div>';
        for (var v = 0; v < Math.min(centros.length, maxVisible); v++) {
            recHtml += self._renderCentroCard(centros[v]);
        }
        // Always append cercano card
        recHtml += self._renderCentroCercanoCard(ciudad);
        $('#vk-centro-recomendado').html(recHtml);

        // Remaining centers (4+) go in expandable section
        if (centros.length > maxVisible) {
            var otrosHtml = '';
            for (var i = maxVisible; i < centros.length; i++) {
                otrosHtml += self._renderCentroCard(centros[i]);
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

        // Handle cercano (virtual center)
        if (centroId === 'centro-cercano') {
            centro = {
                id: 'centro-cercano',
                nombre: 'Centro Voltika cercano',
                direccion: 'Por confirmar por tu asesor Voltika',
                ciudad: this.app.state.ciudad || '',
                estado: this.app.state.estado || '',
                tipo: 'cercano'
            };
        } else if (typeof VOLTIKA_CENTROS !== 'undefined') {
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
            // Reset all radio circles
            $('.vk-radio-circle').css({ 'background': 'transparent', 'border-color': '#ccc' }).html('');
            // Reset all card glow
            $('.vk-centro-card').css({ 'box-shadow': 'none', 'border-color': '#1a3a5c' });
            // Cercano card has different default border
            $('.vk-centro-card[data-centro-id="centro-cercano"]').css('border-color', '#ddd');
            // Fill selected radio circle
            $('.vk-radio-circle[data-radio-id="' + centroId + '"]')
                .css({ 'background': '#039fe1', 'border-color': '#039fe1' })
                .html('<span style="color:#fff;font-size:12px;line-height:1;">&#10003;</span>');
            // Glow on selected card
            $('.vk-centro-card[data-centro-id="' + centroId + '"]')
                .css({ 'box-shadow': '0 0 14px rgba(3,159,225,0.45)', 'border-color': '#039fe1' });
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
        // Button always enabled
            // $('#vk-paso3-confirmar').prop('disabled', false);
    }
};
