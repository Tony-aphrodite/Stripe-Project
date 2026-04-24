/* ==========================================================================
   Voltika - Crédito Screen 11: Aceptación de acuerdos
   Per Dibujo.pdf: 2 checkboxes + 6-digit OTP input (individual boxes)
   ========================================================================== */

var PasoCreditoConsentimiento = {

    _otpCooldown: false,
    _resendTimerId: null,
    _initialSendDone: false,
    _lastInitialSendAt: 0,

    init: function(app) {
        this.app = app;
        // \u2500\u2500 Reset per-visit state \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
        // Customer report 2026-04-24: after a CONDICIONAL/NO_VIABLE result
        // the user retries the flow, re-enters this step within the 60s
        // cooldown from the previous visit, and the OTP was silently NOT
        // re-sent because `_otpCooldown` persisted on the singleton across
        // navigations. Every visit must re-enable sending.
        this._otpCooldown = false;
        this._initialSendDone = false;
        if (this._resendTimerId) { clearInterval(this._resendTimerId); this._resendTimerId = null; }

        // \u2500\u2500 Skip when phone was already OTP-verified in this session \u2500\u2500\u2500\u2500\u2500\u2500
        // If the customer already validated this exact phone number during
        // a previous attempt of the same flow, re-sending a new SMS is
        // pure friction. Re-render the form in "already verified" mode so
        // they only re-acknowledge the T&C checkboxes for the new plan
        // terms \u2014 OTP field is hidden and pre-satisfied.
        var state = app.state || {};
        var sameVerifiedPhone = state._otpVerificado === true
            && state._otpVerificadoPhone
            && state._otpVerificadoPhone === state.telefono;

        this._skipOtp = !!sameVerifiedPhone;

        this.render();
        this.bindEvents();
        if (!this._skipOtp) {
            this._enviarOTPInicial();
        }
    },

    _enviarOTPInicial: function() {
        var self = this;
        var tel = self.app.state.telefono;
        if (!tel) return;

        // Prevent double-call within the same visit (init() + some other
        // trigger) but do NOT block re-entries across page navigations.
        if (self._initialSendDone) return;
        self._initialSendDone = true;
        self._lastInitialSendAt = Date.now();

        // Apply the 60s cooldown to the Resend button ONLY. The initial
        // send itself is always allowed because init() is gated by
        // _initialSendDone above.
        self._startResendCooldown(60);

        self._setStatus('sending');

        jQuery.ajax({
            url: 'php/enviar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            xhrFields: { withCredentials: true },
            data: JSON.stringify({
                telefono: tel,
                nombre: self.app.state.nombre || '',
                // Let the backend know this is a retry attempt so it can
                // vary the SMS body slightly to avoid carrier/device
                // duplicate-message suppression.
                attempt_hint: 'retry_' + (self._lastInitialSendAt % 100000),
            }),
            success: function(res) {
                self._applySendResult(res);
            },
            error: function() {
                self._setStatus('send_error');
            },
        });
    },

    _applySendResult: function(res) {
        var self = this;
        // Surface a fallback test code when the SMS provider signaled a
        // failure \u2014 the customer can still finish the flow.
        if (res && res.testCode) {
            self.app.state._otpTestCode = res.testCode;
            var $hint = jQuery('#vk-cons-test-hint');
            var hintHtml = '&#128161; Si no llega el SMS puedes usar este c\u00f3digo: <strong>' + res.testCode + '</strong>';
            if ($hint.length) {
                $hint.html(hintHtml).show();
            } else {
                jQuery('.vk-otp-box').first().closest('div').before(
                    '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                    hintHtml + '</div>'
                );
            }
        }
        self._setStatus('sent');
        // Focus the first OTP box so the user can type immediately.
        setTimeout(function() {
            jQuery('.vk-otp-box[data-index="0"]').trigger('focus');
        }, 50);
    },

    _setStatus: function(state) {
        var $err = jQuery('#vk-cons-error');
        if (!$err.length) return;
        if (state === 'sending') {
            $err.html('&#9881; Enviando c\u00f3digo\u2026').css({
                'color':'#1565C0','background':'#E3F2FD','display':'block'
            });
        } else if (state === 'sent') {
            $err.html('&#10004; C\u00f3digo enviado por SMS.').css({
                'color':'var(--vk-green-primary)','background':'var(--vk-green-soft)','display':'block'
            });
            // Auto-hide after 5s so it doesn't stick around.
            setTimeout(function(){ $err.fadeOut(400); }, 5000);
        } else if (state === 'send_error') {
            $err.html('&#10060; No pudimos enviar el SMS. Usa el c\u00f3digo de prueba de arriba o pulsa "Reenviar".').css({
                'color':'#C62828','background':'#FFEBEE','display':'block'
            });
        } else if (state === 'resent') {
            $err.html('&#10004; C\u00f3digo reenviado.').css({
                'color':'var(--vk-green-primary)','background':'var(--vk-green-soft)','display':'block'
            });
            setTimeout(function(){ $err.fadeOut(400); }, 5000);
        } else if (state === 'hide') {
            $err.hide();
        }
    },

    _startResendCooldown: function(seconds) {
        var self = this;
        var $resend = jQuery('#vk-cons-reenviar');
        if (self._resendTimerId) { clearInterval(self._resendTimerId); self._resendTimerId = null; }
        if (!$resend.length) {
            setTimeout(function() { self._otpCooldown = false; }, seconds * 1000);
            self._otpCooldown = true;
            return;
        }
        self._otpCooldown = true;
        $resend.prop('disabled', true);
        var sec = seconds;
        self._resendTimerId = setInterval(function() {
            sec--;
            $resend.text('Reenviar en ' + sec + 's');
            if (sec <= 0) {
                clearInterval(self._resendTimerId);
                self._resendTimerId = null;
                self._otpCooldown = false;
                $resend.prop('disabled', false).text('Reenviar');
            }
        }, 1000);
    },

    render: function() {
        var state = this.app.state;
        var html = '';
        var skip = !!this._skipOtp;

        html += VkUI.renderBackButton('credito-ingresos');
        html += VkUI.renderCreditoStepBar(4);

        // Title copy varies by verified state. When the phone was already
        // OTP-verified in this session (retry scenario) we don't ask for a
        // fresh SMS \u2014 customer report 2026-04-24: OTP step was being asked
        // twice with no SMS on the second ask.
        if (skip) {
            html += '<h2 class="vk-paso__titulo" style="text-align:center;font-size:22px;line-height:1.3;">Confirma tu nuevo plan</h2>';
            html += '<p class="vk-paso__subtitulo" style="text-align:center;">Tu n\u00famero ya fue verificado. Solo confirma los nuevos t\u00e9rminos.</p>';
        } else {
            html += '<h2 class="vk-paso__titulo" style="text-align:center;font-size:22px;line-height:1.3;">Verifica tu n\u00famero para ver tu resultado</h2>';
            html += '<p class="vk-paso__subtitulo" style="text-align:center;">Te enviamos un c\u00f3digo por SMS para confirmar tu identidad.</p>';
        }

        html += '<div class="vk-card" style="padding:20px;">';

        // Phone display
        var telDisplay = state.telefono
            ? ('+52 ' + state.telefono.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3'))
            : '';

        if (skip) {
            // Verified state \u2014 hide OTP boxes entirely, show a green tick.
            html += '<div style="text-align:center;margin-bottom:14px;padding:14px;background:#E8F5E9;border-radius:10px;">';
            html += '<div style="font-size:28px;color:#2E7D32;margin-bottom:4px;">&#10004;</div>';
            html += '<div style="font-size:14px;font-weight:700;color:#2E7D32;">N\u00famero verificado</div>';
            html += '<div style="font-size:13px;color:#555;margin-top:2px;">' + telDisplay + '</div>';
            html += '</div>';
        } else {
            html += '<div style="text-align:center;margin-bottom:16px;">';
            html += '<div style="font-size:13px;color:var(--vk-text-secondary);">C\u00f3digo enviado a</div>';
            html += '<div style="font-size:16px;font-weight:700;">' + telDisplay + '</div>';
            html += '</div>';

            // Fallback test code \u2014 shown when SMS provider returned a
            // failure and the backend gave us a code the user can still
            // use manually.
            if (state._otpTestCode) {
                html += '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                    '&#128161; Si no llega el SMS puedes usar este c\u00f3digo: <strong>' + state._otpTestCode + '</strong></div>';
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
        }

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
        var tyc  = jQuery('#vk-cons-tyc').is(':checked');
        var buro = jQuery('#vk-cons-buro').is(':checked');
        // In "already verified" mode the OTP is implicit — only the two
        // T&C checkboxes gate the continue button.
        var otpOk = this._skipOtp ? true : (this._getOTPValue().length === 6);
        var ready = otpOk && tyc && buro;
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
            var tyc  = jQuery('#vk-cons-tyc').is(':checked');
            var buro = jQuery('#vk-cons-buro').is(':checked');

            if (!self._skipOtp) {
                var otp  = self._getOTPValue();
                if (otp.length !== 6) {
                    jQuery('#vk-cons-error').text('Ingresa el c\u00f3digo de 6 d\u00edgitos.').show();
                    return;
                }
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

        // Resend OTP \u2014 explicit user action. Cooldown only affects this
        // button (via _startResendCooldown), not initial send at page load.
        jQuery(document).on('click', '#vk-cons-reenviar', function() {
            if (self._otpCooldown) return;  // button is disabled anyway
            var tel = self.app.state.telefono;
            if (!tel) return;
            self._setStatus('sending');
            self._startResendCooldown(60);
            jQuery.ajax({
                url: 'php/enviar-otp.php',
                method: 'POST',
                contentType: 'application/json',
                xhrFields: { withCredentials: true },
                data: JSON.stringify({
                    telefono: tel,
                    nombre: self.app.state.nombre || '',
                    attempt_hint: 'manual_resend_' + (Date.now() % 100000),
                }),
                success: function(res) {
                    if (res && res.testCode) {
                        self.app.state._otpTestCode = res.testCode;
                        var $hint = jQuery('#vk-cons-test-hint');
                        var hintHtml = '&#128161; Si no llega el SMS puedes usar este c\u00f3digo: <strong>' + res.testCode + '</strong>';
                        if ($hint.length) {
                            $hint.html(hintHtml).show();
                        } else {
                            jQuery('.vk-otp-box').first().closest('div').before(
                                '<div id="vk-cons-test-hint" style="background:#E3F2FD;border-radius:6px;padding:8px;margin-bottom:12px;text-align:center;font-size:12px;color:#1565C0;">' +
                                hintHtml + '</div>'
                            );
                        }
                    }
                    self._setStatus('resent');
                },
                error: function() {
                    self._setStatus('send_error');
                },
            });
        });
    },

    _evaluar: function() {
        var self  = this;
        var state = self.app.state;

        jQuery('#vk-cons-evaluar').prop('disabled', true);
        jQuery('#vk-cons-label').hide();
        jQuery('#vk-cons-spinner').show();
        jQuery('#vk-cons-error').hide();

        // Skip the OTP verification entirely when the phone was already
        // verified in this session. We still go through _consultarBuro so
        // the adjusted plan gets re-evaluated.
        if (self._skipOtp) {
            state._otpVerificado = true;
            state._otpVerificadoPhone = state.telefono;
            self._consultarBuro();
            return;
        }

        var otp = this._getOTPValue();
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
                    // Remember which phone was verified so retries can
                    // skip the OTP step instead of re-asking.
                    state._otpVerificadoPhone = state.telefono;
                    self._consultarBuro();
                } else {
                    var msg = (res && res.error) ? res.error : 'C\u00f3digo incorrecto. Verifica e intenta de nuevo.';
                    jQuery('#vk-cons-error').html(msg).css({'color':'#C62828','background':'#FFEBEE'}).show();
                    self._resetCTA();
                }
            },
            error: function() {
                // Fail-open: backend unreachable shouldn't block approval.
                // Pre-existing behaviour (kept to avoid losing leads).
                state._otpVerificado = true;
                state._otpVerificadoPhone = state.telefono;
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
                    self._showCdcError(res);
                    return;
                }
                state._buroResult  = res;
                state._buroConsent = true;
                self._routeByBuroResult(res);
            },
            error: function(xhr) {
                var body = (xhr && xhr.responseJSON) || null;
                self._showCdcError(body || { error: 'Sin conexión', http: xhr && xhr.status });
            }
        });
    },

    _routeByBuroResult: function(buroRes) {
        var self   = this;
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);

        if (!modelo) { this.app.irAPaso('credito-loading'); return; }

        // Idempotency guard (customer brief 2026-04-25): if V3 evaluation
        // already completed for this session, do NOT POST to
        // preaprobacion-v3.php again — duplicate rows would be written to
        // preaprobacion_log / solicitudes_credito. Re-use the stored result
        // and route to the appropriate next screen. This protects against:
        //   - user hitting Back from credito-pago / Paso4B and retriggering
        //     the consent flow,
        //   - page refresh mid-flow with persisted state,
        //   - any future routing bug that loops through consentimiento.
        // A legitimate re-evaluation (e.g., user changes ingresos) must
        // clear state._resultadoFinal explicitly before re-submission.
        if (state._resultadoFinal && state._resultadoFinal.status) {
            if (state._resultadoFinal.status === 'PREAPROBADO') {
                self.app.irAPaso('credito-loading');
            } else {
                self.app.irAPaso('credito-resultado');
            }
            return;
        }

        var credito = VkCalculadora.calcular(
            modelo.precioContado,
            state.enganchePorcentaje || 0.25,
            state.plazoMeses || 12
        );

        // Always call server-side preaprobacion-v3.php — it has the enhanced
        // self-scoring logic that uses age, income, repeat-customer history
        // (Stripe DB lookup) and Truora identity check when CDC has no data.
        // Server-side is also where the decision is logged for audit.
        jQuery.ajax({
            url: 'php/preaprobacion-v3.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                ingreso_mensual_est:  state._ingresoMensual || 10000,
                pago_semanal_voltika: credito.pagoSemanal,
                enganche_pct:         state.enganchePorcentaje || 0.25,
                plazo_meses:          state.plazoMeses || 12,
                precio_contado:       modelo.precioContado,
                modelo:               modelo.nombre || state.modeloSeleccionado,
                // CDC data (real or null)
                score:                buroRes.score || null,
                pago_mensual_buro:    buroRes.pago_mensual_buro || 0,
                dpd90_flag:           buroRes.dpd90_flag || false,
                dpd_max:              buroRes.dpd_max || 0,
                // Tri-state identity signal — consultar-buro.php returns
                // person_found=false when CDC says the persona doesn't exist.
                // Forwarding it lets preaprobacion-v3.php enforce the hard KO.
                person_found:         (buroRes.person_found === undefined) ? null : buroRes.person_found,
                // Customer info (for admin lead tracking)
                nombre:               state.nombre || '',
                apellido_paterno:     state.apellidoPaterno || '',
                apellido_materno:     state.apellidoMaterno || '',
                telefono:             state.telefono || '',
                fecha_nacimiento:     state.fechaNacimiento || '',
                email:                state.email || '',
                cp:                   state.cpDomicilio || '',
                ciudad:               state.ciudad || '',
                estado:               state.estadoDomicilio || state.estado || '',
                truora_ok:            !!(state._truoraResult && (state._truoraResult.status === 'approved'))
            }),
            success: function(resultado) {
                state._resultadoFinal = resultado;
                // The "¡Felicidades!" approval screen is reserved for
                // applicants with a REAL Círculo score that crossed the PRE
                // threshold. CONDICIONAL and every _ESTIMADO variant lack
                // verified credit-bureau data, so they must render in the
                // yellow credito-resultado screen (where the applicant can
                // still proceed if they accept the conditions) — NOT in the
                // green approval screen. NO_VIABLE lands in resultado too.
                if (resultado.status === 'PREAPROBADO') {
                    self.app.irAPaso('credito-loading');
                } else {
                    self.app.irAPaso('credito-resultado');
                }
            },
            error: function() {
                // Last-resort client-side eval if server endpoint is down
                if (typeof PreaprobacionV3 !== 'undefined') {
                    state._resultadoFinal = PreaprobacionV3.evaluar({
                        ingreso_mensual_est:  state._ingresoMensual || 10000,
                        pago_semanal_voltika: credito.pagoSemanal,
                        score:                buroRes.score || null,
                        pago_mensual_buro:    buroRes.pago_mensual_buro || 0,
                        dpd90_flag:           buroRes.dpd90_flag || false,
                        dpd_max:              buroRes.dpd_max || 0,
                        // Identity signal — same semantics as server-side
                        // (false = CDC 404.1 → reject). Without this a server
                        // outage would let fake identities through the client
                        // fallback.
                        person_found:         (buroRes.person_found === undefined) ? null : buroRes.person_found
                    });
                }
                self.app.irAPaso('credito-loading');
            }
        });
    },

    _resetCTA: function() {
        jQuery('#vk-cons-evaluar').prop('disabled', false);
        jQuery('#vk-cons-label').show();
        jQuery('#vk-cons-spinner').hide();
    },

    // Show the real CDC error (HTTP code + body excerpt) so we can diagnose
    // why Círculo is rejecting the call, instead of a generic "try again".
    _showCdcError: function(res) {
        var msg = (res && res.message) || 'No pudimos consultar tu historial crediticio.';
        var detail = '';
        if (res) {
            if (res.http)     detail += 'HTTP: ' + res.http + '<br>';
            if (res.curl_err) detail += 'curl: ' + res.curl_err + '<br>';
            if (res.body)     detail += 'resp: <code style="word-break:break-all;">' + jQuery('<div/>').text(String(res.body).substring(0,400)).html() + '</code>';
        }
        var html = '<strong>' + msg + '</strong>';
        if (detail) html += '<div style="margin-top:8px;font-size:11px;color:#666;">' + detail + '</div>';
        jQuery('#vk-cons-error').html(html)
            .css({'color':'#C62828','background':'#FFEBEE','padding':'12px','border-radius':'6px'}).show();
        this._resetCTA();
    }
};
