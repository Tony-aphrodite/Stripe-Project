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

    _initCount: 0,

    init: function(app) {
        this._initCount = (this._initCount || 0) + 1;
        var thisCount = this._initCount;
        this.app = app;
        this._iframeReady    = false;
        this._tokenFetched   = false;
        this._currentProcessId = null;
        this._finished       = false;
        // Cancel any in-flight poll from a previous attempt. Without this,
        // a stale setInterval kept firing after the user clicked
        // "Regresar y corregir datos" and walked back through OTP/CDC, and
        // could navigate them away mid-step when an old verdict landed.
        if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
        if (this._blankTimeout) { clearTimeout(this._blankTimeout); this._blankTimeout = null; }
        if (this._verdictTimeout) { clearTimeout(this._verdictTimeout); this._verdictTimeout = null; }
        this._verdictResolved = false;
        this.render();
        // After render the debug panel exists — log init-count BEFORE
        // anything else so we know if SPA is calling init() multiple
        // times (which would explain a destroyed-iframe symptom).
        this._appendDebug('init() call #' + thisCount + ' @ ' + new Date().toLocaleTimeString());
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

        // 7b. "Regresar a datos" button — used by the name/CURP-mismatch branch
        //     so the user can fix what they typed on the previous CDC screen
        //     and re-run Truora with consistent data.
        html += '<button id="vk-identidad-back-nombre" style="display:none;width:100%;padding:14px;background:#ffffff;color:#039fe1;border:1.5px solid #039fe1;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;margin-top:10px;text-transform:uppercase;letter-spacing:0.5px;">Regresar y corregir datos</button>';

        // 7c. Manual-review explainer — shown when Truora flagged false info.
        //     The crew handles these cases offline; no retry button is shown
        //     because the user can't fix this themselves.
        html += '<div id="vk-identidad-manual-note" style="display:none;margin-top:10px;padding:12px;background:#FFF8E1;border:1px solid #F9A825;border-radius:8px;font-size:12px;color:#5D4037;line-height:1.55;">';
        html += '<strong>Tu solicitud queda en revisión.</strong><br>Nuestro equipo de Voltika revisará tu caso y te contactará por WhatsApp o correo dentro de las próximas horas hábiles.';
        html += '</div>';

        // 8. Consejos rápidos
        html += '<div class="vk-identidad-consejos" style="margin-top:16px;">';
        html += '<div class="vk-identidad-consejos__title">Consejos rápidos</div>';
        html += '<div class="vk-identidad-consejos__grid">';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Buena iluminación</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Documento completo (frente y reverso)</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> INE legible y sin reflejos</div>';
        html += '</div>';
        html += '</div>';

        // 9. Footer
        html += '<div style="text-align:center;font-size:11px;color:#94a3b8;margin-top:12px;">Tu moto permanecerá reservada mientras completas este paso.</div>';

        // 10. Debug log panel — hidden in production, surfaced only when
        //     diagnostics are needed by appending ?debug=1 (or &debug=1)
        //     to the page URL. Customer report 2026-04-30: end users were
        //     seeing the iframe URLs and probe timestamps mid-flow and
        //     reading them as errors. The panel is still rendered (so
        //     `_appendDebug` writes don't error out) but kept off-screen.
        var _debugVisible = /[?&]debug=1\b/i.test(location.search);
        var _debugStyle = _debugVisible
            ? 'margin-top:14px;padding:10px 12px;background:#0f172a;color:#94a3b8;border-radius:8px;font-family:ui-monospace,Menlo,monospace;font-size:10.5px;line-height:1.6;max-height:160px;overflow-y:auto;'
            : 'display:none;';
        html += '<div id="vk-truora-debug" style="' + _debugStyle + '">[debug] esperando iniciar verificación...</div>';

        jQuery('#vk-credito-identidad-container').html(html);

        // Bind retry button
        var self = this;
        jQuery(document).off('click', '#vk-identidad-retry');
        jQuery(document).on('click', '#vk-identidad-retry', function() {
            jQuery('#vk-identidad-error').hide();
            jQuery('#vk-identidad-manual-note').hide();
            jQuery('#vk-identidad-back-nombre').hide();
            jQuery(this).hide();
            self._tokenFetched = false;
            self._iframeReady = false;
            self._finished = false;
            // Re-show the iframe container — a previous attempt may have
            // hidden it once polling started (see succeeded/redirect
            // branches). Without `.show()` the rebuilt iframe would render
            // into a display:none parent.
            jQuery('#vk-truora-container').show().html(
                '<div id="vk-truora-loader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px;text-align:center;">' +
                    '<div style="font-size:15px;font-weight:700;color:#1a3a5c;margin-bottom:8px;">Preparando tu verificación…</div>' +
                    '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;">Esto toma unos segundos</div>' +
                    VkUI.renderSpinner() +
                '</div>'
            );
            self._startIframe();
        });

        // "Regresar a corregir datos" — sends the user back to the CDC name
        // screen so they can fix what they typed and re-run Truora with
        // consistent data. (Customer brief 2026-04-30 — name mismatch.)
        jQuery(document).off('click', '#vk-identidad-back-nombre');
        jQuery(document).on('click', '#vk-identidad-back-nombre', function() {
            if (self.app && typeof self.app.irAPaso === 'function') {
                self.app.irAPaso('credito-nombre');
            }
        });

    },

    _showError: function(msg, detail, opts) {
        opts = opts || {};
        jQuery('#vk-identidad-error').html(
            '<strong>' + msg + '</strong>' +
            (detail ? '<div style="margin-top:6px;font-size:11px;color:#666;white-space:pre-wrap;">' + detail + '</div>' : '')
        ).show();
        // Three CTAs depending on the failure class:
        //   - retry-truora: name/CURP mismatch → user re-runs identity with same data
        //   - back-to-name: explicit "go fix the previous screen" button
        //   - manual-review: Truora flagged false info → no retry, crew will reach out
        jQuery('#vk-identidad-retry').toggle(opts.retry !== false);
        jQuery('#vk-identidad-back-nombre').toggle(!!opts.backToNombre);
        jQuery('#vk-identidad-manual-note').toggle(!!opts.manualReview);
        jQuery('#vk-truora-loader').hide();
        this._appendDebug('SHOW ERROR: ' + msg + (opts.code ? ' [' + opts.code + ']' : ''));
    },

    // Map Truora declined_reason → customer-facing message + CTA.
    // The three classes come from the customer brief 2026-04-30:
    //   1. CURP/name mismatch  → "use same info as previous screen, retry"
    //   2. Truora false-info   → manual review by crew
    //   3. Generic failure     → retry
    _mapDeclinedReason: function(declinedReason, fallbackDetail) {
        var msg, detail, opts = { retry: true };
        switch (declinedReason) {
            case 'identity_name_mismatch':
                msg = 'Los datos no coinciden con la información del paso anterior.';
                detail = 'El nombre que aparece en tu INE no coincide con el que ingresaste en la pantalla anterior. ' +
                         'Por favor regresa, usa la misma información que aparece en tu INE y reinicia la verificación.';
                opts.backToNombre = true;
                break;
            case 'identity_curp_mismatch':
                msg = 'La identidad verificada no coincide con los datos del estudio de crédito.';
                detail = 'Por seguridad, la persona que sube su INE debe ser la misma que solicitó el crédito. ' +
                         'Usa la misma información del paso anterior y reinicia la verificación.';
                opts.backToNombre = true;
                break;
            case 'verified_curp_unavailable':
                msg = 'No pudimos confirmar el CURP de tu documento.';
                detail = 'Reintenta la verificación con un INE más legible. Si el problema persiste, ' +
                         'contáctanos y revisaremos tu caso manualmente.';
                break;
            default:
                // Anything else from Truora (liveness, document tampering,
                // inconsistent face, blacklist hit, etc.) → manual review.
                msg = 'Tu verificación necesita revisión manual.';
                detail = 'Detectamos información que requiere validación adicional por parte de nuestro equipo. ' +
                         'Nos pondremos en contacto contigo a la brevedad para completar tu solicitud. ' +
                         'Puedes escribirnos a ventas@voltika.mx o WhatsApp +52 55 1341 6370.' +
                         (fallbackDetail ? '\n\nDetalle: ' + fallbackDetail : '');
                opts.retry = false;
                opts.manualReview = true;
        }
        return { msg: msg, detail: detail, opts: opts };
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

    _isInAppBrowser: function() {
        // In-app browsers block camera access, which is what triggers
        // Truora's "Lo sentimos, no es posible continuar" device-block
        // screen. Truora doesn't emit any postMessage in that state, so
        // we can't detect it from outside the iframe — preflight is the
        // only reliable mechanism.
        var ua = (navigator.userAgent || '');
        return /FBAN|FBAV|Instagram|WhatsApp|Line\/|MicroMessenger|TikTok|Snapchat|Twitter|FB_IAB|FBIOS/i.test(ua);
    },

    _startIframe: function() {
        var self = this;
        var state = (this.app && this.app.state) || {};

        if (this._tokenFetched) return;
        this._tokenFetched = true;

        // Customer brief 2026-04-30: "When shows this screen [Truora's
        // device-block], send to CDC otp screen." The device-block is
        // overwhelmingly caused by in-app browsers (WhatsApp, Facebook,
        // Instagram, …) where camera access is sandboxed off. Catch
        // that case BEFORE loading Truora so the user lands on
        // credito-otp and can restart the flow from a real browser.
        // Truora and CDC remain mandatory — credito-otp is the
        // upstream step, so the user goes through OTP → consentimiento
        // → loading → aprobado → identidad (Truora again) and reaches
        // a working Truora once they're on a supported browser.
        if (this._isInAppBrowser()) {
            this._appendDebug('in-app browser detected (' + (navigator.userAgent || '').substr(0, 80) + ') · routing to credito-otp');
            if (this.app && typeof this.app.irAPaso === 'function') {
                this.app.irAPaso('credito-otp');
            }
            return;
        }

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

        // Capture browser-level events that might silently block iframes.
        if (!self._envProbed) {
            self._envProbed = true;
            document.addEventListener('securitypolicyviolation', function(ev) {
                self._appendDebug('CSP-violation: ' + ev.violatedDirective + ' blocked=' + ev.blockedURI);
            });
            try {
                if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
                    navigator.serviceWorker.getRegistrations().then(function(regs) {
                        if (regs && regs.length) {
                            self._appendDebug('SW: ' + regs.length + ' registrations · ' +
                                regs.map(function(r){ return r.scope; }).join(', '));
                        } else {
                            self._appendDebug('SW: none');
                        }
                    });
                }
            } catch (e) {}
            self._appendDebug('cookies-enabled=' + navigator.cookieEnabled +
                ' · UA=' + (navigator.userAgent || '').substr(0, 80));
        }

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

            // Wrap the Truora iframe in our own host page (php/truora-iframe-host.php)
            // so it loads in an isolated browsing context. The host page is
            // a same-origin minimal HTML that embeds Truora as a nested
            // iframe and forwards postMessages back to us via
            // window.parent.postMessage with a `__from_truora_host` flag.
            //
            // Why: empirical testing 2026-04-29 (truora-test-personal.php)
            // proved the Truora iframe renders perfectly in any standalone
            // page on this same origin — including pages that reproduce
            // the SPA's exact DOM nesting and CSS. Yet the iframe renders
            // BLANK when embedded directly in the configurador SPA. The
            // SPA's window scope (one of the many JS modules loaded during
            // the credit flow) interferes with Truora; loading Truora in a
            // sub-iframe gives it a fresh window where nothing has touched
            // the postMessage path or DOM observers.
            var hostUrl = 'php/truora-iframe-host.php?u=' + encodeURIComponent(r.iframe_url);
            iframe.src = hostUrl;
            self._appendDebug('host src set: ' + hostUrl.substr(0, 80) + '...');

            jQuery('#vk-truora-container').empty().append(iframe);
            self._iframeReady = true;
            self._appendDebug('iframe appended @ ' + new Date().toLocaleTimeString());
            self._appendDebug('referrer=' + (document.referrer || '(empty)') + ' · location=' + location.href);

            // Probe iframe state at intervals — tells us if Truora is
            // initializing inside the iframe even when no postMessage
            // arrives. Cross-origin access throws SecurityError = good
            // (Truora's content is loaded). Returns about:blank/null = bad.
            var probe = function(label) {
                try {
                    var f = document.getElementById('vk-truora-iframe');
                    if (!f) { self._appendDebug(label + ': iframe MISSING from DOM!'); return; }
                    var rect = f.getBoundingClientRect();
                    var info = label + ': size=' + Math.round(rect.width) + 'x' + Math.round(rect.height) +
                               ' visible=' + (rect.width > 0 && rect.height > 0);
                    try {
                        var loc = f.contentWindow && f.contentWindow.location;
                        var href = loc && loc.href;
                        info += ' contentLoc=' + (href || 'null');
                    } catch (e) {
                        // SecurityError = cross-origin content loaded (good — Truora is there)
                        info += ' contentLoc=BLOCKED(cross-origin OK · ' + (e.name || 'err') + ')';
                    }
                    self._appendDebug(info);
                } catch (e) {
                    self._appendDebug(label + ' probe failed: ' + (e.message || e));
                }
            };
            setTimeout(function(){ probe('probe@3s'); }, 3000);
            setTimeout(function(){ probe('probe@10s'); }, 10000);
            setTimeout(function(){ probe('probe@25s'); }, 25000);

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

        // VERBOSE: log EVERY postMessage from ANY origin so we can see
        // if Truora is sending anything at all (we previously filtered to
        // only `truora.*` events but observed silence — need to know if
        // events are arriving with different naming).
        window.addEventListener('message', function(event) {
            var d = event && event.data;
            var origin = event && event.origin || '?';
            var preview = '';
            if (typeof d === 'string') preview = d.substr(0, 80);
            else if (d && typeof d === 'object') {
                try { preview = JSON.stringify(d).substr(0, 80); } catch (e) { preview = '[unserializable]'; }
            } else preview = '(empty)';
            self._appendDebug('msg<' + origin + '> ' + preview);
        }, false);

        window.addEventListener('message', function(event) {
            var data = event && event.data;
            if (!data) return;
            // Capture state for every branch in this listener (redirect-signal,
            // host-event, truora-event). Without this, the redirect-signal
            // branch threw ReferenceError on `state._truoraProcessId` and the
            // success → enganche advance silently failed.
            var state = (self.app && self.app.state) || {};

            // ── Post-redirect signal from truora-redirect.php ────────────
            // When Truora finishes the flow it lands the user on our
            // truora-redirect.php page. That page then posts up a
            // { vk_truora_otp_returned, process_id, status, ... } payload.
            //
            // Customer brief 2026-04-30: "If the truora validation is ok,
            // send to the enganche pay screen." — when this signal
            // arrives we know Truora's flow finished successfully (only
            // a successful flow reaches the redirect URL). Advance
            // immediately. Background CURP check + server-side order
            // guard handle anti-fraud.
            if (data && typeof data === 'object' && data.vk_truora_otp_returned) {
                if (self._finished) return;
                var pid = data.process_id || data.identity_process_id || data.id || null;
                var aid = data.account_id || data.user_id || null;
                if (pid) {
                    state._truoraProcessId = state._truoraProcessId || pid;
                    self._currentProcessId = self._currentProcessId || pid;
                }
                if (aid && !state._truoraAccountId) state._truoraAccountId = aid;
                self._appendDebug('redirect signal · pid=' + (pid || '?') + ' aid=' + (aid || '?'));
                // Same gating as the in-iframe succeeded branch — wait
                // for verdict before navigating so brief #4 (mismatch →
                // back to credito-nombre) fires here and not at checkout.
                self._finished = true;
                state._identidadVerificada = false;
                self._verdictResolved = false;
                try { sessionStorage.removeItem('vk_identidad_uploads'); } catch (e) {}
                jQuery('#vk-truora-container').hide();
                jQuery('#vk-identidad-error')
                    .css({color:'#0c4a6e',background:'#e0f2fe'})
                    .html('<strong>Verificando datos…</strong>' +
                          '<div style="margin-top:6px;font-size:11px;">Estamos confirmando que la información de tu INE coincide con los datos que ingresaste. Esto toma unos segundos.</div>')
                    .show();
                var pollKey2;
                if (state._truoraProcessId) pollKey2 = { process_id: state._truoraProcessId };
                else if (self._currentProcessId) pollKey2 = { process_id: self._currentProcessId };
                else if (state._truoraAccountId) pollKey2 = { account_id: state._truoraAccountId };
                self._appendDebug('poll key (redirect): ' + JSON.stringify(pollKey2 || null));
                if (pollKey2) self._pollWebhookResult(pollKey2);
                if (self._verdictTimeout) clearTimeout(self._verdictTimeout);
                self._verdictTimeout = setTimeout(function() {
                    if (self._verdictResolved) return;
                    self._appendDebug('verdict timeout · showing retry');
                    if (self._pollTimer) { clearInterval(self._pollTimer); self._pollTimer = null; }
                    jQuery('#vk-identidad-error')
                        .css({color:'#92400e',background:'#fef3c7'})
                        .html('<strong>La verificación está tardando más de lo normal.</strong>' +
                              '<div style="margin-top:6px;font-size:11px;">Esto suele resolverse en segundos. Reintenta o contacta soporte si persiste.</div>')
                        .show();
                    jQuery('#vk-identidad-retry').show();
                }, 20000);
                return;
            }

            // Unwrap messages forwarded by truora-iframe-host.php. The
            // host wraps each Truora postMessage as
            //   { __from_truora_host: true, origin, data }
            // and also emits its own host_event lifecycle pings so the
            // SPA can see when the host iframe loaded.
            if (data && typeof data === 'object' && data.__from_truora_host) {
                if (data.host_event) {
                    self._appendDebug('host: ' + data.host_event);
                    return;
                }
                data = data.data;  // unwrap
                if (!data) return;
            }

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
            self._appendDebug('truora-event: ' + eventName + (processId ? ' (' + processId + ')' : ''));

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
                // Wait for the server verdict before navigating.
                // status.php (with the account_id-fallback writeback fix)
                // delivers the verdict via API even when the webhook
                // signature is broken. Customer brief #4 (2026-04-30)
                // requires mismatch refusal at THIS step, not at checkout.
                state._truoraProcessId = (data && (data.process_id || data.identity_process_id)) || this._currentProcessId;
                state._identidadVerificada = false;
                this._finished = true;
                this._verdictResolved = false;
                jQuery('#vk-truora-container').hide();
                jQuery('#vk-identidad-error')
                    .css({color:'#0c4a6e',background:'#e0f2fe'})
                    .html('<strong>Verificando datos…</strong>' +
                          '<div style="margin-top:6px;font-size:11px;">Estamos confirmando que la información de tu INE coincide con los datos que ingresaste. Esto toma unos segundos.</div>')
                    .show();
                // Build poll key — prefer process_id; if missing, fall
                // back to account_id (status.php accepts either). The
                // 2026-04-30 stuck-on-verifying issue traced back to
                // some Truora flow paths not including process_id in the
                // postMessage payload — without this fallback the poll
                // never started.
                var pollKey1;
                if (state._truoraProcessId) pollKey1 = { process_id: state._truoraProcessId };
                else if (this._currentProcessId) pollKey1 = { process_id: this._currentProcessId };
                else if (state._truoraAccountId) pollKey1 = { account_id: state._truoraAccountId };
                this._appendDebug('poll key: ' + JSON.stringify(pollKey1 || null));
                if (pollKey1) this._pollWebhookResult(pollKey1);
                // Safety timeout — fires when no verdict was reached
                // (poll never started OR poll exhausted). Distinguishes
                // from "poll resolved cleanly" via _verdictResolved.
                if (this._verdictTimeout) clearTimeout(this._verdictTimeout);
                this._verdictTimeout = setTimeout(function() {
                    if (self._verdictResolved) return; // verdict reached
                    self._appendDebug('verdict timeout · showing retry');
                    if (self._pollTimer) { clearInterval(self._pollTimer); self._pollTimer = null; }
                    jQuery('#vk-identidad-error')
                        .css({color:'#92400e',background:'#fef3c7'})
                        .html('<strong>La verificación está tardando más de lo normal.</strong>' +
                              '<div style="margin-top:6px;font-size:11px;">Esto suele resolverse en segundos. Reintenta o contacta soporte si persiste.</div>')
                        .show();
                    jQuery('#vk-identidad-retry').show();
                }, 20000);
                break;

            case 'truora.process.failed':
                // Hard failure from Truora's side. We do NOT decide the
                // user-facing branch here — the webhook (or status.php's
                // API fallback) does, because only the server can read the
                // declined_reason / failure_status payload. Kick a status
                // poll so the right branch (retry vs back-to-name vs
                // manual-review) gets shown as soon as the verdict lands.
                this._finished = true;
                jQuery('#vk-identidad-error')
                    .css({color:'#92400e',background:'#fef3c7'})
                    .html('<strong>Procesando resultado…</strong>' +
                          '<div style="margin-top:6px;font-size:11px;">Estamos confirmando el motivo con Truora. Esto toma unos segundos.</div>')
                    .show();
                this._finished = false; // allow the poll to settle
                this._pollWebhookResult(state._truoraProcessId || this._currentProcessId);
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

    // Out-of-band status check. Used when the redirect-page signal
    // arrives so the SPA settles immediately instead of waiting for the
    // next polling tick. Idempotent — duplicate calls are harmless.
    _forceStatusCheck: function() {
        var self = this;
        var state = (this.app && this.app.state) || {};
        if (this._finished) return;
        var params = {};
        var pid = state._truoraProcessId || this._currentProcessId;
        var aid = state._truoraAccountId;
        if (pid) params.process_id = pid;
        else if (aid) params.account_id = aid;
        else return;
        self._appendDebug('force-check ' + JSON.stringify(params));
        jQuery.get('php/truora-status.php', params).done(function(r) {
            if (!r) return;
            self._appendDebug('force-check result · approved=' + r.approved +
                ' status=' + r.status + ' curp_match=' + r.curp_match +
                ' source=' + (r.source || '?'));
            if (r.approved === 1 && r.curp_match !== 0 && r.name_match !== 0) {
                self._finished = true;
                self.app.state._identidadVerificada = true;
                self.app.irAPaso('credito-enganche');
            } else if (r.approved === 0) {
                self._finished = true;
                var mappedFC = self._mapDeclinedReason(
                    r.declined_reason || r.manual_review_reason || 'truora_validation_failed',
                    r.failure_status
                );
                self._showError(mappedFC.msg, mappedFC.detail, mappedFC.opts);
            }
            // approved === null → keep the existing 4-second poll running;
            // the API fallback in truora-status.php may need another tick.
        });
    },

    _pollWebhookResult: function(processId) {
        // Accept either a process_id string (legacy) or a query-params
        // object {process_id} or {account_id}. Customer report 2026-04-30:
        // when Truora's flow goes through the OTP-redirect path it does
        // not always include process_id in the postMessage / URL params
        // — the SPA had it from the truora-token call as account_id, but
        // the old signature only accepted process_id, so the poll silently
        // never started and the user got stuck on "Verificando datos…"
        // with no retry CTA (the timeout logic also misclassified
        // never-started as already-resolved).
        var qs;
        if (typeof processId === 'object' && processId) {
            qs = processId;
        } else if (typeof processId === 'string' && processId) {
            qs = { process_id: processId };
        }
        if (!qs || (!qs.process_id && !qs.account_id)) return;
        var self = this;
        // Reset the verdict-resolved flag — the poll will set it true
        // when (and only when) a terminal verdict is observed.
        self._verdictResolved = false;
        // Replace any in-flight poll so we don't have two timers racing
        // against each other (happens when the user fails name-match,
        // walks back through credito-nombre and re-arrives at this step).
        if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
        var tries = 0;
        var maxTries = 22;   // ~90 s at 4 s intervals
        // Verdict-resolved flag: prevents the immediate first check and
        // the first interval tick from BOTH advancing/refusing when their
        // responses arrive close to each other.
        var resolved = false;

        // Stop the poll cleanly from any branch — uses self._pollTimer
        // (the canonical reference) so all stops behave the same and
        // init()/duplicate _pollWebhookResult calls can also tear it down.
        // ALSO sets _verdictResolved=true so the safety timeout (in the
        // success / redirect-signal handlers) knows the verdict was
        // delivered cleanly and stays out of the way.
        var stopPoll = function() {
            resolved = true;
            self._verdictResolved = true;
            if (self._pollTimer) { clearInterval(self._pollTimer); self._pollTimer = null; }
        };

        var checkOnce = function() {
            if (resolved) return;
            tries++;
            jQuery.get('php/truora-status.php', qs)
                .done(function(r) {
                    if (resolved) return;
                    if (!r) return;
                    self._appendDebug('poll #' + tries + ': approved=' + r.approved +
                        ' status=' + r.status + ' curp_match=' + r.curp_match);

                    // ── Recoverable mismatch branch ───────────────────────
                    // CURP or NAME doesn't match what was sent to CDC →
                    // surface the "use the same info as previous screen,
                    // restart" CTA (customer brief 2026-04-30).
                    if (r.approved === 0 && (
                        r.declined_reason === 'identity_curp_mismatch' ||
                        r.declined_reason === 'identity_name_mismatch' ||
                        r.declined_reason === 'verified_curp_unavailable' ||
                        r.curp_match === 0 ||
                        r.name_match === 0
                    )) {
                        stopPoll();
                        self._finished = true;
                        // Iframe stays hidden (already hidden by the
                        // success/redirect handler when polling started)
                        // — the user sees the back-to-nombre CTA without
                        // Truora's post-flow noise behind it.
                        var mapped = self._mapDeclinedReason(r.declined_reason ||
                            (r.name_match === 0 ? 'identity_name_mismatch' :
                             r.curp_match === 0 ? 'identity_curp_mismatch' : ''));
                        self._showError(mapped.msg, mapped.detail, mapped.opts);
                        return;
                    }

                    if (r.approved === 1) {
                        // Defense-in-depth: only advance if curp_match is
                        // explicitly 1 OR null (legacy rows from before
                        // this column existed). curp_match === 0 should
                        // never reach here because the webhook would have
                        // set approved=0, but guard anyway.
                        if (r.curp_match === 0 || r.name_match === 0) {
                            stopPoll();
                            self._finished = true;
                            var mapped2 = self._mapDeclinedReason(
                                r.name_match === 0 ? 'identity_name_mismatch' : 'identity_curp_mismatch'
                            );
                            self._showError(mapped2.msg, mapped2.detail, mapped2.opts);
                            return;
                        }
                        stopPoll();
                        self._finished = true;
                        self.app.state._identidadVerificada = true;
                        self.app.irAPaso('credito-enganche');
                    } else if (r.approved === 0 && (r.status === 'failure' || r.status === 'failed' || r.manual_review === 1)) {
                        // Truora outright failed (liveness, doc tampering,
                        // blacklist hit, etc.) → manual-review branch.
                        // Accept both 'failure' and 'failed' since
                        // truora-status.php passes through Truora's raw
                        // status string (which is 'failed' on /v1 API).
                        stopPoll();
                        self._finished = true;
                        var mapped3 = self._mapDeclinedReason(
                            r.declined_reason || r.manual_review_reason || 'truora_validation_failed',
                            r.failure_status || r.declined_reason
                        );
                        self._showError(mapped3.msg, mapped3.detail, mapped3.opts);
                    }
                    // approved === null → keep polling; the webhook may
                    // still arrive, and the API fallback in truora-status.php
                    // may need another tick to fetch from Truora's REST API.
                });
            if (tries >= maxTries) {
                stopPoll();
                // 90 s elapsed without verdict — neither webhook nor API
                // fallback resolved. Surface a clear retry CTA so the user
                // isn't trapped on the holding screen forever; the back-end
                // CURP / name-match guard at confirmar-orden.php still
                // refuses to create a fraudulent order if the user
                // somehow continued anyway.
                jQuery('#vk-identidad-error')
                    .css({color:'#92400e',background:'#fef3c7'})
                    .html('<strong>Tu verificación sigue procesando.</strong>' +
                          '<div style="margin-top:6px;font-size:11px;">Puede haber un retraso de Truora. Espera unos minutos más, o reintenta la verificación desde cero.</div>')
                    .show();
                jQuery('#vk-identidad-retry').show();
            }
        };

        // Customer report 2026-04-30: users were stuck on "Verificando
        // datos…" because the first status check only fired AFTER the
        // initial 4 s timer cycle. Fire one immediately so the verdict
        // surfaces as soon as the webhook (or API fallback) is ready.
        checkOnce();
        var timer = setInterval(checkOnce, 4000);
        this._pollTimer = timer;
    },
};
