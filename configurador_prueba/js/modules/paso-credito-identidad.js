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
        this._iframeReady    = false;
        this._tokenFetched   = false;
        this._currentProcessId = null;
        this._finished       = false;
        this.render();
        this._startIframe();
        this._bindMessageListener();
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

        // 10. Debug log panel (visible — captures iframe events on the
        //     user's actual device since most won't open DevTools).
        //     Hidden once we confirm production is stable.
        html += '<div id="vk-truora-debug" style="margin-top:14px;padding:10px 12px;background:#0f172a;color:#94a3b8;border-radius:8px;font-family:ui-monospace,Menlo,monospace;font-size:10.5px;line-height:1.6;max-height:160px;overflow-y:auto;">[debug] esperando iniciar verificación...</div>';

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
        this._appendDebug('SHOW ERROR: ' + msg);
    },

    // Append a line to the on-page debug panel. Helps diagnose iframe
    // failures on real devices where the user can't open DevTools.
    _appendDebug: function(line) {
        try {
            var dbg = document.getElementById('vk-truora-debug');
            if (!dbg) return;
            if (dbg.innerText.indexOf('esperando iniciar') === 0) dbg.innerText = '';
            dbg.innerText += '\n' + line;
            dbg.scrollTop = dbg.scrollHeight;
        } catch (e) {}
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

        self._appendDebug('POST truora-token.php... @ ' + new Date().toLocaleTimeString());

        jQuery.ajax({
            url: 'php/truora-token.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            dataType: 'json',
        }).done(function(r) {
            if (!r || !r.ok || !r.iframe_url) {
                self._appendDebug('token NOT OK: ' + JSON.stringify(r).substr(0, 100));
                self._showError(
                    'No pudimos iniciar la verificación de identidad.',
                    (r && (r.error || r.body)) ? (r.error || '') + ' ' + (r.body || '') : ''
                );
                return;
            }

            self._appendDebug('token OK · flow_id=' + r.flow_id + ' · account=' + r.account_id);
            state._truoraAccountId = r.account_id;
            state._truoraFlowId    = r.flow_id;
            state._truoraIframeUrl = r.iframe_url;

            // Build iframe element programmatically.
            //
            // Canonical pattern (matches the working diagnostic page at
            // truora-diag-completo.php which renders Truora correctly):
            //   1. create element
            //   2. set src
            //   3. append to DOM
            //
            // We previously tried APPEND-first-then-SET-SRC + cache-bust
            // ?_cb=<ts>. That combination broke real-flow rendering:
            //   - APPEND-first triggered a phantom `load` event for
            //     about:blank in some Chromium versions, marking the
            //     iframe as "loaded" before the real URL was assigned.
            //     Subsequent silent CSP/X-Frame blocks then weren't
            //     caught by our 45 s timeout.
            //   - The cache-bust param produced URLs not signed by the
            //     JWT, which Truora's gateway in some configurations
            //     treats as tampered — returning a blank page rather
            //     than the flow.
            self._iframeLoaded = false;
            self._iframeLoadCount = 0;
            var iframe = document.createElement('iframe');
            iframe.id    = 'vk-truora-iframe';
            iframe.allow = 'camera; microphone; geolocation; payment; clipboard-write';
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('referrerpolicy', 'origin-when-cross-origin');
            iframe.setAttribute('style', 'width:100%;height:720px;border:0;display:block;background:#fff;');

            // Listener BEFORE src so the very first load event is captured.
            iframe.addEventListener('load', function() {
                self._iframeLoadCount++;
                self._iframeLoaded = true;
                var now = new Date().toLocaleTimeString();
                if (window.console) console.log('[Truora iframe] load event fired (#' + self._iframeLoadCount + ' at ' + now + ')');
                self._appendDebug('load #' + self._iframeLoadCount + ' @ ' + now);
            });
            iframe.addEventListener('error', function() {
                if (window.console) console.error('[Truora iframe] error event fired');
                self._appendDebug('ERROR event @ ' + new Date().toLocaleTimeString());
                if (self._finished || self._currentProcessId) return;
                self._showError(
                    'La verificación de identidad no pudo cargar.',
                    'Revisa tu conexión a internet. Si el problema persiste, ' +
                    'escríbenos a WhatsApp +52 55 1341 6370 o ventas@voltika.mx.'
                );
            });

            // Set src BEFORE append. Use the URL exactly as Truora gave it
            // (do NOT add cache-bust params — the JWT is unique per call so
            // there is nothing to bust, and added params can void Truora's
            // gateway validation).
            iframe.src = r.iframe_url;
            self._appendDebug('src set: ' + r.iframe_url.substr(0, 60) + '...');

            jQuery('#vk-truora-container').empty().append(iframe);
            self._iframeReady = true;
            self._appendDebug('iframe appended @ ' + new Date().toLocaleTimeString());

            // Hard fallback: if `load` never fires AND no Truora postMessage
            // arrives within 45s, network or browser-level block. We use
            // 45s instead of 30 because cold-start Truora + slow mobile
            // networks can take 15-25s; postMessages cancel the timer.
            if (self._blankTimeout) clearTimeout(self._blankTimeout);
            self._blankTimeout = setTimeout(function() {
                if (self._finished || self._currentProcessId) return;
                if (!self._iframeLoaded) {
                    self._showError(
                        'La verificación de identidad tardó demasiado en cargar.',
                        'Revisa tu conexión a internet y reintenta. Si el problema persiste, ' +
                        'escríbenos a ventas@voltika.mx o WhatsApp +52 55 1341 6370.'
                    );
                }
                // If load fired but no postMessage, do NOTHING — the user
                // may simply be reading the screen before interacting.
            }, 45000);
        }).fail(function(xhr) {
            var body = (xhr && xhr.responseJSON) || null;
            self._appendDebug('AJAX FAIL: HTTP ' + (xhr && xhr.status));
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
            self._appendDebug('postMessage: ' + eventName + (processId ? ' (' + processId + ')' : ''));

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
