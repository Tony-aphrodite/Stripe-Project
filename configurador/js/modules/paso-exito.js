/* ==========================================================================
   Voltika - Éxito / Resumen de Compra
   Final confirmation screen — sends data to email + Zoho
   ========================================================================== */

var PasoExito = {

    init: function(app) {
        this.app = app;
        this._enviarConfirmacion();
        this.render();
    },

    _calcFechaEntrega: function() {
        var d = new Date();
        d.setDate(d.getDate() + 15);
        var m = ['enero','febrero','marzo','abril','mayo','junio',
                 'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return d.getDate() + ' de ' + m[d.getMonth()] + ' de ' + d.getFullYear();
    },

    _nPedido: function() {
        return 'VK-' + Date.now().toString(36).toUpperCase().slice(-6);
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var metodo = state.metodoPago;
        var fechaEntrega = this._calcFechaEntrega();

        var html = '';

        // Success header
        html += '<div style="text-align:center;padding:24px 0 16px;">';
        html += '<div style="font-size:64px;">&#127881;</div>';
        html += '<h2 style="font-size:24px;font-weight:800;color:var(--vk-green-primary);margin:12px 0 4px;">' +
            '\u00a1Solicitud confirmada!</h2>';
        if (metodo !== 'credito') {
            html += '<p style="font-size:15px;color:var(--vk-text-secondary);">' +
                '\u00a1Tu Voltika ya es tuya!</p>';
        } else {
            html += '<p style="font-size:15px;color:var(--vk-text-secondary);">' +
                'Tu solicitud de cr\u00e9dito ha sido registrada.</p>';
        }
        html += '</div>';

        html += '<div class="vk-card">';
        html += '<div style="padding:16px 20px;">';

        // Order summary
        if (modelo) {
            var img = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);
            html += '<div class="vk-card__imagen" style="max-height:140px;">' +
                '<img src="' + img + '" alt="' + modelo.nombre + '">' +
                '</div>';
        }

        html += '<table style="width:100%;font-size:14px;border-collapse:collapse;margin-top:12px;">';

        if (modelo) {
            html += '<tr><td style="padding:6px 0;color:var(--vk-text-secondary);">Modelo</td>' +
                '<td style="text-align:right;font-weight:600;">' + modelo.nombre + '</td></tr>';
            html += '<tr><td style="padding:6px 0;color:var(--vk-text-secondary);">Color</td>' +
                '<td style="text-align:right;font-weight:600;">' +
                (state.colorSeleccionado || modelo.colorDefault) + '</td></tr>';
        }

        html += '<tr><td style="padding:6px 0;color:var(--vk-text-secondary);">Forma de pago</td>' +
            '<td style="text-align:right;font-weight:600;">' +
            (metodo === 'credito' ? 'Cr\u00e9dito Voltika' :
             metodo === 'msi'     ? '9 MSI sin intereses' : 'Contado') +
            '</td></tr>';

        if (state.ciudad) {
            html += '<tr><td style="padding:6px 0;color:var(--vk-text-secondary);">Entrega en</td>' +
                '<td style="text-align:right;font-weight:600;">' +
                state.ciudad + (state.estado ? ', ' + state.estado : '') + '</td></tr>';
        }

        html += '<tr><td style="padding:6px 0;color:var(--vk-text-secondary);">Fecha estimada</td>' +
            '<td style="text-align:right;font-weight:600;">' + fechaEntrega + '</td></tr>';

        if (state.totalPagado && metodo !== 'credito') {
            html += '<tr><td colspan="2">' +
                '<div style="border-top:2px solid var(--vk-border);margin:8px 0;"></div></td></tr>';
            html += '<tr><td style="font-weight:700;">Total pagado</td>' +
                '<td style="text-align:right;font-weight:800;font-size:16px;color:var(--vk-green-primary);">' +
                VkUI.formatPrecio(state.totalPagado) + ' MXN</td></tr>';
        }

        html += '</table>';

        html += '<div style="border-top:1px solid var(--vk-border);margin:16px 0;"></div>';

        // Confirmation info
        if (state.email) {
            html += '<div style="background:var(--vk-green-soft);border-radius:8px;padding:12px;' +
                'margin-bottom:12px;font-size:13px;">';
            html += '<div style="font-weight:700;margin-bottom:4px;">&#9993; Confirmaci\u00f3n enviada a:</div>';
            html += '<div>' + state.email + '</div>';
            html += '</div>';
        }

        if (metodo === 'credito') {
            html += '<div style="background:#E3F2FD;border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<div style="font-weight:700;color:#1565C0;margin-bottom:6px;">' +
                '&#128241; Un asesor Voltika te contactar\u00e1 pronto</div>';
            html += '<div style="color:#555;">Te contactaremos por WhatsApp al ' +
                (state.telefono ? '+52 ' + state.telefono : 'n\u00famero registrado') +
                ' dentro de las pr\u00f3ximas 24-48 horas para coordinar tu enganche y entrega.</div>';
            html += '</div>';
        } else {
            html += '<div style="background:#E3F2FD;border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<div style="font-weight:700;color:#1565C0;margin-bottom:6px;">' +
                '&#128241; \u00bfQu\u00e9 sigue?</div>';
            html += '<div style="color:#555;">Un asesor Voltika confirmar\u00e1 el Centro de entrega ' +
                'm\u00e1s cercano a ti en 24-48 horas v\u00eda WhatsApp.</div>';
            html += '</div>';
        }

        html += VkUI.renderTrustBadges();

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-exito-container').html(html);
    },

    _enviarConfirmacion: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!state.email && !state.nombre) return;

        jQuery.ajax({
            url: 'php/confirmar-pedido.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:    state.nombre,
                email:     state.email,
                telefono:  state.telefono,
                modelo:    modelo ? modelo.nombre : '',
                color:     state.colorSeleccionado || '',
                metodoPago: state.metodoPago,
                ciudad:    state.ciudad,
                estado:    state.estado,
                cp:        state.codigoPostal,
                total:     state.totalPagado || 0,
                credito:   state.metodoPago === 'credito' ? {
                    enganchePct: state.enganchePorcentaje,
                    plazoMeses:  state.plazoMeses
                } : null
            })
            // Fire and forget — no need to await response
        });
    }
};
