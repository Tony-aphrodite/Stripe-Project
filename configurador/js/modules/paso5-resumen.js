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
        var total   = modelo.precioContado + state.costoLogistico;
        var msiPago = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);
        var color   = state.colorSeleccionado || modelo.colorDefault || '';
        var ciudad  = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');

        var html = '';

        // 1. Back button
        html += VkUI.renderBackButton(3);

        // 2. Header: título + logos
        html += '<div style="text-align:center;margin-bottom:14px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:2px;">\u00b7 PASO 4 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:6px;">Confirma tu forma de pago segura</h2>';
        html += VkUI.renderCardLogos();
        html += '</div>';

        // 3. "Tu moto está lista" card — MSI destacado
        html += '<div class="vk-card" style="padding:20px;margin-bottom:14px;text-align:center;">';
        html += '<div style="font-size:14px;font-weight:700;margin-bottom:2px;">&#128230; Tu moto ' + modelo.nombre + ' est\u00e1 lista &#128640;</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Ll\u00e9vatela por solo</div>';
        html += '<div style="font-size:38px;font-weight:900;color:var(--vk-text-primary);line-height:1;">' + VkUI.formatPrecio(msiPago) + ' <span style="font-size:18px;font-weight:700;">/ mes</span></div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:12px;font-weight:700;color:#039fe1;margin:6px 0 2px;">9 MSI SIN INTERESES</div>';
        }
        html += '<div style="font-size:12px;color:var(--vk-text-muted);margin-bottom:14px;">Primer cargo hoy.</div>';
        html += '<button id="vk-resumen-pagar-msi" style="display:block;width:100%;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:0.3px;">PAGAR ' + VkUI.formatPrecio(msiPago) + ' HOY</button>';
        html += '<div style="margin-top:10px;font-size:12px;color:var(--vk-text-muted);">&#128274; Pago seguro con ' + VkUI.renderCardLogos() + '</div>';
        html += '</div>';

        // 4. Opciones de pago — radio style interactivo (vertical)
        // Opción MSI
        if (modelo.tieneMSI) {
            html += '<div id="vk-opcion-msi" class="vk-opcion-pago" data-tipo="msi" style="border:2px solid #039fe1;border-radius:10px;padding:14px;margin-bottom:10px;background:#f0faff;cursor:pointer;">';
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
            html += '<span id="vk-radio-msi" style="width:18px;height:18px;border-radius:50%;background:#039fe1;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="width:8px;height:8px;border-radius:50%;background:#fff;display:block;"></span></span>';
            html += '<span style="font-size:14px;font-weight:700;">Pagar a 9 meses sin intereses</span>';
            html += '</div>';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';
            html += '<span style="font-size:15px;font-weight:800;">' + VkUI.formatPrecio(msiPago) + ' MXN <span style="font-size:13px;font-weight:500;">/ mes</span></span>';
            html += '<button id="vk-resumen-pagar-msi-2" style="padding:8px 14px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">PAGAR ' + VkUI.formatPrecio(msiPago) + ' HOY</button>';
            html += '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-top:6px;">Primer pago hoy y luego 8 pagos mensuales.</div>';
            html += '</div>';
        }

        // Opción contado
        html += '<div id="vk-opcion-contado" class="vk-opcion-pago" data-tipo="contado" style="border:1.5px solid var(--vk-border);border-radius:10px;padding:14px;margin-bottom:16px;cursor:pointer;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<span id="vk-radio-contado" style="width:18px;height:18px;border-radius:50%;border:2px solid #ccc;display:inline-block;flex-shrink:0;"></span>';
        html += '<span style="font-size:14px;font-weight:700;">O pagar al contado</span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">';
        html += '<span style="font-size:15px;font-weight:800;">' + VkUI.formatPrecio(total) + ' MXN</span>';
        html += '<button id="vk-resumen-pagar-contado" style="padding:8px 14px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">PAGAR ' + VkUI.formatPrecio(total) + '</button>';
        html += '</div>';
        html += '</div>';

        // 5. Resumen al fondo — 2 columnas
        html += '<div class="vk-summary" style="padding:14px 16px;">';
        html += '<div style="font-weight:700;font-size:14px;margin-bottom:10px;">Resumen de tu compra</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:13px;">';
        html += '<div>Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div style="color:var(--vk-green-primary);">&#10003; Sin intereses</div>';
        html += '<div>Color: <strong>' + color + '</strong></div>';
        html += '<div style="color:var(--vk-green-primary);">&#10003; Sin cargos ocultos</div>';
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

        var html = '';
        html += VkUI.renderBackButton(3);

        // Header
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:22px;font-weight:800;">voltika</div>';
        html += '<h2 style="font-size:22px;font-weight:800;margin-top:8px;">&#161;Toma solo 2 minutos!</h2>';
        html += '</div>';

        // Selection summary
        html += '<div class="vk-credit-summary">';
        html += '<div class="vk-credit-summary__model">';
        html += '<div class="vk-credit-summary__details">';
        html += '<div style="font-size:15px;font-weight:700;margin-bottom:4px;">Resumen de tu selecci\u00f3n:</div>';
        html += '<div style="font-size:14px;margin-bottom:2px;">Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div style="font-size:14px;margin-bottom:2px;">Enganche: <strong style="color:var(--vk-green-primary);">' + VkUI.formatPrecio(credito.enganche) + '</strong></div>';
        html += '<div style="font-size:13px;margin-bottom:2px;">Desde <strong>' + VkUI.formatPrecio(credito.pagoSemanal) + '</strong> por semana</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Color: ' + (state.colorSeleccionado || modelo.colorDefault) + '</div>';
        html += '<div style="background:var(--vk-green-soft);border-radius:6px;padding:6px 8px;margin-top:8px;font-size:12px;">' +
            'Entrega en tu ciudad en punto aliado Voltika<br>' +
            '<span style="font-size:11px;color:var(--vk-text-secondary);">Se entrega en permiso provisional y documentos para que puedas emplacar\u00e1cilmente</span>' +
            '</div>';
        html += '</div>';
        html += '<img class="vk-credit-summary__img" src="' + img + '" alt="' + modelo.nombre + '">';
        html += '</div>';
        html += '</div>';

        // 5 pasos list
        html += '<div class="vk-credit-steps">';
        html += '<div class="vk-credit-steps__title">5 pasos muy sencillos:</div>';

        var pasos = [
            ['Verifica tu identidad', 'INE y selfie'],
            ['Confirma tu lugar de entrega cercano', 'Puedes elegir un punto aliado Voltika'],
            ['Hablamos contigo y te guiamos en persona', 'Tu asesor Voltika te llama, resuelve tus dudas y agenda la entrega'],
            ['Paga tu enganche de forma segura', 'Puedes pagar con tarjeta, efectivo o transferencia'],
            ['Firma contrato y recibe tu moto', 'Activamos pagos semanales con tu tarjeta']
        ];

        for (var s = 0; s < pasos.length; s++) {
            html += '<div class="vk-credit-step">';
            html += '<div class="vk-credit-step__number">' + (s + 1) + '</div>';
            html += '<div class="vk-credit-step__content">';
            html += '<div class="vk-credit-step__title">' + pasos[s][0] + '</div>';
            html += '<div class="vk-credit-step__desc">' + pasos[s][1] + '</div>';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>';

        // CTA
        html += '<button class="vk-btn vk-btn--blue" id="vk-resumen-iniciar-credito">Iniciar proceso</button>';
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin:4px 0 16px;">Toma menos de 2 minutos.</p>';

        // Alternative: pay with card
        html += '<div style="border-top:1px solid var(--vk-border-light);padding-top:14px;text-align:center;">';
        html += '<p style="font-size:13px;color:var(--vk-text-muted);margin-bottom:8px;">\u00bfPrefieres pagar con tarjeta?</p>';
        html += '<div style="display:flex;gap:10px;justify-content:center;">';
        if (modelo.tieneMSI) {
            html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-msi" style="flex:1;max-width:180px;font-size:13px;">9 MSI sin intereses</button>';
        }
        html += '<button class="vk-btn vk-btn--secondary" id="vk-switch-contado" style="flex:1;max-width:180px;font-size:13px;">Pago contado</button>';
        html += '</div>';
        html += '</div>';

        jQuery('#vk-resumen-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Contado/MSI: Pago contado button
        jQuery(document).off('click', '#vk-resumen-pagar-contado');
        jQuery(document).on('click', '#vk-resumen-pagar-contado', function() {
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

        // Switch to contado/MSI from crédito view
        jQuery(document).off('click', '#vk-switch-contado');
        jQuery(document).on('click', '#vk-switch-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(3); // Recalculate logistics
        });
        jQuery(document).off('click', '#vk-switch-msi');
        jQuery(document).on('click', '#vk-switch-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(3);
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
