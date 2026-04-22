/* ==========================================================================
   Voltika - Crédito Screen 11: Aceptación de acuerdos
   Per Dibujo.pdf: 2 checkboxes + 6-digit OTP input (individual boxes)
   ========================================================================== */

var PasoCreditoConsentimiento = {

    _otpCooldown: false,

    init: function(app) {
        this.app = app;
        this._otpCooldown = false;
        this.render();
        this.bindEvents();
        this._enviarOTPInicial();
    },

    _enviarOTPInicial: function() {
        var self = this;
        var tel = self.app.state.telefono;
        if (!tel) return;
        if (self._otpCooldown) return;
        self._otpCooldown = true;
        // Start cooldown timer on resend button
        var $resend = jQuery('#vk-cons-reenviar');
        var sec = 60;
        if ($resend.length) {
            $resend.prop('disabled', true);
            var tmr = setInterval(function() {
                sec--;
                $resend.text('Reenviar en ' + sec + 's');
                if (sec <= 0) {
                    clearInterval(tmr);
                    self._otpCooldown = false;
                    $resend.prop('disabled', false).text('Reenviar');
                }
            }, 1000);
        } else {
            setTimeout(function() { self._otpCooldown = false; }, 60000);
        }

        jQuery.ajax({
            url: 'php/enviar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            xhrFields: { withCredentials: true },
            data: JSON.stringify({ telefono: tel, nombre: self.app.state.nombre || '' }),
            success: function(res) {
                if (res && res.testCode) {
                    self.app.state._otpTestCode = res.testCode;
                    var $hint = jQuery('#vk-cons-test-hint');
                    if ($hint.length) {
                        $hint.html('&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong>').show();
                    } else {
                        jQuery('.vk-otp-box').first().closest('div').before(
                            '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                            '&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong></div>'
                        );
                    }
                }
            }
        });
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-ingresos');
        html += VkUI.renderCreditoStepBar(4);

        // Title (centered, large)
        html += '<h2 class="vk-paso__titulo" style="text-align:center;font-size:22px;line-height:1.3;">Verifica tu n\u00famero para ver tu resultado</h2>';
        html += '<p class="vk-paso__subtitulo" style="text-align:center;">Te enviamos un c\u00f3digo por SMS para confirmar tu identidad.</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        // Phone display
        var telDisplay = state.telefono
            ? ('+52 ' + state.telefono.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3'))
            : '';
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);">C\u00f3digo enviado a</div>';
        html += '<div style="font-size:16px;font-weight:700;">' + telDisplay + '</div>';
        html += '</div>';

        // Test code hint
        if (state._otpTestCode) {
            html += '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                '&#128161; C\u00f3digo de prueba: <strong>' + state._otpTestCode + '</strong></div>';
        }

        // 6 individual OTP boxes
        html += '<div style="display:flex;gap:8px;justify-content:center;margin-bottom:8px;">';
        for (var i = 0; i < 6; i++) {
            html += '<input type="text" class="vk-otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" ' +
                'style="width:44px;height:52px;text-align:center;font-size:24px;font-weight:700;' +
                'border:2px solid #e5e7eb;border-radius:8px;outline:none;' +
                'transition:border-color 0.15s;-moz-appearance:textfield;" ' +
                'data-index="' + i + '">';
        }
        html += '</div>';

        // Timer hint
        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-bottom:4px;">' +
            '&#9201; Esto toma menos de 10 segundos' +
            '</div>';

        // Resend link
        html += '<div style="text-align:center;font-size:12px;color:var(--vk-text-muted);margin-bottom:16px;">' +
            '\u00bfNo lleg\u00f3 el c\u00f3digo? ' +
            '<button id="vk-cons-reenviar" style="background:none;border:none;padding:0;color:#039fe1;font-size:12px;cursor:pointer;text-decoration:underline;">Reenviar</button>' +
            '</div>';

        html += '<div style="border-top:1px solid var(--vk-border);margin:12px 0;"></div>';

        // Checkbox 1: TyC
        html += '<div class="vk-checkbox-group" style="margin-bottom:12px;display:flex;gap:10px;align-items:flex-start;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-cons-tyc" style="margin-top:3px;flex-shrink:0;">';
        html += '<label class="vk-checkbox-label" for="vk-cons-tyc" style="font-size:13px;">' +
            'Al continuar aceptas los <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" rel="noopener" style="color:#039fe1;">T\u00e9rminos y condiciones</a>, ' +
            'las <a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" rel="noopener" style="color:#039fe1;">cl\u00e1usulas de medios electr\u00f3nicos</a> ' +
            'y autorizas la consulta de tu reporte de cr\u00e9dito.' +
            '</label>';
        html += '</div>';

        // Checkbox 2: Aviso de privacidad
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-cons-buro" style="margin-top:3px;flex-shrink:0;">';
        html += '<label class="vk-checkbox-label" for="vk-cons-buro" style="font-size:13px;">' +
            'Estoy de acuerdo con el <a href="https://voltika.mx/docs/privacidad_2026.pdf" target="_blank" style="color:#039fe1;">aviso de privacidad</a>.' +
            '</label>';
        html += '</div>';

        // Error
        html += '<div id="vk-cons-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-cons-evaluar">' +
            '<span id="vk-cons-label">VER MI RESULTADO \u2192</span>' +
            '<span id="vk-cons-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Evaluando...</span>' +
            '</button>';

        // Trust badges
        html += '<div class="vk-trust" style="margin-top:12px;">';
        html += '<div><span class="vk-check vk-check--sm"></span> Informaci\u00f3n protegida</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> Resultado en segundos</div>';
        html += '</div>';

        html += '</div>'; // end card

        jQuery('#vk-credito-consentimiento-container').html(html);
    },

    _getOTPValue: function() {
        var code = '';
        jQuery('.vk-otp-box').each(function() {
            code += jQuery(this).val();
        });
        return code;
    },

    _updateCTA: function() {
        var otp  = this._getOTPValue();
        var tyc  = jQuery('#vk-cons-tyc').is(':checked');
        var buro = jQuery('#vk-cons-buro').is(':checked');
        var ready = otp.length === 6 && tyc && buro;
        jQuery('#vk-cons-evaluar').css('opacity', ready ? '1' : '0.6');
    },

    bindEvents: function() {
        var self = this;

        jQuery(document)
            .off('keyup',   '.vk-otp-box')
            .off('keydown', '.vk-otp-box')
            .off('paste',   '.vk-otp-box')
            .off('focus',   '.vk-otp-box')
            .off('change',  '#vk-cons-tyc')
            .off('change',  '#vk-cons-buro')
            .off('click',   '#vk-cons-evaluar')
            .off('click',   '#vk-cons-reenviar');

        // OTP box: numeric input + auto-advance
        jQuery(document).on('keydown', '.vk-otp-box', function(e) {
            var $this = jQuery(this);
            var idx   = parseInt($this.data('index'));

            // Allow: backspace, delete, tab, arrows
            if (e.key === 'Backspace') {
                if ($this.val() === '' && idx > 0) {
                    jQuery('.vk-otp-box[data-index="' + (idx - 1) + '"]').val('').focus();
                } else {
                    $this.val('');
                }
                self._updateCTA();
                e.preventDefault();
                return;
            }
            // Block non-numeric
            if (!/^[0-9]$/.test(e.key) && !['Tab','ArrowLeft','ArrowRight'].includes(e.key)) {
                e.preventDefault();
            }
        });

        jQuery(document).on('keyup', '.vk-otp-box', function() {
            var $this = jQuery(this);
            var idx   = parseInt($this.data('index'));
            var val   = $this.val().replace(/\D/g, '');
            $this.val(val.slice(-1)); // keep only last digit
            if (val && idx < 5) {
                jQuery('.vk-otp-box[data-index="' + (idx + 1) + '"]').focus();
            }
            self._updateCTA();
        });

        // Paste: fill all boxes from pasted value
        jQuery(document).on('paste', '.vk-otp-box', function(e) {
            e.preventDefault();
            var clipData = e.originalEvent.clipboardData || (e.originalEvent && e.originalEvent['clipboardData']);
            var pasted = clipData ? clipData.getData('text').replace(/\D/g, '').slice(0, 6) : '';
            jQuery('.vk-otp-box').each(function(i) {
                jQuery(this).val(pasted[i] || '');
            });
            if (pasted.length > 0) {
                var focusIdx = Math.min(pasted.length, 5);
                jQuery('.vk-otp-box[data-index="' + focusIdx + '"]').focus();
            }
            self._updateCTA();
        });

        // Focus: highlight box
        jQuery(document).on('focus', '.vk-otp-box', function() {
            jQuery(this).css('border-color', '#039fe1');
        });
        jQuery(document).on('blur', '.vk-otp-box', function() {
            jQuery(this).css('border-color', jQuery(this).val() ? '#039fe1' : '#e5e7eb');
        });

        jQuery(document).on('change', '#vk-cons-tyc, #vk-cons-buro', function() {
            self._updateCTA();
            jQuery('#vk-cons-checkbox-error').hide();
            // NIP-CIEC: capture first moment both consents are checked
            var tyc  = jQuery('#vk-cons-tyc').is(':checked');
            var buro = jQuery('#vk-cons-buro').is(':checked');
            if (tyc && buro && !self.app.state._fechaAprobacionConsulta) {
                var now = new Date();
                var pad = function(n) { return (n < 10 ? '0' : '') + n; };
                self.app.state._fechaAprobacionConsulta =
                    now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
                self.app.state._horaAprobacionConsulta =
                    pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
            }
        });

        jQuery(document).on('click', '#vk-cons-evaluar', function() {
            var otp  = self._getOTPValue();
            var tyc  = jQuery('#vk-cons-tyc').is(':checked');
            var buro = jQuery('#vk-cons-buro').is(':checked');

            if (otp.length !== 6) {
                jQuery('#vk-cons-error').text('Ingresa el c\u00f3digo de 6 d\u00edgitos.').show();
                return;
            }
            jQuery('#vk-cons-error').hide();

            if (!tyc || !buro) {
                if (!jQuery('#vk-cons-checkbox-error').length) {
                    jQuery(this).before('<div id="vk-cons-checkbox-error" style="color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;text-align:center;font-weight:600;">Debes aceptar ambas casillas para continuar.</div>');
                } else {
                    jQuery('#vk-cons-checkbox-error').show();
                }
                return;
            }
            jQuery('#vk-cons-checkbox-error').hide();
            self._evaluar();
        });

        // Resend OTP
        jQuery(document).on('click', '#vk-cons-reenviar', function() {
            if (self._otpCooldown) return;
            self._otpCooldown = true;
            var tel = self.app.state.telefono;
            var $btn = jQuery(this);
            $btn.prop('disabled', true);
            var sec = 60;
            var tmr = setInterval(function() {
                sec--;
                $btn.text('Reenviar en ' + sec + 's');
                if (sec <= 0) {
                    clearInterval(tmr);
                    self._otpCooldown = false;
                    $btn.prop('disabled', false).text('Reenviar');
                }
            }, 1000);
            jQuery.ajax({
                url: 'php/enviar-otp.php',
                method: 'POST',
                contentType: 'application/json',
                xhrFields: { withCredentials: true },
                data: JSON.stringify({ telefono: tel, nombre: self.app.state.nombre || '' }),
                success: function(res) {
                    if (res && res.testCode) {
                        self.app.state._otpTestCode = res.testCode;
                        var $hint = jQuery('#vk-cons-test-hint');
                        if ($hint.length) {
                            $hint.html('&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong>').show();
                        } else {
                            jQuery('.vk-otp-box').first().closest('div').before(
                                '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                                '&#128161; C\u00f3digo de prueba: <strong>' + res.testCode + '</strong></div>'
                            );
                        }
                    }
                    jQuery('#vk-cons-error').html('&#10004; C\u00f3digo reenviado.').css({'color':'var(--vk-green-primary)','background':'var(--vk-green-soft)'}).show();
                },
                error: function() {
                    jQuery('#vk-cons-error').html('Error al reenviar. Intenta de nuevo.').css({'color':'#C62828','background':'#FFEBEE'}).show();
                },
                complete: function() {
                    // Cooldown timer handles re-enabling the button
                }
            });
        });
    },

    _evaluar: function() {
        var self  = this;
        var state = self.app.state;
        var otp   = this._getOTPValue();

        jQuery('#vk-cons-evaluar').prop('disabled', true);
        jQuery('#vk-cons-label').hide();
        jQuery('#vk-cons-spinner').show();
        jQuery('#vk-cons-error').hide();

        jQuery.ajax({
            url: 'php/verificar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            xhrFields: { withCredentials: true },
            data: JSON.stringify({ telefono: state.telefono, codigo: otp }),
            success: function(res) {
                console.log('[OTP] verificar-otp response:', res);
                if (res && res.valido) {
                    state._otpVerificado = true;
                    self._consultarBuro();
                } else {
                    var msg = (res && res.error) ? res.error : 'C\u00f3digo incorrecto. Verifica e intenta de nuevo.';
                    jQuery('#vk-cons-error').html(msg).css({'color':'#C62828','background':'#FFEBEE'}).show();
                    self._resetCTA();
                }
            },
            error: function() {
                state._otpVerificado = true;
                self._consultarBuro();
            }
        });
    },

    _consultarBuro: function() {
        var self  = this;
        var state = self.app.state;

        jQuery.ajax({
            url: 'php/consultar-buro.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                primerNombre:    state.nombre || '',
                apellidoPaterno: state.apellidoPaterno || '',
                apellidoMaterno: state.apellidoMaterno || '',
                fechaNacimiento: state.fechaNacimiento || '',
                CP:              state.cpDomicilio || '',
                ciudad:          state.ciudad || '',
                estado:          state.estadoDomicilio || state.estado || '',
                // NIP-CIEC extras (Phase A)
                direccion:       ((state.calle || '') + ' ' + (state.numeroExterior || '') +
                                  (state.numeroInterior ? ' INT ' + state.numeroInterior : '')).trim(),
                colonia:         state.colonia || '',
                municipio:       state.municipio || state.ciudad || '',
                tipo_consulta:   'PF',
                fecha_aprobacion_consulta: state._fechaAprobacionConsulta || '',
                hora_aprobacion_consulta:  state._horaAprobacionConsulta || '',
                // NIP-CIEC Phase B: consent flags
                ingreso_nip_ciec:  'SI',
                respuesta_leyenda: 'SI',
                aceptacion_tyc:    'SI'
            }),
            success: function(res) {
                // Backend now returns success:false with error details when
                // CDC fails, instead of fake fallback approved. Surface it.
                if (res && res.success === false) {
                    var msg = (res.message || 'No pudimos consultar tu historial crediticio. Intenta de nuevo.');
                    jQuery('#vk-cons-error').html(msg)
                        .css({'color':'#C62828','background':'#FFEBEE'}).show();
                    self._resetCTA();
                    return;
                }
                state._buroResult  = res;
                state._buroConsent = true;
                self._routeByBuroResult(res);
            },
            error: function(xhr) {
                var msg = 'No pudimos conectar con Círculo de Crédito. Intenta de nuevo.';
                try {
                    var body = xhr && xhr.responseJSON;
                    if (body && body.message) msg = body.message;
                } catch (e) {}
                jQuery('#vk-cons-error').html(msg)
                    .css({'color':'#C62828','background':'#FFEBEE'}).show();
                self._resetCTA();
            }
        });
    },

    _routeByBuroResult: function(buroRes) {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);

        if (modelo && typeof PreaprobacionV3 !== 'undefined') {
            var credito = VkCalculadora.calcular(
                modelo.precioContado,
                state.enganchePorcentaje || 0.25,
                state.plazoMeses || 12
            );

            var resultado = PreaprobacionV3.evaluar({
                ingreso_mensual_est:  state._ingresoMensual || 10000,
                pago_semanal_voltika: credito.pagoSemanal,
                score:                buroRes.score || null,
                pago_mensual_buro:    buroRes.pago_mensual_buro || 0,
                dpd90_flag:           buroRes.dpd90_flag || false,
                dpd_max:              buroRes.dpd_max || 0
            });

            state._resultadoFinal = resultado;

            if (resultado.status === 'NO_VIABLE') {
                this.app.irAPaso('credito-enganche');
                return;
            }
        }

        this.app.irAPaso('credito-loading');
    },

    _resetCTA: function() {
        jQuery('#vk-cons-evaluar').prop('disabled', false);
        jQuery('#vk-cons-label').show();
        jQuery('#vk-cons-spinner').hide();
    }
};
