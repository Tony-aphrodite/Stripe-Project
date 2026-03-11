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
        var total = modelo.precioContado + state.costoLogistico;
        var msiPago = modelo.tieneMSI ? Math.round(modelo.precioMSI) : Math.round(total / 9);
        var color = state.colorSeleccionado || modelo.colorDefault || '';
        var ciudad = (state.ciudad && state.estado) ? state.ciudad + ', ' + state.estado : (state.ciudad || '--');
        var diasEntrega = (VOLTIKA_PRODUCTOS.config && VOLTIKA_PRODUCTOS.config.entregaDiasHabiles) || '7 a 10';
        var base = window.VK_BASE_PATH || '';
        var imgSrc = base + 'img/' + modelo.id + '/model.png';

        var html = '';

        // 1. Back button
        html += VkUI.renderBackButton(3);

        // 2. Header: title first, then card logos
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);margin-bottom:4px;">\u00b7 PASO 4 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo" style="margin-bottom:8px;">Confirma tu forma de pago segura</h2>';
        html += VkUI.renderCardLogos();
        html += '</div>';

        // 3. "Tu moto está lista" card
        html += '<div class="vk-card" style="padding:16px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<img src="' + imgSrc + '" alt="' + modelo.nombre + '" style="width:110px;height:auto;object-fit:contain;flex-shrink:0;">';
        html += '<div>';
        html += '<div style="font-size:13px;color:var(--vk-green-primary);font-weight:700;margin-bottom:2px;">&#10003; Tu moto est\u00e1 lista</div>';
        html += '<div style="font-weight:800;font-size:20px;line-height:1.1;">' + modelo.nombre + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">Color: ' + color + '</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">Entrega: ' + ciudad + '</div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // 4. Two payment cards — horizontal flex row
        html += '<div style="display:flex;flex-direction:row;gap:10px;margin-bottom:16px;align-items:stretch;">';

        // Left: Pago único
        html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
        html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">Pago \u00fanico<br>100% seguro</div>';
        html += '<div style="font-size:11px;color:var(--vk-text-secondary);flex:1;line-height:1.6;">';
        html += '<div>\u2022 Pago protegido y encriptado</div>';
        html += '<div>\u2022 Confirmaci\u00f3n bancaria al instante</div>';
        html += '<div>\u2022 Atenci\u00f3n personalizada post-venta</div>';
        html += '</div>';
        html += '<button id="vk-resumen-pagar-contado" style="display:block;width:100%;margin-top:10px;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">PAGAR ' + VkUI.formatPrecio(total) + ' MXN</button>';
        html += '</div>';

        // Right: 9 MSI
        if (modelo.tieneMSI) {
            html += '<div style="flex:1;min-width:0;border:1.5px solid var(--vk-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;">';
            html += '<div style="font-weight:800;font-size:13px;text-align:center;margin-bottom:8px;line-height:1.3;">9 MSI<br>sin intereses</div>';
            html += '<div style="font-size:11px;color:var(--vk-text-secondary);flex:1;line-height:1.6;">';
            html += '<div>Tu moto hoy, sin pagar todo de golpe</div>';
            html += '<div>&#10003; 9 pagos de ' + VkUI.formatPrecio(msiPago) + ' MXN</div>';
            html += '<div>&#10003; Sin intereses ni cargos ocultos</div>';
            html += '<div>&#10003; Cargo autom\u00e1tico cada mes</div>';
            html += '<div>&#10003; Sin tr\u00e1mites adicionales</div>';
            html += '</div>';
            html += '<button id="vk-resumen-pagar-msi" style="display:block;width:100%;margin-top:10px;padding:10px 4px;background:var(--vk-green-primary);color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer;">PAGAR PRIMER CARGO</button>';
            html += '</div>';
        }

        html += '</div>'; // end flex row

        // 5. Resumen al fondo
        html += '<div class="vk-summary" style="margin-top:4px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Resumen de tu compra</div>';
        html += '<div style="font-size:14px;line-height:1.9;">';
        html += '<div>\u2022 Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div>\u2022 Color: <strong>' + color + '</strong></div>';
        html += '<div>\u2022 Entrega en: <strong>' + ciudad + '</strong></div>';
        html += '<div>\u2022 Entrega estimada: <strong>' + diasEntrega + ' d\u00edas h\u00e1biles</strong> en tu ciudad</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 Asesor Voltika confirma la ubicaci\u00f3n exacta del centro autorizado entre 24 a 48 horas, h\u00e1biles despu\u00e9s del pago</div>';
        if (state.costoLogistico > 0) {
            html += '<div>\u2022 Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
        }
        html += '</div>';
        html += '<div style="border-top:1.5px solid var(--vk-border);margin:12px 0 10px;"></div>';
        html += '<div style="font-size:20px;font-weight:800;margin-bottom:4px;">Total a pagar hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">\u2022 o 9 pagos de <strong>' + VkUI.formatPrecio(msiPago) + ' MXN</strong> (9 MSI sin intereses)</div>';
        }
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

        // Contado/MSI: 9 MSI button
        jQuery(document).off('click', '#vk-resumen-pagar-msi');
        jQuery(document).on('click', '#vk-resumen-pagar-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(4); // Goes to Paso4A (Stripe)
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
