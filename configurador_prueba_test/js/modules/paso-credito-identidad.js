/* ==========================================================================
   Voltika - Crédito: Verificación de Identidad (Truora iframe flow)

   Replaces the old DIY INE/selfie capture UI with Truora's hosted Digital
   Identity flow inside an <iframe>. Truora handles:
     - Document capture (INE front + back)
     - Selfie + passive liveness detection
     - OCR + anti-photocopy checks
     - Government database validation (RENAPO / INE)
     - NOM-151 compliance

   Flow:
     1. Ask backend for a one-time iframe token (truora-token.php)
     2. Render the iframe at https://identity.truora.com/?token=<jwt>
     3. Listen for postMessage: truora.process.succeeded / .failed / .steps.completed
     4. On success → advance to credito-enganche
     5. Webhook on our server (truora-webhook.php) stores the final verdict
        asynchronously, so even if the user closes the tab we still get
        the result.

   The old manual capture flow remains available as a fallback when the
   iframe is unavailable (network, Truora outage). That fallback just shows
   an error — we no longer hit the checks API directly.
   ========================================================================== */

var PasoCreditoIdentidad = {

    _iframeReady: false,
    _tokenFetched: false,
    _currentProcessId: null,
    _finished: false,

    init: function(app) {
        this.app = app;

        // ── TEST-MIRROR ONLY (configurador_prueba_test) ────────────────
        // DO NOT COPY THIS BLOCK TO PROD (configurador_prueba/).
        //
        // Customer brief 2026-04-27: in TEST mode we use a simulated
        // verification UI instead of the live Truora iframe. The iframe
        // only works when voltika.mx is on Truora's frame-ancestors
        // whitelist (production-only feature). For internal QA we render
        // a multi-step progress simulation that visually walks through
        // INE → selfie → RENAPO → completion in ~5 seconds, then
        // advances to credito-enganche just like a successful real flow.
        //
        // LIVE mode (configurador_prueba/paso-credito-identidad.js) is
        // unchanged — it always renders the real Truora iframe.
        var state = app.state || {};
        var isTestMode = state._truoraResult &&
                         state._truoraResult.status === 'approved' &&
                         state._truoraResult.fallback === true;
        if (isTestMode) {
            this._renderTestSimulator();
            return;
        }

        this._iframeReady    = false;
        this._tokenFetched   = false;
        this._currentProcessId = null;
        this._finished       = false;
        this.render();
        this._startIframe();
        this._bindMessageListener();
    },

    /**
     * TEST-MIRROR ONLY — simulates the Truora identity verification
     * pipeline without actually calling Truora. Used when state was
     * pre-populated by configurador.js test mode (?test_credito=...).
     * Visually shows a 4-step progress flow then auto-advances.
     */
    _renderTestSimulator: function() {
        var self = this;
        var html = '';

        // Logo
        html += '<div class="vk-identidad-logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';

        // Progress: paso 1 (Crédito aprobado) ✓ + paso 2 (Confirmar identidad) active
        html += '<div class="vk-identidad-progress">';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--done">';
        html += '<span class="vk-identidad-progress__num">&#10003;</span>';
        html += '<span class="vk-identidad-progress__label">Crédito aprobado</span>';
        html += '</div>';
        html += '<div class="vk-identidad-progress__line"></div>';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--active">';
        html += '<span class="vk-identidad-progress__num">2</span>';
        html += '<span class="vk-identidad-progress__label">Confirmar identidad</span>';
        html += '</div>';
        html += '</div>';

        // Title
        html += '<h2 class="vk-identidad-title">Confirma tu identidad</h2>';
        html += '<p class="vk-identidad-subtitle">Validando tus datos con las bases del gobierno mexicano…</p>';

        // TEST MODE banner
        html += '<div style="background:#FFF3E0;border:1.5px solid #FB8C00;border-radius:10px;padding:12px 14px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;font-weight:700;color:#E65100;font-size:13px;">';
        html += '<span>⚠</span><span>MODO TEST · Simulación de Verificación</span>';
        html += '</div>';
        html += '<div style="font-size:11.5px;color:#5d4037;margin-top:4px;line-height:1.5;">';
        html += 'En producción este paso usa el iframe real de Truora con captura de INE + selfie + biometría.';
        html += '</div>';
        html += '</div>';

        // Security callout
        html += '<div class="vk-identidad-security">';
        html += '<div class="vk-identidad-security__icon">&#128274;</div>';
        html += '<div>';
        html += '<div class="vk-identidad-security__title">Verificación certificada</div>';
        html += '<div class="vk-identidad-security__text">Tu identidad es validada por Truora con tecnología certificada y segura.</div>';
        html += '</div>';
        html += '</div>';

        // Progress steps (animated)
        var steps = [
            { id: 1, label: 'Capturando documento INE', delay: 1000 },
            { id: 2, label: 'Verificando rostro y biometría', delay: 2200 },
            { id: 3, label: 'Validando con RENAPO', delay: 3400 },
            { id: 4, label: 'Verificación completa', delay: 4400 }
        ];

        html += '<div id="vk-test-id-steps" style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-top:8px;">';
        for (var i = 0; i < steps.length; i++) {
            html += '<div id="vk-test-id-step-' + steps[i].id + '" ' +
                    'style="display:flex;align-items:center;gap:12px;padding:10px 0;' +
                    (i < steps.length - 1 ? 'border-bottom:1px solid #f3f4f6;' : '') + '">' +
                    '<span id="vk-test-id-icon-' + steps[i].id + '" ' +
                        'style="width:24px;height:24px;border-radius:50%;border:2px solid #d1d5db;' +
                        'display:inline-flex;align-items:center;justify-content:center;color:#9ca3af;' +
                        'font-size:13px;font-weight:700;flex-shrink:0;">' + steps[i].id + '</span>' +
                    '<span id="vk-test-id-label-' + steps[i].id + '" ' +
                        'style="font-size:14px;font-weight:600;color:#9ca3af;flex:1;">' +
                        steps[i].label + '</span>' +
                    '</div>';
        }
        html += '</div>';

        jQuery('#vk-credito-identidad-container').html(html);

        // Animate each step
        steps.forEach(function(step) {
            setTimeout(function() {
                jQuery('#vk-test-id-icon-' + step.id).css({
                    'background': '#10b981',
                    'border-color': '#10b981',
                    'color': '#fff'
                }).html('&#10003;');
                jQuery('#vk-test-id-label-' + step.id).css({
                    'color': '#111',
                    'font-weight': '700'
                });
            }, step.delay);
        });

        // After last step, advance to credito-enganche
        setTimeout(function() {
            self.app.state._identidadVerificada = true;
            self.app.state._truoraProcessId = 'TEST-' + Date.now();
            if (self.app && typeof self.app.irAPaso === 'function') {
                self.app.irAPaso('credito-enganche');
            }
        }, 5500);
    },

    render: function() {
        var state = (this.app && this.app.state) || {};
        var html = '';

        // 1. Logo
        html += '<div class="vk-identidad-logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';

        // 2. Progress indicator (same visual as before)
        html += '<div class="vk-identidad-progress">';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--done">';
        html += '<span class="vk-identidad-progress__num">&#10003;</span>';
        html += '<span class="vk-identidad-progress__label">Crédito aprobado</span>';
        html += '</div>';
        html += '<div class="vk-identidad-progress__line"></div>';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--active">';
        html += '<span class="vk-identidad-progress__num">2</span>';
        html += '<span class="vk-identidad-progress__label">Confirmar identidad</span>';
        html += '</div>';
        html += '</div>';

        // 3. Title + subtitle
        html += '<h2 class="vk-identidad-title">Confirma tu identidad</h2>';
        html += '<p class="vk-identidad-subtitle">Este es el último paso para activar tu crédito Voltika y liberar la entrega de tu moto.</p>';

        // 4. Security callout — highlights that Truora handles data
        html += '<div class="vk-identidad-security">';
        html += '<div class="vk-identidad-security__icon">&#128274;</div>';
        html += '<div>';
        html += '<div class="vk-identidad-security__title">Verificación certificada</div>';
        html += '<div class="vk-identidad-security__text">Tu identidad es validada por Truora con tecnología certificada y segura.</div>';
        html += '</div>';
        html += '</div>';

        // 5. Iframe container (starts showing a loader; replaced when token arrives)
        html += '<div id="vk-truora-container" style="position:relative;width:100%;min-height:720px;border-radius:14px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0;">';
        html += '<div id="vk-truora-loader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px;text-align:center;">';
        html += '<div style="font-size:15px;font-weight:700;color:#1a3a5c;margin-bottom:8px;">Preparando tu verificación…</div>';
        html += '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;">Esto toma unos segundos</div>';
        html += VkUI.renderSpinner();
        html += '</div>';
        html += '</div>';

        // 6. Error banner (hidden by default)
        html += '<div id="vk-identidad-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-top:12px;"></div>';

        // 7. Retry button (shown only after errors)
        html += '<button id="vk-identidad-retry" style="display:none;width:100%;padding:14px;background:#039fe1;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;margin-top:10px;text-transform:uppercase;letter-spacing:0.5px;">Reintentar verificación</button>';

        // 8. Consejos rápidos
        html += '<div class="vk-identidad-consejos" style="margin-top:16px;">';
        html += '<div class="vk-identidad-consejos__title">Consejos rápidos</div>';
        html += '<div class="vk-identidad-consejos__grid">';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Buena iluminación</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Documento completo</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Selfie con rostro despejado</div>';
        html += '</div>';
        html += '</div>';

        // 9. Footer
        html += '<div style="text-align:center;font-size:11px;color:#94a3b8;margin-top:12px;">Tu moto permanecerá reservada mientras completas este paso.</div>';

        jQuery('#vk-credito-identidad-container').html(html);

        // Bind retry button
        var self = this;
        jQuery(document).off('click', '#vk-identidad-retry');
        jQuery(document).on('click', '#vk-identidad-retry', function() {
            jQuery('#vk-identidad-error').hide();
            jQuery(this).hide();
            self._tokenFetched = false;
            self._iframeReady = false;
            self._finished = false;
            jQuery('#vk-truora-container').html(
                '<div id="vk-truora-loader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px;text-align:center;">' +
                    '<div style="font-size:15px;font-weight:700;color:#1a3a5c;margin-bottom:8px;">Preparando tu verificación…</div>' +
                    '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;">Esto toma unos segundos</div>' +
                    VkUI.renderSpinner() +
                '</div>'
            );
            self._startIframe();
        });
    },

    _showError: function(msg, detail) {
        jQuery('#vk-identidad-error').html(
            '<strong>' + msg + '</strong>' +
            (detail ? '<div style="margin-top:6px;font-size:11px;color:#666;white-space:pre-wrap;">' + detail + '</div>' : '')
        ).show();
        jQuery('#vk-identidad-retry').show();
        jQuery('#vk-truora-loader').hide();
    },

    _startIframe: function() {
        var self = this;
        var state = (this.app && this.app.state) || {};

        if (this._tokenFetched) return;
        this._tokenFetched = true;

        // Compose applicant snapshot for the token endpoint.
        var apellidos = ((state.apellidoPaterno || '') + ' ' + (state.apellidoMaterno || '')).trim();
        var payload = {
            cliente_id: state.cliente_id || state.clienteId || state.telefono || '',
            nombre:     (state.nombre || '').trim(),
            apellidos:  apellidos,
            telefono:   state.telefono || '',
            email:      state.email || '',
            curp:       (state.curp || '').toUpperCase(),
        };

        jQuery.ajax({
            url: 'php/truora-token.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
        }).done(function(r) {
            if (!r || !r.ok || !r.iframe_url) {
                self._showError(
                    'No pudimos iniciar la verificación de identidad.',
                    (r && (r.error || r.body)) ? (r.error || '') + ' ' + (r.body || '') : ''
                );
                return;
            }

            state._truoraAccountId = r.account_id;
            state._truoraFlowId    = r.flow_id;

            // Replace loader with the iframe. Minimum size from Truora docs:
            // 450x700 — we use 100% width and 720px height to fit mobile.
            var iframeHtml = '<iframe id="vk-truora-iframe" ' +
                'src="' + r.iframe_url + '" ' +
                'allow="camera; microphone; geolocation" ' +
                'style="width:100%;height:720px;border:0;display:block;background:#fff;">' +
                '</iframe>';
            jQuery('#vk-truora-container').html(iframeHtml);
            self._iframeReady = true;

            // Blank-iframe detection. Truora can block embeds from
            // unwhitelisted domains via X-Frame-Options / CSP
            // frame-ancestors — the iframe element loads but stays blank.
            // We detect this on the iframe `load` event by probing
            // contentWindow.location.href: a SecurityError throw means
            // cross-origin Truora content is rendered (good); a readable
            // about:blank/empty URL means the embed was blocked (bad).
            //
            // We do NOT use a "no postMessage in N seconds" timeout —
            // Truora only posts on major milestones, and the user can
            // legitimately spend minutes inside the flow (granting
            // camera/location, capturing INE, retrying selfie) without
            // any postMessage firing. That produced false positives
            // mid-flow (incident 2026-04-28).
            if (self._blankTimeout) clearTimeout(self._blankTimeout);
            // Hard fallback: if `load` never fires within 30s, the
            // iframe is unreachable (network/DNS).
            self._blankTimeout = setTimeout(function() {
                if (self._finished || self._currentProcessId) return;
                self._showError(
                    'La verificación de identidad tardó demasiado en cargar.',
                    'Revisa tu conexión a internet y reintenta. Si el problema persiste, ' +
                    'escríbenos a ventas@voltika.mx o WhatsApp +52 55 1341 6370.'
                );
            }, 30000);
            jQuery('#vk-truora-iframe').on('load', function() {
                if (self._blankTimeout) {
                    clearTimeout(self._blankTimeout);
                    self._blankTimeout = null;
                }
                if (self._finished || self._currentProcessId) return;
                var blocked = false;
                try {
                    var href = this.contentWindow && this.contentWindow.location && this.contentWindow.location.href;
                    if (!href || href === 'about:blank') blocked = true;
                } catch (e) {
                    // SecurityError = cross-origin Truora content rendered ✓
                }
                if (blocked) {
                    self._showError(
                        'La verificación de identidad no se cargó correctamente.',
                        'Esto suele ocurrir cuando el dominio aún no está autorizado por Truora. ' +
                        'Si el problema persiste, escríbenos a ventas@voltika.mx o WhatsApp +52 55 1341 6370 ' +
                        'para completar tu solicitud manualmente.'
                    );
                }
            });
        }).fail(function(xhr) {
            var body = (xhr && xhr.responseJSON) || null;
            self._showError(
                'No pudimos conectar con el servicio de verificación.',
                (body && (body.error || body.hint)) || ('HTTP ' + (xhr && xhr.status))
            );
        });
    },

    _bindMessageListener: function() {
        var self = this;
        if (this._messageBound) return;
        this._messageBound = true;

        window.addEventListener('message', function(event) {
            // Only accept messages from identity.truora.com origin. Truora
            // docs do not guarantee a specific origin string, so we
            // accept anything that looks like a Truora event.
            var data = event && event.data;
            if (!data) return;

            // Messages can be strings ("truora.process.succeeded") or
            // objects ({ event: "truora.process.succeeded", process_id, ... })
            var eventName = '';
            var processId = null;
            if (typeof data === 'string') {
                eventName = data;
            } else if (typeof data === 'object') {
                eventName = data.event || data.type || data.message || '';
                processId = data.process_id || data.identity_process_id || null;
            }

            if (!eventName || eventName.indexOf('truora') !== 0) return;

            // Truora is talking — cancel the blank-iframe timeout so the
            // user doesn't see a false "no se cargó" error mid-flow.
            if (self._blankTimeout) {
                clearTimeout(self._blankTimeout);
                self._blankTimeout = null;
            }

            if (processId) self._currentProcessId = processId;
            self._handleTruoraEvent(eventName, data);
        }, false);
    },

    _handleTruoraEvent: function(eventName, data) {
        if (this._finished) return;
        var self = this;
        var state = (this.app && this.app.state) || {};

        switch (eventName) {
            case 'truora.process.succeeded':
                // Hard success — all steps passed. Advance immediately.
                this._finished = true;
                state._identidadVerificada = true;
                state._truoraProcessId = (data && (data.process_id || data.identity_process_id)) || this._currentProcessId;
                try { sessionStorage.removeItem('vk_identidad_uploads'); } catch (e) {}
                if (this.app && typeof this.app.irAPaso === 'function') {
                    this.app.irAPaso('credito-enganche');
                }
                break;

            case 'truora.process.failed':
                // Hard failure — identity rejected.
                this._finished = true;
                this._showError(
                    'No pudimos verificar tu identidad.',
                    'Revisa que el documento esté completo y que la selfie coincida con tu INE. Puedes reintentar.'
                );
                break;

            case 'truora.steps.completed':
                // Async validations still running (RENAPO / manual review).
                // The webhook will settle the final status. Show a holding
                // message but keep the user in the flow.
                jQuery('#vk-identidad-error')
                    .css({color:'#92400e',background:'#fef3c7'})
                    .html('<strong>Procesando…</strong><div style="margin-top:6px;font-size:11px;">Tu verificación está siendo revisada. Te notificaremos en cuanto tengamos respuesta (normalmente menos de 1 minuto).</div>')
                    .show();
                // Poll backend for final status every 4s up to 90s.
                this._pollWebhookResult(state._truoraProcessId || this._currentProcessId);
                break;

            default:
                // Unknown Truora event — ignore silently but log for debugging.
                if (window.console) console.log('[Truora]', eventName, data);
        }
    },

    _pollWebhookResult: function(processId) {
        if (!processId) return;
        var self = this;
        var tries = 0;
        var maxTries = 22;   // ~90 s at 4 s intervals
        var timer = setInterval(function() {
            tries++;
            jQuery.get('php/truora-status.php', { process_id: processId })
                .done(function(r) {
                    if (r && r.approved === 1) {
                        clearInterval(timer);
                        self._finished = true;
                        self.app.state._identidadVerificada = true;
                        self.app.irAPaso('credito-enganche');
                    } else if (r && r.approved === 0 && r.status === 'failure') {
                        clearInterval(timer);
                        self._finished = true;
                        self._showError(
                            'No pudimos verificar tu identidad.',
                            (r.declined_reason || '') + ' Puedes reintentar.'
                        );
                    }
                });
            if (tries >= maxTries) {
                clearInterval(timer);
                // Keep the user informed but don't block — webhook will
                // still settle the record asynchronously.
                jQuery('#vk-identidad-error')
                    .html('<strong>Tu verificación sigue procesando.</strong><div style="margin-top:6px;font-size:11px;">Puedes esperar unos minutos más o reintentar.</div>')
                    .show();
            }
        }, 4000);
    },
};
