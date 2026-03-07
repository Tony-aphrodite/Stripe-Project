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
        var fechaEntrega = this._calcFechaEntrega();
        var img = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);

        var html = '';
        html += VkUI.renderBackButton(3);

        // Card logos header
        html += '<div style="text-align:center;padding:8px 0;">' + VkUI.renderCardLogos() + '</div>';

        html += '<div style="text-align:center;margin-bottom:4px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-muted);">\u00b7 PASO 4 \u00b7</div>';
        html += '<h2 class="vk-paso__titulo">Confirma tu forma de pago segura</h2>';
        html += '</div>';

        // Summary box
        html += '<div class="vk-card" style="padding:16px 20px;margin-bottom:16px;">';
        html += '<div style="font-weight:700;font-size:15px;margin-bottom:10px;">Resumen de tu compra</div>';

        html += '<div style="font-size:14px;margin-bottom:6px;">\u2022 Modelo: <strong>' + modelo.nombre + '</strong></div>';
        html += '<div style="font-size:14px;margin-bottom:6px;">\u2022 Color: <strong>' + (state.colorSeleccionado || modelo.colorDefault) + '</strong></div>';
        if (state.ciudad) {
            html += '<div style="font-size:14px;margin-bottom:6px;">\u2022 Entrega en: <strong>' + state.ciudad + ', ' + (state.estado || '') + '</strong></div>';
        }
        html += '<div style="font-size:14px;margin-bottom:6px;">\u2022 Entrega estimada: <strong>7 a 10 d\u00edas h\u00e1biles</strong> en tu ciudad</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">\u2022 Asesor Voltika confirma la ubicaci\u00f3n exacta del centro autorizado entre 24 a 48 horas, h\u00e1biles despu\u00e9s del pago</div>';

        if (state.costoLogistico > 0) {
            html += '<div style="font-size:14px;margin-bottom:8px;">Costo log\u00edstico: <strong>' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</strong></div>';
        }

        // Total
        html += '<div style="border-top:2px solid var(--vk-border);padding-top:10px;margin-top:8px;">';
        html += '<div style="font-size:18px;font-weight:800;">Total a pagar hoy: ' + VkUI.formatPrecio(total) + ' MXN</div>';
        if (modelo.tieneMSI) {
            html += '<div style="font-size:14px;color:var(--vk-text-secondary);margin-top:4px;">\u2022 o 9 pagos de <strong>' + VkUI.formatPrecio(msiPago) + ' MXN</strong> (9 MSI sin intereses)</div>';
        }
        html += '</div>';
        html += '</div>'; // end summary card

        // ── 2 Payment Buttons side by side ──
        html += '<div style="display:flex;gap:12px;margin-bottom:16px;">';

        // Pago contado
        html += '<div style="flex:1;border:2px solid var(--vk-border);border-radius:10px;padding:14px;text-align:center;">';
        html += '<div style="font-weight:800;font-size:15px;margin-bottom:6px;">Pago \u00fanico<br>100% seguro</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-bottom:10px;">';
        html += '\u2022 Pago protegido y encriptado<br>';
        html += '\u2022 Confirmaci\u00f3n bancaria al instante<br>';
        html += '\u2022 Atenci\u00f3n personalizada post-venta';
        html += '</div>';
        html += '<button class="vk-btn vk-btn--primary" id="vk-resumen-pagar-contado" style="width:100%;font-size:14px;">' +
            'PAGAR ' + VkUI.formatPrecio(total) + ' MXN</button>';
        html += '</div>';

        // 9 MSI
        if (modelo.tieneMSI) {
            html += '<div style="flex:1;border:2px solid var(--vk-border);border-radius:10px;padding:14px;text-align:center;">';
            html += '<div style="font-weight:800;font-size:15px;margin-bottom:6px;">9 MSI sin intereses</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-bottom:4px;">Tu moto hoy, sin pagar todo de golpe</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-bottom:10px;">';
            html += '\u2714 9 pagos fijos de ' + VkUI.formatPrecio(msiPago) + ' MXN<br>';
            html += '\u2714 Sin intereses ni cargos ocultos<br>';
            html += '\u2714 Cargo autom\u00e1tico seguro cada mes<br>';
            html += '\u2714 Sin tr\u00e1mites ni validaciones adicionales';
            html += '</div>';
            html += '<button class="vk-btn vk-btn--primary" id="vk-resumen-pagar-msi" style="width:100%;font-size:14px;">' +
                'PAGAR PRIMER CARGO</button>';
            html += '</div>';
        }

        html += '</div>'; // end flex

        // Trust badges
        html += '<div style="text-align:center;padding:12px;background:var(--vk-bg-light);border-radius:8px;">';
        html += '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:4px;">';
        html += '<span style="color:var(--vk-green-primary);">&#128737;</span>';
        html += '<strong style="font-size:14px;">Compra 100% segura</strong>';
        html += '</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Voltika procesa pagos con tecnolog\u00eda bancaria certificada.</div>';
        html += '<div style="font-size:11px;color:var(--vk-text-muted);margin-top:6px;">Los datos para facturar se te pedir\u00e1n posteriormente.</div>';
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
