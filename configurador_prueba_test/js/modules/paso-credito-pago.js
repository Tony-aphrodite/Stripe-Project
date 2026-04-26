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

        // Three-way render switch (customer brief 2026-04-26 v2): same
        // template for ALL credit results, content varies by status.
        //   PREAPROBADO  → enganche box (low %), CONFIRMAR → Truora
        //   CONDICIONAL  → enganche box (50%), MONTO AJUSTADO badge,
        //                  AJUSTAR → Paso4B (locked 50%/12)
        //   NO_VIABLE    → alt-payment mini cards (Contado / 9 MSI),
        //                  VER OTRAS FORMAS → paso 3 with metodoPago set
        var status = (state._resultadoFinal && state._resultadoFinal.status) || '';
        var isRejected   = (status === 'NO_VIABLE');
        var isCondicional = !!state.modoCondicional;

        var html = '';

        // Common title (positive framing for all 3 cases)
        html += '<h2 class="vk-cpago-title">Tu Voltika está lista</h2>';

        if (isRejected) {
            html += this._renderRejectedBody(modelo);
        } else {
            html += this._renderApprovedBody(modelo, state, isCondicional);
        }

        // Card logos footer (common to all three cases)
        html += '<div class="vk-cpago-footer">';
        html += 'Pago cifrado SSL &middot; ' + VkUI.renderCardLogos();
        html += '</div>';

        jQuery('#vk-credito-pago-container').html(html);
    },

    /**
     * Body for PREAPROBADO + CONDICIONAL (the user IS getting credit).
     * Shows the enganche amount box, two acceptance checkboxes, and a CTA
     * that routes based on whether terms were adjusted.
     */
    _renderApprovedBody: function(modelo, state, isCondicional) {
        var enganchePct = state.enganchePorcentaje || 0.30;
        var plazoMeses  = state.plazoMeses || 12;
        var credito     = VkCalculadora.calcular(modelo.precioContado, enganchePct, plazoMeses);
        var enganche    = credito.enganche;

        var engAjustado   = !!state.engancheAjustado;
        var plazoAjustado = !!state.plazoAjustado;
        var engOrigPct    = state.enganchePorcentajeOriginal || null;
        var plazoOrig     = state.plazoMesesOriginal || null;

        var html = '';

        html += '<p class="vk-cpago-subtitle">';
        html += 'Realiza el <strong>pago de tu enganche</strong> para preparar tu Voltika y programar la entrega.';
        html += '</p>';
        html += '<p style="text-align:center;font-size:13px;color:var(--vk-text-muted);margin-bottom:16px;">';
        html += 'Este pago se aplica directamente a tu financiamiento.';
        html += '</p>';

        // Enganche amount box
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

        // Checkboxes
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

        html += '<div id="vk-cpago-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button id="vk-cpago-continuar" style="display:block;width:100%;padding:16px;background:#1b5e3b;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.5px;">';
        html += isCondicional ? 'AJUSTAR MI PLAN &rsaquo;' : 'CONFIRMAR Y CONTINUAR &rsaquo;';
        html += '</button>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
                (isCondicional
                    ? 'A continuación podrás ajustar tu enganche y plazo dentro de los límites aprobados.'
                    : 'A continuación verificaremos tu identidad (INE + CURP) antes del pago.') +
                '</p>';

        return html;
    },

    /**
     * Body for NO_VIABLE — credit was not approved, but Voltika is still
     * available via contado or 9 MSI. Two clickable mini cards replace
     * the enganche box; tapping either one routes to paso 3 (delivery)
     * with metodoPago pre-set.
     */
    _renderRejectedBody: function(modelo) {
        var precio    = modelo.precioContado || 0;
        var precioMSI = Math.round(precio / 9);
        var fmt = VkUI.formatPrecio;
        var html = '';

        html += '<p class="vk-cpago-subtitle">';
        html += 'Tu solicitud de crédito <strong>no aplica esta vez</strong>, pero puedes llevarte tu Voltika hoy con estas formas de pago:';
        html += '</p>';

        // Two payment mini cards (replace the green enganche box)
        function payCard(id, badge, badgeColor, title, amount, sub){
            return '<button type="button" class="vk-cpago-altcard" id="'+id+'" style="'+
                'display:block;width:100%;background:#fff;border:2px solid #e5e7eb;border-radius:12px;'+
                'padding:14px 16px;margin-bottom:10px;cursor:pointer;text-align:left;'+
                'transition:border-color .15s,box-shadow .15s;">'+
                '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">'+
                    '<div style="flex:1;min-width:0;">'+
                        '<div style="display:inline-block;background:'+badgeColor+';color:#fff;font-size:10px;font-weight:800;padding:3px 8px;border-radius:10px;letter-spacing:0.5px;margin-bottom:6px;">'+badge+'</div>'+
                        '<div style="font-size:14px;font-weight:800;color:#111;line-height:1.2;">'+title+'</div>'+
                        '<div style="font-size:22px;font-weight:900;color:#1b5e3b;line-height:1.1;margin-top:4px;">'+amount+'</div>'+
                        '<div style="font-size:11.5px;color:#5b6b7a;margin-top:4px;">'+sub+'</div>'+
                    '</div>'+
                    '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#039fe1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 18 15 12 9 6"/></svg>'+
                '</div>'+
                '</button>';
        }

        html += '<div style="margin-bottom:16px;">';
        html += payCard(
            'vk-cpago-rej-contado',
            'PAGO ÚNICO',
            '#1b5e3b',
            'Pago al contado',
            fmt(precio) + ' MXN',
            'Tarjeta, SPEI u OXXO · Se aparta al instante'
        );
        html += payCard(
            'vk-cpago-rej-msi',
            '9 MSI SIN INTERESES',
            '#039fe1',
            '9 mensualidades sin intereses',
            fmt(precioMSI) + ' / mes',
            'Solo con tarjetas participantes · Primer pago hoy'
        );
        html += '</div>';

        html += '<p style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-top:8px;">' +
                'Selecciona una opción para continuar al pago seguro.' +
                '</p>';

        return html;
    },

    bindEvents: function() {
        var self = this;
        var status = (self.app.state._resultadoFinal && self.app.state._resultadoFinal.status) || '';
        var isRejected = (status === 'NO_VIABLE');

        if (isRejected) {
            // NO_VIABLE — alt-payment mini cards. Each one sets metodoPago
            // and routes to paso 3 (delivery) so the user picks a Punto
            // Voltika before checkout.
            jQuery(document).off('click', '#vk-cpago-rej-contado');
            jQuery(document).on('click', '#vk-cpago-rej-contado', function() {
                self.app.state.metodoPago = 'contado';
                self.app.state.creditoAprobado = false;
                self.app.irAPaso(3);
            });

            jQuery(document).off('click', '#vk-cpago-rej-msi');
            jQuery(document).on('click', '#vk-cpago-rej-msi', function() {
                self.app.state.metodoPago = 'msi';
                self.app.state.creditoAprobado = false;
                self.app.irAPaso(3);
            });

            jQuery(document).off('mouseenter', '.vk-cpago-altcard').on('mouseenter', '.vk-cpago-altcard', function(){
                jQuery(this).css({'border-color':'#039fe1','box-shadow':'0 2px 8px rgba(3,159,225,.15)'});
            });
            jQuery(document).off('mouseleave', '.vk-cpago-altcard').on('mouseleave', '.vk-cpago-altcard', function(){
                jQuery(this).css({'border-color':'#e5e7eb','box-shadow':''});
            });
            return;
        }

        // PREAPROBADO + CONDICIONAL — checkbox toggle + Continuar routing.
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

        // CONDICIONAL → Paso4B slider locked at 50%/12.
        // PREAPROBADO → credito-identidad (Truora) → Stripe enganche.
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
