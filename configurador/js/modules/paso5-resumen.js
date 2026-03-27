/* ==========================================================================
   Voltika - PASO Resumen (Screen 4 contado/msi, Screen 5 crédito)
   Per Dibujo.pdf:
   - Contado/MSI: Order summary + 2 buttons (Pago contado / 9 MSI)
   - Crédito: Selection summary + 5 pasos + "Iniciar proceso"
   ========================================================================== */

var PasoResumen = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    _calcFechaEntrega: function() {
        var d = new Date();
        d.setDate(d.getDate() + 15);
        var m = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return d.getDate() + ' de ' + m[d.getMonth()] + ' de ' + d.getFullYear();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var metodo = state.metodoPago;

        if (metodo === 'credito') {
            this._renderCredito(modelo, state);
        } else {
            this._renderContadoMSI(modelo, state);
        }
    },

    /* ── CONTADO / MSI: Screen 4 ─────────────────────────────────────────── */
    _renderContadoMSI: function(modelo, state) {
        var costoLog = state.costoLogistico || 0;
        var totalContado = modelo.precioContado; // Contado: freight is free, original price
        var totalMSI = modelo.precioContado + costoLog; // MSI: includes freight
        var total = totalContado; // Default to contado price
        var msiPagoExact = modelo.tieneMSI ? (modelo.precioMSI * 9 + costoLog) / 9 : totalMSI / 9;
        var msiPago = Math.round(msiPagoExact);
        var _fmtMsi2 = '$' + msiPagoExact.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var color   = state.colorSeleccionado || modelo.colorDefault || '';
        var ciudad  = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');
        var _envioDestino = (state.centroEntrega && state.centroEntrega.nombre && state.centroEntrega.tipo !== 'cercano')
            ? state.centroEntrega.nombre
            : (state.ciudad || 'tu ciudad');

        var html = '';

        // 1. Back button
        html += VkUI.renderBackButton(3);

        // 2. Header: título + logos
        html += '<div style="text-align:center;margin-bottom:14px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:2px;">\u00b7 PASO 4 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:6px;">Confirma tu forma de pago segura</h2>';
        html += VkUI.renderCardLogos();
        html += '</div>';

        // 3. "Tu moto está lista" card — adapts to metodoPago
        html += '<div class="vk-card" style="padding:20px;margin-bottom:14px;text-align:center;">';
        html += '<div style="font-size:14px;font-weight:700;margin-bottom:2px;">Tu moto ' + modelo.nombre + ' est\u00e1 lista</div>';
        if (state.metodoPago === 'msi' && modelo.tieneMSI) {
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Ll\u00e9vatela por solo</div>';
            html += '<div style="font-size:38px;font-weight:900;color:var(--vk-text-primary);line-height:1;">' + _fmtMsi2 + ' <span style="font-size:18px;font-weight:700;">/ mes</span></div>';
            html += '<div style="font-size:12px;font-weight:700;color:#039fe1;margin:6px 0 2px;">9 MSI SIN INTERESES</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-bottom:4px;">Primer cargo hoy.</div>';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:4px;">Env\u00edo incluido a ' + _envioDestino + '</div>';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:14px;">9 meses de ' + _fmtMsi2 + '</div>';
            html += '<button id="vk-resumen-pagar-msi" style="display:block;width:100%;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">PAGAR ' + _fmtMsi2 + ' HOY</button>';
        } else {
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Pago \u00fanico</div>';
            html += '<div style="font-size:38px;font-weight:900;color:var(--vk-text-primary);line-height:1;">' + VkUI.formatPrecio(total) + ' <span style="font-size:16px;font-weight:700;">MXN</span></div>';
            html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-bottom:4px;">IVA incluido.</div>';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-green-primary);margin-bottom:14px;">Env\u00edo incluido a ' + _envioDestino + '</div>';
            html += '<button id="vk-resumen-pagar-contado-main" style="display:block;width:100%;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">PAGAR ' + VkUI.formatPrecio(total) + ' HOY</button>';
        }
        html += '<div style="margin-top:10px;font-size:12px;color:var(--vk-text-muted);">&#128274; Pago seguro con ' + VkUI.renderCardLogos() + '</div>';
        html += '</div>';

        // 4. Opciones de pago — radio style interactivo (vertical)
        // Opción MSI
        var _esMSI = (state.metodoPago === 'msi');
        if (modelo.tieneMSI) {
            var _msiBorder = _esMSI ? 'border:2px solid #039fe1;background:#f0faff;' : 'border:1.5px solid var(--vk-border);';
            var _msiRadio = _esMSI
                ? '<span id="vk-radio-msi" style="width:18px;height:18px;border-radius:50%;background:#039fe1;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="width:8px;height:8px;border-radius:50%;background:#fff;display:block;"></span></span>'
                : '<span id="vk-radio-msi" style="width:18px;height:18px;border-radius:50%;border:2px solid #ccc;display:inline-block;flex-shrink:0;"></span>';
            html += '<div id="vk-opcion-msi" class="vk-opcion-pago" data-tipo="msi" style="' + _msiBorder + 'border-radius:10px;padding:14px;margin-bottom:10px;cursor:pointer;">';
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
            html += _msiRadio;
            html += '<span style="font-size:14px;font-weight:700;">Pagar a 9 meses sin intereses</span>';
            html += '</div>';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';
            html += '<span style="font-size:15px;font-weight:800;">' + _fmtMsi2 + ' MXN <span style="font-size:13px;font-weight:500;">/ mes</span></span>';
            html += '<button id="vk-resumen-pagar-msi-2" style="padding:8px 14px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">PAGAR ' + _fmtMsi2 + ' HOY</button>';
            html += '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-top:6px;">Primer pago hoy y luego 8 pagos mensuales.</div>';
            if (costoLog > 0) {
                html += '<div style="font-size:11px;margin-top:6px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad: ' + VkUI.formatPrecio(costoLog) + ' MXN</strong></div>';
            } else {
                html += '<div style="font-size:11px;margin-top:6px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad:</strong> <strong style="color:#00C851;">Sin costo</strong></div>';
            }
            html += '</div>';
        }

        // Opción contado
        var _contBorder = !_esMSI ? 'border:2px solid #039fe1;background:#f0faff;' : 'border:1.5px solid var(--vk-border);';
        var _contRadio = !_esMSI
            ? '<span id="vk-radio-contado" style="width:18px;height:18px;border-radius:50%;background:#039fe1;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="width:8px;height:8px;border-radius:50%;background:#fff;display:block;"></span></span>'
            : '<span id="vk-radio-contado" style="width:18px;height:18px;border-radius:50%;border:2px solid #ccc;display:inline-block;flex-shrink:0;"></span>';
        html += '<div id="vk-opcion-contado" class="vk-opcion-pago" data-tipo="contado" style="' + _contBorder + 'border-radius:10px;padding:14px;margin-bottom:16px;cursor:pointer;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += _contRadio;
        html += '<span style="font-size:14px;font-weight:700;">O pagar al contado</span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';
        html += '<span style="font-size:15px;font-weight:800;">' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '<button id="vk-resumen-pagar-contado" style="padding:8px 14px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">PAGAR ' + VkUI.formatPrecio(total) + '</button>';
        html += '</div>';
        if (costoLog > 0) {
            html += '<div style="font-size:11px;margin-top:6px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad: <span style="text-decoration:line-through;color:#999;">' + VkUI.formatPrecio(costoLog) + '</span></strong> <strong style="color:#00C851;">Sin costo en pago de contado</strong></div>';
        } else {
            html += '<div style="font-size:11px;margin-top:6px;"><strong style="color:#039fe1;">Costo log\u00edstico a tu ciudad:</strong> <strong style="color:#00C851;">Sin costo</strong></div>';
        }
        html += '</div>';

        // 5. Resumen al fondo — 2 columnas
        html += '<div class="vk-summary" style="padding:14px 16px;">';
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">Resumen de tu compra</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:13px;">';
        html += '<div>Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div style="color:var(--vk-green-primary);">&#10003; Sin intereses</div>';
        html += '<div>Color: <strong>' + color + '</strong></div>';
        html += '<div style="color:var(--vk-green-primary);">&#10003; Sin cargos adicionales</div>';
        html += '<div>Entrega: <strong>' + ciudad + '</strong></div>';
        html += '<div></div>';
        if (state.costoLogistico > 0) {
            html += '<div>Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
            html += '<div></div>';
        }
        html += '</div>';
        html += '</div>';

        jQuery('#vk-resumen-container').html(html);
    },

    /* ── CRÉDITO: Screen 5 ───────────────────────────────────────────────── */
    _renderCredito: function(modelo, state) {
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.25,
            state.plazoMeses || 12
        );
        var img = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);
        var color = state.colorSeleccionado || modelo.colorDefault || '';
        var pagoDiario = Math.ceil(credito.pagoSemanal / 7);

        var html = '';
        html += VkUI.renderBackButton(3);

        // 1. Header
        html += '<div style="margin-bottom:16px;">';
        html += '<div style="font-size:20px;font-weight:900;line-height:1.2;">Obt\u00e9n tu aprobaci\u00f3n en 2 minutos</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">Proceso seguro y 100% digital</div>';
        html += '</div>';

        // 2. Model card
        html += '<div class="vk-card" style="padding:16px;margin-bottom:14px;">';
        html += '<div style="font-size:13px;font-weight:700;margin-bottom:10px;">Tu Voltika seleccionada</div>';
        html += '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">';
        html += '<div style="flex:1;">';
        html += '<div style="font-size:16px;font-weight:800;">' + Paso1._getModeloLogo(modelo.id, modelo.nombre) + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:10px;">Color: ' + color + '</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Pago semanal desde</div>';
        html += '<div style="font-size:32px;font-weight:900;color:var(--vk-text-primary);line-height:1.1;">' + VkUI.formatPrecio(credito.pagoSemanal) + '</div>';
        html += '<div style="font-size:12px;font-weight:700;margin-top:4px;">Enganche<br><span style="color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.enganche) + '</span></div>';
        html += '<div style="font-size:12px;font-weight:700;margin-top:4px;">Plazo<br><span style="color:#039fe1;">' + (state.plazoMeses || 36) + ' meses</span></div>';
        html += '</div>';
        html += '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">';
        html += '<img src="' + img + '" alt="' + modelo.nombre + '" style="width:100px;height:auto;object-fit:contain;">';
        html += '<div style="font-size:11px;color:var(--vk-text-secondary);text-align:center;margin-top:4px;">menos de <strong>' + VkUI.formatPrecio(pagoDiario) + '</strong> al d\u00eda</div>';
        html += '</div>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:6px;margin-top:12px;padding-top:10px;border-top:1px solid var(--vk-border);">';
        html += '<span style="color:var(--vk-green-primary);font-size:16px;">&#10003;</span>';
        var _centroNombre = (state.centroEntrega && state.centroEntrega.nombre) ? state.centroEntrega.nombre : '';
        var _entregaDetalle = _centroNombre
            ? '<strong style="color:#039fe1;">' + _centroNombre + '</strong>' + (state.ciudad ? ', ' + state.ciudad : '')
            : (state.ciudad || 'tu ciudad');
        html += '<span style="font-size:13px;"><strong>Entrega garantizada</strong> en ' + _entregaDetalle + '<br><span style="font-size:12px;color:var(--vk-text-secondary);">Incluye documentos para tramitar placas</span></span>';
        html += '</div>';
        html += '</div>';

        // 3. 5 pasos
        html += '<div style="margin-bottom:16px;">';
        html += '<div style="font-size:15px;font-weight:800;margin-bottom:12px;">Solo 5 pasos (menos de 2 minutos)</div>';

        var pasos = [
            ['Verifica tu identidad',             'INE + selfie'],
            ['Confirma tu ciudad de entrega',      'Asignamos tu punto Voltika cercano'],
            ['Recibe tu aprobaci\u00f3n',          'En menos de 2 minutos'],
            ['Paga tu enganche de forma segura',   'Tarjeta, transferencia o efectivo'],
            ['Firma digital y recibe tu Voltika',  'Primer pago 7 d\u00edas despu\u00e9s de la entrega']
        ];

        for (var s = 0; s < pasos.length; s++) {
            var isLast = (s === pasos.length - 1);
            html += '<div style="display:flex;align-items:stretch;gap:12px;">';
            // Number circle + vertical connecting line
            html += '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">';
            html += '<div style="width:28px;height:28px;border-radius:50%;background:#039fe1;color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;">' + (s + 1) + '</div>';
            if (!isLast) {
                html += '<div style="width:2px;flex:1;min-height:10px;background:#b8ddf5;margin:2px 0;"></div>';
            }
            html += '</div>';
            html += '<div style="padding-bottom:' + (isLast ? '0' : '10') + 'px;">';
            html += '<div style="font-size:14px;font-weight:700;">' + pasos[s][0] + '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);">' + pasos[s][1] + '</div>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>';

        // 4. CTA button
        html += '<button id="vk-resumen-iniciar-credito" style="display:block;width:100%;padding:16px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:17px;font-weight:900;cursor:pointer;letter-spacing:0.5px;margin-bottom:10px;">VER SI CALIFICO</button>';

        // 5. Trust badges
        html += '<div style="display:flex;justify-content:center;gap:16px;margin-bottom:14px;font-size:12px;color:var(--vk-text-secondary);">';
        html += '<span><span style="color:var(--vk-green-primary);">&#10003;</span> En menos de 2 minutos</span>';
        html += '<span><span style="color:var(--vk-green-primary);">&#10003;</span> Solo necesitas tu INE</span>';
        html += '</div>';

        // 7. Bottom text links
        html += '<div style="text-align:center;font-size:14px;font-weight:700;color:var(--vk-text-primary);margin-bottom:8px;border-top:1px solid var(--vk-border);padding-top:14px;">Otras opciones de pago</div>';
        html += '<div style="display:flex;justify-content:center;gap:0;">';
        if (modelo.tieneMSI) {
            html += '<button id="vk-switch-msi" style="flex:1;background:none;border:none;border-right:1px solid var(--vk-border);font-size:13px;font-weight:700;color:#039fe1;cursor:pointer;padding:8px 4px;">9 MSI CON TC</button>';
        }
        html += '<button id="vk-switch-contado" style="flex:1;background:none;border:none;font-size:13px;font-weight:700;color:var(--vk-text-secondary);cursor:pointer;padding:8px 4px;">PAGO DE CONTADO</button>';
        html += '</div>';

        jQuery('#vk-resumen-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Contado/MSI: Pago contado button
        jQuery(document).off('click', '#vk-resumen-pagar-contado, #vk-resumen-pagar-contado-main');
        jQuery(document).on('click', '#vk-resumen-pagar-contado, #vk-resumen-pagar-contado-main', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(4); // Goes to Paso4A (Stripe)
        });

        // Contado/MSI: 9 MSI buttons (main card + radio option)
        jQuery(document).off('click', '#vk-resumen-pagar-msi, #vk-resumen-pagar-msi-2');
        jQuery(document).on('click', '#vk-resumen-pagar-msi, #vk-resumen-pagar-msi-2', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(4);
        });

        // Radio selection: clicking opcion area toggles active style
        jQuery(document).off('click', '.vk-opcion-pago');
        jQuery(document).on('click', '.vk-opcion-pago', function(e) {
            if (jQuery(e.target).is('button')) return; // let button handle navigation
            var tipo = jQuery(this).data('tipo');
            // Reset both
            jQuery('#vk-opcion-msi').css({'border': '1.5px solid var(--vk-border)', 'background': '#fff'});
            jQuery('#vk-radio-msi').css({'background': '#ccc'}).html('');
            jQuery('#vk-opcion-contado').css({'border': '1.5px solid var(--vk-border)', 'background': '#fff'});
            jQuery('#vk-radio-contado').css({'background': '', 'border': '2px solid #ccc'}).html('');
            // Activate selected
            jQuery('#vk-opcion-' + tipo).css({'border': '2px solid #039fe1', 'background': '#f0faff'});
            jQuery('#vk-radio-' + tipo).css({'background': '#039fe1', 'border': 'none'}).html('<span style="width:8px;height:8px;border-radius:50%;background:#fff;display:block;margin:auto;"></span>');
        });

        // Crédito: Iniciar proceso
        jQuery(document).off('click', '#vk-resumen-iniciar-credito');
        jQuery(document).on('click', '#vk-resumen-iniciar-credito', function() {
            self.app.irAPaso('credito-nombre'); // Screen 6: name
        });

        // Switch to contado/MSI from crédito view → go to Stripe payment
        jQuery(document).off('click', '#vk-switch-contado');
        jQuery(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(4);
        });
        jQuery(document).off('click', '#vk-switch-msi');
        jQuery(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(4);
        });

        // Legacy: keep old button working if somehow still present
        jQuery(document).off('click', '#vk-resumen-continuar');
        jQuery(document).on('click', '#vk-resumen-continuar', function() {
            var target = jQuery(this).data('target');
            if (target === 'credito-enganche') {
                self.app.irAPaso('credito-enganche');
            } else {
                self.app.irAPaso(4);
            }
        });
    }
};
