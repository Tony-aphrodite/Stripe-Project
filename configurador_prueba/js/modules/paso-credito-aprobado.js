/* ==========================================================================
   Voltika - Crédito: Aprobado Screen
   Shows "¡Felicidades! Tu crédito Voltika ya fue aprobado."
   Then user clicks "Ver mi plan de pagos" → credito-identidad (Truora)
   ========================================================================== */

var PasoCreditoAprobado = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;

        // Get delivery info — prefer state values from CP API (more accurate)
        var cpEntrega = state.codigoPostal || '';
        var ciudadEntrega = '';
        if (state.ciudad && state.estado) {
            ciudadEntrega = state.ciudad + ', ' + state.estado;
        } else {
            var cpInfo = cpEntrega && typeof VOLTIKA_CP !== 'undefined' ? VOLTIKA_CP._buscar(cpEntrega) : null;
            ciudadEntrega = cpInfo ? (cpInfo.ciudad + ', ' + cpInfo.estado) : '';
        }

        var html = '';

        // Blue gradient header
        html += '<div class="vk-aprobado-header">';
        html += '<div class="vk-aprobado-header__check" style="text-align:center;">';
        html += '<img src="' + (window.VK_BASE_PATH || '') + 'img/Archivo 4/last_elements-06.png" alt="Moto aprobada" style="width:180px;height:auto;margin:0 auto;display:block;">';
        html += '</div>';
        html += '<h2 class="vk-aprobado-header__title" style="color:#ffffff;font-size:32px;margin-top:16px;">\u00a1Felicidades!</h2>';
        html += '<p class="vk-aprobado-header__subtitle" style="font-size:20px;">Tu cr\u00e9dito Voltika ya est\u00e1 aprobado</p>';
        html += '<p style="color:rgba(255,255,255,0.9);font-size:15px;margin-top:8px;">Tu plan de pagos ya est\u00e1 listo y tu moto qued\u00f3 reservada para ti.</p>';
        html += '</div>';

        // White card area
        html += '<div class="vk-aprobado-body">';

        // Delivery info — show selected centro details
        var centro = state.centroEntrega;
        if (cpEntrega || centro) {
            html += '<div class="vk-aprobado-info">';
            html += '<div class="vk-aprobado-info__row">';
            html += '<span style="font-size:18px;flex-shrink:0;">&#128205;</span>';
            html += '<div style="font-size:14px;line-height:1.5;">';
            if (centro && centro.nombre) {
                html += 'Tu moto se entregar\u00e1 en <strong style="color:#039fe1;">' + centro.nombre + '</strong>';
                if (centro.direccion && centro.tipo !== 'cercano') {
                    html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:2px;">' + centro.direccion + '</div>';
                }
            } else {
                html += 'Tu moto se entregar\u00e1 en un <strong>punto Voltika autorizado</strong> cerca de ti';
            }
            html += '<div style="font-weight:700;margin-top:2px;">' + ciudadEntrega + (cpEntrega ? ' (CP ' + cpEntrega + ')' : '') + '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Truora steps preview
        html += '<div class="vk-aprobado-steps">';
        html += '<div class="vk-aprobado-steps__header">';
        html += '<span style="font-size:20px;">&#9201;</span>';
        html += '<strong style="font-size:16px;">Solo faltan 30 segundos para terminar</strong>';
        html += '</div>';
        html += '<p style="font-size:14px;color:var(--vk-text-secondary);margin-bottom:10px;">Para proteger tu cr\u00e9dito y evitar fraudes necesitamos confirmar tu identidad.</p>';
        var chk = '<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#039fe1;color:#fff;font-size:12px;flex-shrink:0;">&#10003;</span>';
        html += '<div class="vk-aprobado-steps__item" style="font-size:15px;margin-bottom:8px;">' + chk + ' Foto de tu INE</div>';
        html += '<div class="vk-aprobado-steps__item" style="font-size:15px;margin-bottom:8px;">' + chk + ' Selfie r\u00e1pida</div>';
        html += '<div class="vk-aprobado-steps__item" style="font-size:14px;">&#128274; Tu informaci\u00f3n est\u00e1 protegida y cifrada</div>';
        html += '</div>';

        // CTA
        html += '<button id="vk-aprobado-continuar" style="display:block;width:100%;padding:16px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;margin-top:16px;">CONTINUAR Y CONFIRMAR MI IDENTIDAD &rsaquo;</button>';
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:6px;">Tu plan de pagos se mostrar\u00e1 en el siguiente paso.</p>';

        // Trust badges — pill buttons
        html += '<div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid var(--vk-border-light);">';
        var badges = ['Sin aval', 'Sin papeleo', 'Proceso 100% digital'];
        for (var i = 0; i < badges.length; i++) {
            html += '<span style="display:inline-flex;align-items:center;gap:4px;background:#e8f7ff;color:#0288cc;' +
                'border:1px solid #b3e0f7;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;">' +
                '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;' +
                'border-radius:50%;background:#039fe1;color:#fff;font-size:9px;flex-shrink:0;">&#10003;</span>' +
                badges[i] + '</span>';
        }
        html += '</div>';

        // Advisor note
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:14px;line-height:1.5;">';
        html += 'Un asesor Voltika te contactar\u00e1 dentro de las pr\u00f3ximas <strong>48 horas</strong> para acompa\u00f1arte en la entrega de tu moto y resolver cualquier duda.';
        html += '</p>';

        html += '</div>'; // end body

        jQuery('#vk-credito-aprobado-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-aprobado-continuar');
        jQuery(document).on('click', '#vk-aprobado-continuar', function() {
            self.app.irAPaso('credito-identidad');
        });
    }
};
