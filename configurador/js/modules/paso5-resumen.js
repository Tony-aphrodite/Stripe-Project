/* ==========================================================================
   Voltika - PASO Resumen
   Order summary before payment (contado/MSI) or before confirm (crédito)
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
        var fechaEntrega = this._calcFechaEntrega();
        var img = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);
        var html = '';

        html += VkUI.renderBackButton(3);
        html += '<h2 class="vk-paso__titulo">Resumen de tu pedido</h2>';

        html += '<div class="vk-card">';
        html += '<div class="vk-card__imagen" style="max-height:160px;">' +
            '<img src="' + img + '" alt="' + modelo.nombre + '">' +
            '</div>';

        html += '<div style="padding:16px 20px;">';

        // Order detail rows
        html += '<table style="width:100%;font-size:14px;border-collapse:collapse;">';

        html += '<tr>' +
            '<td style="padding:7px 0;color:var(--vk-text-secondary);">Modelo</td>' +
            '<td style="text-align:right;font-weight:600;">' + modelo.nombre + '</td>' +
            '</tr>';

        html += '<tr>' +
            '<td style="padding:7px 0;color:var(--vk-text-secondary);">Color</td>' +
            '<td style="text-align:right;font-weight:600;">' +
            (state.colorSeleccionado || modelo.colorDefault) +
            '</td></tr>';

        html += '<tr>' +
            '<td style="padding:7px 0;color:var(--vk-text-secondary);">Forma de pago</td>' +
            '<td style="text-align:right;font-weight:600;">' +
            (metodo === 'credito' ? 'Cr\u00e9dito Voltika' :
             metodo === 'msi'     ? '9 MSI sin intereses' : 'Contado') +
            '</td></tr>';

        if (state.ciudad) {
            html += '<tr>' +
                '<td style="padding:7px 0;color:var(--vk-text-secondary);">Entrega en</td>' +
                '<td style="text-align:right;font-weight:600;">' +
                state.ciudad + ', ' + (state.estado || '') +
                '</td></tr>';
        }

        html += '<tr>' +
            '<td style="padding:7px 0;color:var(--vk-text-secondary);">Fecha estimada</td>' +
            '<td style="text-align:right;font-weight:600;">' + fechaEntrega + '</td>' +
            '</tr>';

        // Divider
        html += '<tr><td colspan="2"><div style="border-top:2px solid var(--vk-border);margin:10px 0;"></div></td></tr>';

        // Price section
        if (metodo === 'credito') {
            var enganchePct = state.enganchePorcentaje || 0.30;
            var plazo = state.plazoMeses || 12;
            var credito = VkCalculadora.calcular(modelo.precioContado, enganchePct, plazo);

            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Precio contado</td>' +
                '<td style="text-align:right;">' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</td>' +
                '</tr>';
            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Enganche (' + Math.round(enganchePct * 100) + '%)</td>' +
                '<td style="text-align:right;font-weight:700;">' + VkUI.formatPrecio(credito.enganche) + ' MXN</td>' +
                '</tr>';
            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Plazo</td>' +
                '<td style="text-align:right;font-weight:600;">' + plazo + ' meses</td>' +
                '</tr>';
            html += '<tr>' +
                '<td style="padding:5px 0;font-weight:700;font-size:15px;">Pago semanal</td>' +
                '<td style="text-align:right;font-weight:800;font-size:18px;color:var(--vk-green-primary);">' +
                VkUI.formatPrecio(credito.pagoSemanal) + '</td>' +
                '</tr>';
            html += '<tr>' +
                '<td colspan="2" style="padding:4px 0;font-size:12px;color:var(--vk-green-primary);">' +
                '&#10004; Flete incluido en Cr\u00e9dito Voltika</td>' +
                '</tr>';

        } else if (metodo === 'msi') {
            var total = modelo.precioContado + state.costoLogistico;
            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Moto</td>' +
                '<td style="text-align:right;">' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</td>' +
                '</tr>';
            if (state.costoLogistico > 0) {
                html += '<tr>' +
                    '<td style="padding:5px 0;color:var(--vk-text-secondary);">Costo log\u00edstico</td>' +
                    '<td style="text-align:right;">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</td>' +
                    '</tr>';
            }
            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Total</td>' +
                '<td style="text-align:right;font-weight:700;">' + VkUI.formatPrecio(total) + ' MXN</td>' +
                '</tr>';
            html += '<tr>' +
                '<td style="padding:5px 0;font-weight:700;font-size:15px;">9 pagos de</td>' +
                '<td style="text-align:right;font-weight:800;font-size:18px;color:var(--vk-green-primary);">' +
                VkUI.formatPrecio(Math.round(modelo.precioMSI)) + ' /mes</td>' +
                '</tr>';

        } else { // contado
            var totalC = modelo.precioContado + state.costoLogistico;
            html += '<tr>' +
                '<td style="padding:5px 0;color:var(--vk-text-secondary);">Moto</td>' +
                '<td style="text-align:right;">' + VkUI.formatPrecio(modelo.precioContado) + ' MXN</td>' +
                '</tr>';
            if (state.costoLogistico > 0) {
                html += '<tr>' +
                    '<td style="padding:5px 0;color:var(--vk-text-secondary);">Costo log\u00edstico</td>' +
                    '<td style="text-align:right;">' + VkUI.formatPrecio(state.costoLogistico) + ' MXN</td>' +
                    '</tr>';
            }
            html += '<tr>' +
                '<td style="padding:5px 0;font-weight:700;font-size:15px;">Total</td>' +
                '<td style="text-align:right;font-weight:800;font-size:18px;color:var(--vk-green-primary);">' +
                VkUI.formatPrecio(totalC) + ' MXN</td>' +
                '</tr>';
        }
        html += '</table>';

        // Delivery banner
        html += VkUI.renderBanner();

        // CTA
        if (metodo === 'credito' && state.creditoAprobado) {
            // Second visit (post-verification): go to enganche payment
            html += '<button class="vk-btn vk-btn--primary" id="vk-resumen-continuar" data-target="credito-enganche">' +
                '&#128274; Pagar enganche y continuar</button>';
            html += '<p class="vk-card__footer-note">' +
                'Ser\u00e1s dirigido al formulario de pago seguro con Stripe.' +
                '</p>';
        } else if (metodo === 'credito') {
            // First visit: go to credit calculator
            html += '<button class="vk-btn vk-btn--primary" id="vk-resumen-continuar" data-target="4">' +
                '&#10004; Confirmar solicitud</button>';
            html += '<p class="vk-card__footer-note">' +
                'Siguiente paso: calculadora de cr\u00e9dito y pre-aprobaci\u00f3n.' +
                '</p>';
        } else {
            html += '<button class="vk-btn vk-btn--primary" id="vk-resumen-continuar">' +
                '&#128274; Proceder al pago</button>';
            html += '<p class="vk-card__footer-note">' +
                'Ser\u00e1s dirigido al formulario de pago seguro con Stripe.' +
                '</p>';
        }

        html += VkUI.renderTrustBadges();
        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-resumen-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-resumen-continuar');
        jQuery(document).on('click', '#vk-resumen-continuar', function() {
            var target = jQuery(this).data('target');
            if (target === 'credito-enganche') {
                self.app.irAPaso('credito-enganche');
            } else {
                self.app.irAPaso(4); // credit → Paso4B, contado/msi → Paso4A (Stripe)
            }
        });
    }
};
