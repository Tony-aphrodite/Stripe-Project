/* ==========================================================================
   Voltika - Crédito: Resultado de Evaluación
   Shows credit bureau + identity verification results
   ========================================================================== */

var PasoCreditoResultado = {

    init: function(app) {
        this.app = app;
        this._evaluarResultado();
        this.render();
        this.bindEvents();
    },

    /**
     * Re-run V3 pre-approval with real Círculo de Crédito data
     */
    _evaluarResultado: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var buro = state._buroResult || {};
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        // Re-evaluate with real bureau data
        state._resultadoFinal = PreaprobacionV3.evaluar({
            ingreso_mensual_est:   state._ingresoMensual || 10000,
            pago_semanal_voltika:  credito.pagoSemanal,
            score:                 buro.score || null,
            pago_mensual_buro:     buro.pagoMensual || 0,
            dpd90_flag:            buro.dpd90 || false,
            dpd_max:               buro.dpdMax || 0
        });
    },

    render: function() {
        var state     = this.app.state;
        var resultado = state._resultadoFinal || {};
        var buro      = state._buroResult || {};
        var truora    = state._truoraResult || {};

        var html = '';

        // If NO_VIABLE (skipped Truora), back goes to consentimiento
        var backTarget = (resultado.status === 'NO_VIABLE') ? 'credito-consentimiento' : 'credito-identidad';
        html += VkUI.renderBackButton(backTarget);

        html += '<h2 class="vk-paso__titulo">Resultado de evaluación</h2>';

        html += '<div class="vk-card">';
        html += '<div style="padding:24px 20px;">';

        // Identity verification result (only show if Truora was performed)
        if (resultado.status !== 'NO_VIABLE') {
            html += '<div style="margin-bottom:20px;">';
            html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);' +
                'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Identidad</div>';

            var idStatus = (truora && truora.status === 'approved') || truora.fallback;
            html += '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;' +
                'border-radius:8px;background:' + (idStatus ? 'var(--vk-green-soft)' : '#FFF3E0') + ';">';
            html += '<span style="font-size:24px;">' + (idStatus ? '&#9989;' : '&#9888;') + '</span>';
            html += '<div>';
            html += '<div style="font-weight:700;font-size:14px;">' +
                (idStatus ? 'Identidad verificada' : 'Verificación pendiente') + '</div>';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);">' +
                (idStatus ? 'Tu INE y selfie coinciden correctamente' : 'Se requiere revisión adicional por un asesor') +
                '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
        }

        // Credit bureau result
        html += '<div style="margin-bottom:20px;">';
        html += '<div style="font-size:13px;font-weight:700;color:var(--vk-text-secondary);' +
            'text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Historial crediticio</div>';

        var score = buro.score || null;
        var hasScore = score !== null && score !== undefined;

        if (hasScore) {
            html += '<div style="text-align:center;padding:14px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
            html += '<div style="font-size:12px;color:var(--vk-text-secondary);">Score crediticio</div>';
            html += '<div style="font-size:36px;font-weight:800;color:' + this._scoreColor(score) + ';">' + score + '</div>';
            html += '<div style="font-size:11px;color:var(--vk-text-muted);">de 850 puntos posibles</div>';
            html += '</div>';
        } else {
            html += '<div style="text-align:center;padding:14px;background:var(--vk-bg-light);border-radius:8px;margin-bottom:12px;">';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">' +
                'No se pudo obtener tu score. Se realizará una evaluación estimada.</div>';
            html += '</div>';
        }
        html += '</div>';

        // Divider
        html += '<div style="border-top:2px solid var(--vk-border);margin:16px 0;"></div>';

        // Final pre-approval result
        var status = resultado.status || 'CONDICIONAL_ESTIMADO';

        if (status === 'PREAPROBADO' || status === 'PREAPROBADO_ESTIMADO') {
            html += this._renderAprobado(resultado);
        } else if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
            html += this._renderCondicional(resultado);
        } else {
            html += this._renderNoViable(resultado);
        }

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-resultado-container').html(html);
    },

    _scoreColor: function(score) {
        if (score >= 480) return 'var(--vk-green-primary)';
        if (score >= 420) return '#F9A825';
        return '#C62828';
    },

    _renderAprobado: function(resultado) {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.30,
            state.plazoMeses || 12
        );

        var html = '';
        html += '<div style="background:var(--vk-green-soft);border-radius:10px;padding:20px;text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:40px;margin-bottom:8px;">&#10004;</div>';
        html += '<div style="font-size:20px;font-weight:800;color:var(--vk-green-primary);">¡Pre-aprobado!</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Cumples con los requisitos para Crédito Voltika</div>';
        html += '</div>';

        // Summary
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:14px;margin-bottom:16px;">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Enganche</span>';
        html += '<span style="font-size:14px;font-weight:700;">' +
            VkUI.formatPrecio(credito.enganche) + ' MXN</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Plazo</span>';
        html += '<span style="font-size:14px;font-weight:700;">' +
            (state.plazoMeses || 12) + ' meses</span>';
        html += '</div>';
        html += '<div style="display:flex;justify-content:space-between;">';
        html += '<span style="font-size:13px;color:var(--vk-text-secondary);">Pago semanal</span>';
        html += '<span style="font-size:16px;font-weight:800;color:var(--vk-green-primary);">' +
            VkUI.formatPrecio(credito.pagoSemanal) + '</span>';
        html += '</div>';
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-continuar">' +
            'Continuar &#9654;</button>';
        html += '<p style="text-align:center;font-size:11px;color:var(--vk-text-muted);margin-top:8px;">' +
            'Siguiente paso: confirmar entrega y pagar enganche.</p>';

        return html;
    },

    _renderCondicional: function(resultado) {
        var html = '';
        html += '<div style="background:#FFF8E1;border:1px solid #FFD54F;border-radius:10px;padding:20px;text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:40px;margin-bottom:8px;">&#9888;</div>';
        html += '<div style="font-size:20px;font-weight:800;color:#F9A825;">Aprobación condicional</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Puedes calificar ajustando el enganche y/o plazo</div>';
        html += '</div>';

        if (resultado.enganche_requerido_min) {
            html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<strong>Enganche mínimo requerido:</strong> ' +
                Math.round(resultado.enganche_requerido_min * 100) + '%';
            html += '</div>';
        }
        if (resultado.plazo_max_meses) {
            html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:12px;font-size:13px;">';
            html += '<strong>Plazo máximo:</strong> ' + resultado.plazo_max_meses + ' meses';
            html += '</div>';
        }

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-continuar">' +
            'Continuar con condiciones</button>';

        html += '<div style="text-align:center;margin-top:12px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-resultado-contado" ' +
            'style="font-size:13px;">Cambiar a Contado o MSI</button>';
        html += '</div>';

        return html;
    },

    _renderNoViable: function(resultado) {
        var html = '';
        html += '<div style="background:#FFEBEE;border:1px solid #EF9A9A;border-radius:10px;padding:20px;text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:40px;margin-bottom:8px;">&#10060;</div>';
        html += '<div style="font-size:20px;font-weight:800;color:#C62828;">No es posible el crédito</div>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-top:4px;">' +
            'Lo sentimos, no cumples los requisitos en este momento</div>';
        html += '</div>';

        html += '<p style="font-size:13px;color:var(--vk-text-secondary);text-align:center;margin-bottom:16px;">' +
            'Sin embargo, puedes adquirir tu Voltika de otras formas:</p>';

        html += '<div style="display:flex;gap:10px;margin-bottom:16px;">';
        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-contado" style="flex:1;">' +
            'Pago de contado</button>';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-resultado-msi" style="flex:1;">' +
            '9 MSI</button>';
        html += '</div>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);">' +
            'También puedes hablar con un asesor: <strong>ventas@voltika.com.mx</strong></p>';

        return html;
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-resultado-continuar');
        jQuery(document).on('click', '#vk-resultado-continuar', function() {
            self.app.state.creditoAprobado = true;
            self.app.irAPaso(3); // Back to delivery CP confirmation
        });

        jQuery(document).off('click', '#vk-resultado-contado');
        jQuery(document).on('click', '#vk-resultado-contado', function() {
            self.app.state.metodoPago = 'contado';
            self.app.irAPaso(3);
        });

        jQuery(document).off('click', '#vk-resultado-msi');
        jQuery(document).on('click', '#vk-resultado-msi', function() {
            self.app.state.metodoPago = 'msi';
            self.app.irAPaso(3);
        });
    }
};
