/* ==========================================================================
   Voltika - Crédito: Tu Voltika está lista
   Confirmation screen before Truora identity verification.
   Customer sees the enganche amount + adjustment reason (if CONDICIONAL),
   accepts terms, then proceeds to identity check (NOT directly to payment).
   Flow: credito-resultado → credito-pago (THIS) → credito-identidad → credito-enganche
   ========================================================================== */

var PasoCreditoPago = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var enganchePct = state.enganchePorcentaje || 0.30;
        var plazoMeses  = state.plazoMeses || 12;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, plazoMeses);
        var enganche    = credito.enganche;

        // CONDICIONAL: flags set by paso-credito-resultado.js when the user's
        // selected terms were bumped (e.g. 30% → 40% enganche). Surfacing the
        // delta is critical — customer feedback 2026-04-23: the previous
        // screen silently showed no amount and no reason, so customers hit
        // "Pagar" without knowing how much or why it differed from their pick.
        var engAjustado   = !!state.engancheAjustado;
        var plazoAjustado = !!state.plazoAjustado;
        var engOrigPct    = state.enganchePorcentajeOriginal || null;
        var plazoOrig     = state.plazoMesesOriginal || null;

        var html = '';

        // Title
        html += '<h2 class="vk-cpago-title">Tu Voltika está lista</h2>';

        // Subtitle
        html += '<p class="vk-cpago-subtitle">';
        html += 'Realiza el <strong>pago de tu enganche</strong> para preparar tu Voltika y programar la entrega.';
        html += '</p>';

        // Info note — plain text, no box
        html += '<p style="text-align:center;font-size:13px;color:var(--vk-text-muted);margin-bottom:16px;">';
        html += 'Este pago se aplica directamente a tu financiamiento.';
        html += '</p>';

        // === Enganche amount — prominent display ===
        var ajustadoBadge = '';
        if (engAjustado) {
            ajustadoBadge =
                '<div style="display:inline-block;background:#FFF3E0;color:#E65100;' +
                'padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;' +
                'margin-bottom:8px;border:1px solid #FB8C00;">' +
                'MONTO AJUSTADO POR TU EVALUACIÓN CREDITICIA</div>';
        }

        html += '<div style="background:#F0F9F4;border:2px solid #1b5e3b;border-radius:14px;' +
                'padding:18px 16px;text-align:center;margin-bottom:16px;">';
        html += ajustadoBadge;
        html += '<div style="font-size:12px;font-weight:700;color:#1b5e3b;' +
                'text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Enganche a pagar</div>';
        html += '<div style="font-size:34px;font-weight:900;color:#1b5e3b;line-height:1.1;">' +
                VkUI.formatPrecio(enganche) +
                '<span style="font-size:14px;font-weight:700;margin-left:4px;">MXN</span></div>';
        html += '<div style="font-size:12px;color:#546e7a;margin-top:6px;">' +
                'Equivale al ' + Math.round(enganchePct * 100) + '% del precio de la moto</div>';

        if (engAjustado && engOrigPct) {
            html += '<div style="font-size:12px;color:#E65100;margin-top:10px;padding-top:10px;' +
                    'border-top:1px dashed #FB8C00;">';
            html += 'Originalmente elegiste <strong>' + Math.round(engOrigPct * 100) + '%</strong>. ' +
                    'Tu evaluación requiere un mínimo de <strong>' +
                    Math.round(enganchePct * 100) + '%</strong>.';
            html += '</div>';
        }
        if (plazoAjustado && plazoOrig) {
            html += '<div style="font-size:12px;color:#E65100;margin-top:6px;">';
            html += 'Plazo ajustado: ' + plazoOrig + ' meses → <strong>' +
                    plazoMeses + ' meses</strong>';
            html += '</div>';
        }
        html += '</div>';

        // Checkboxes — pre-checked, box styled inline so :checked CSS is not needed
        var checkedBox = '<span class="vk-cpago-check__box" style="background:#2e7d32;border-color:#2e7d32;color:white;font-size:14px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;">&#10003;</span>';

        html += '<div class="vk-cpago-checks">';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check1" checked>';
        html += checkedBox;
        html += '<span class="vk-cpago-check__text">Confirmo que deseo <strong>continuar</strong> con mi crédito Voltika por <strong>' +
                VkUI.formatPrecio(enganche) + ' MXN</strong></span>';
        html += '</label>';

        html += '<label class="vk-cpago-check">';
        html += '<input type="checkbox" id="vk-cpago-check2" checked>';
        html += checkedBox;
        html += '<span class="vk-cpago-check__text">Acepto los términos del <strong>crédito</strong> y registro de método de pago</span>';
        html += '</label>';

        html += '</div>';

        // Error
        html += '<div id="vk-cpago-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA button — routes based on credit evaluation result.
        //
        // CONDICIONAL (score bajo): customer brief 2026-04-24 —
        //   "we need the user sent to the part of enganche and plazo
        //    with restrictions". So we skip Truora and send to the
        //    Paso4B slider locked to the required min-enganche / max-plazo.
        //
        // PREAPROBADO: keep the legacy Method A flow (identity → enganche)
        //   because high-score customers get normal terms and full
        //   identity verification remains valuable.
        var isCondicional = !!state.modoCondicional;
        html += '<button id="vk-cpago-continuar" style="display:block;width:100%;padding:16px;background:#1b5e3b;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">';
        html += isCondicional ? 'AJUSTAR MI PLAN &rsaquo;' : 'CONFIRMAR Y CONTINUAR &rsaquo;';
        html += '</button>';

        // Hint explaining the next step so no surprise
        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
                (isCondicional
                    ? 'A continuación podrás ajustar tu enganche y plazo dentro de los límites aprobados.'
                    : 'A continuación verificaremos tu identidad (INE + CURP) antes del pago.') +
                '</p>';

        // Card logos footer
        html += '<div class="vk-cpago-footer">';
        html += 'Pago cifrado SSL &middot; ' + VkUI.renderCardLogos();
        html += '</div>';

        jQuery('#vk-credito-pago-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Toggle inline box style + button on change
        jQuery(document).off('change', '#vk-cpago-check1, #vk-cpago-check2');
        jQuery(document).on('change', '#vk-cpago-check1, #vk-cpago-check2', function() {
            var $box = jQuery(this).next('.vk-cpago-check__box');
            if (jQuery(this).is(':checked')) {
                $box.css({ background:'#2e7d32', 'border-color':'#2e7d32', color:'white' }).html('&#10003;');
            } else {
                $box.css({ background:'white', 'border-color':'#ccc', color:'transparent' }).html('');
            }
            var both = jQuery('#vk-cpago-check1').is(':checked') &&
                       jQuery('#vk-cpago-check2').is(':checked');
            jQuery('#vk-cpago-continuar').prop('disabled', !both)
                .css('opacity', both ? '1' : '0.5');
        });

        // Route based on approval flavor.
        //   CONDICIONAL → Paso4B slider in "restricted" mode. Skip Truora
        //       entirely per customer brief 2026-04-24 (low-score
        //       applicants self-gate via higher enganche + shorter plazo;
        //       additional identity friction is counterproductive and
        //       burns Truora quota).
        //   PREAPROBADO → credito-identidad (Truora). High-score customers
        //       qualify for full terms and deserve the strongest
        //       anti-fraud layer (identity + biometric + RENAPO).
        jQuery(document).off('click', '#vk-cpago-continuar');
        jQuery(document).on('click', '#vk-cpago-continuar', function() {
            if (self.app.state && self.app.state.modoCondicional) {
                self.app.irAPaso(4);  // Paso4B — restricted slider
            } else {
                self.app.irAPaso('credito-identidad');
            }
        });
    }
};
