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

        // Re-evaluate with real bureau data. IMPORTANT: forward the
        // tri-state person_found signal so this re-run cannot flip a
        // server-side rejection (404.1 "identidad no encontrada") into an
        // approval. Without this, a fake identity could be rejected by
        // preaprobacion-v3.php and then silently re-approved here because
        // `score=null` alone would send it into _evaluarSinCirculo().
        state._resultadoFinal = PreaprobacionV3.evaluar({
            ingreso_mensual_est:   state._ingresoMensual || 10000,
            pago_semanal_voltika:  credito.pagoSemanal,
            score:                 buro.score || null,
            pago_mensual_buro:     buro.pagoMensual || 0,
            dpd90_flag:            buro.dpd90 || false,
            dpd_max:               buro.dpdMax || 0,
            person_found:          (buro.person_found === undefined) ? null : buro.person_found
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

        // Final pre-approval result. Fail-closed: if resultado is somehow
        // missing status (edge case where an early return bypassed scoring),
        // render NO_VIABLE instead of silently approving. The previous
        // default 'CONDICIONAL_ESTIMADO' is what let fake-identity flows
        // land on an approval-looking screen.
        var status = resultado.status || 'NO_VIABLE';

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

        html += '<div style="background:#FFF8E1;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#5D4037;">';
        html += '&#9888; Al continuar, tu enganche y plazo ser\u00e1n ajustados autom\u00e1ticamente a los m\u00ednimos requeridos.';
        html += '</div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-continuar">' +
            'Continuar con cr\u00e9dito condicional</button>';

        html += '<div style="text-align:center;margin-top:12px;">';
        html += '<button class="vk-btn vk-btn--secondary" id="vk-resultado-contado" ' +
            'style="font-size:13px;">Pagar con tarjeta (Stripe)</button>';
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
            'Sin embargo, puedes adquirir tu Voltika pagando con tarjeta:</p>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-resultado-contado" style="width:100%;margin-bottom:10px;">' +
            '&#128179; Pagar con tarjeta (Stripe)</button>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);">' +
            'También puedes hablar con un asesor: <strong>ventas@voltika.com.mx</strong></p>';

        return html;
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-resultado-continuar');
        jQuery(document).on('click', '#vk-resultado-continuar', function() {
            var resultado = self.app.state._resultadoFinal || {};
            var status    = resultado.status || '';

            self.app.state.creditoAprobado = true;

            // CONDICIONAL: enforce required minimum enganche and maximum plazo
            if (status === 'CONDICIONAL' || status === 'CONDICIONAL_ESTIMADO') {
                if (resultado.enganche_requerido_min) {
                    var minEnganche = resultado.enganche_requerido_min;
                    if (!self.app.state.enganchePorcentaje || self.app.state.enganchePorcentaje < minEnganche) {
                        self.app.state.enganchePorcentaje = minEnganche;
                    }
                }
                if (resultado.plazo_max_meses) {
                    var maxPlazo = resultado.plazo_max_meses;
                    if (!self.app.state.plazoMeses || self.app.state.plazoMeses > maxPlazo) {
                        self.app.state.plazoMeses = maxPlazo;
                    }
                }
            }

            self.app.irAPaso('credito-pago'); // Confirmation screen before Stripe payment
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
