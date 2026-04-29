/* ==========================================================================
   Voltika Configurador - Main Orchestrator
   Manages application state, slide transitions, and step routing
   ========================================================================== */

(function($) {
    'use strict';

    var Configurador = {

        state: {
            pasoActual: 1,
            modeloSeleccionado: null,
            metodoPago: 'credito',
            colorSeleccionado: null,
            codigoPostal: null,
            ciudad: null,
            estado: null,
            costoLogistico: 0,
            asesoriaPlacos: false,
            seguro: false,
            nombre: null,
            apellidoPaterno: null,
            apellidoMaterno: null,
            email: null,
            telefono: null,
            fechaNacimiento: null,
            cpDomicilio: null,
            estadoDomicilio: null,
            calle: null,
            numeroExterior: null,
            colonia: null,
            aceptaTerminos: false,
            enganchePorcentaje: 0.30,
            plazoMeses: 12,
            totalPagado: null,
            pagoCompletado: false,
            creditoAprobado: false
        },

        init: function() {
            var self = this;

            // ── Test mode: ?test_credito=preaprobado|condicional|no_viable|truora_fail ──
            //              ?test_credito=score&score=NNN  (custom-score mode)
            // Voltika QA tooling — lets internal testers verify each branch
            // (PREAPROBADO / CONDICIONAL / NO_VIABLE / Truora) end-to-end
            // without needing real CDC test identities.
            var urlParams = new URLSearchParams(window.location.search);
            var testMode  = urlParams.get('test_credito');
            var testScore = urlParams.get('score');

            if (testMode) {
                // Set minimum required state for the resultado screen
                self.state.modeloSeleccionado = 'm05';
                self.state.metodoPago = 'credito';
                self.state.colorSeleccionado = 'negro';
                self.state.enganchePorcentaje = 0.30;
                self.state.plazoMeses = 12;
                self.state._ingresoMensual = 10000;

                if (testMode === 'preaprobado') {
                    // High score, low PTI → green approval screen → Truora → Stripe
                    self.state._buroResult = { score: 720, pagoMensual: 1500, dpd90: false, dpdMax: 0, person_found: true };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                } else if (testMode === 'condicional') {
                    // Círculo de Crédito low score → enganche increase screen
                    self.state._buroResult = { score: 430, pagoMensual: 5000, dpd90: false, dpdMax: 0, person_found: true };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                } else if (testMode === 'no_viable') {
                    // Círculo de Crédito very low score → credit denied screen
                    self.state._buroResult = { score: 350, pagoMensual: 8000, dpd90: true, dpdMax: 120, person_found: true };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                } else if (testMode === 'truora_fail') {
                    // Truora identity verification failed
                    self.state._buroResult = { score: 500, pagoMensual: 3000, dpd90: false, dpdMax: 0, person_found: true };
                    self.state._truoraResult = { status: 'rejected', fallback: false };
                } else if (testMode === 'score' && testScore) {
                    // Custom score (Tony fine-grained QA): ?test_credito=score&score=520
                    self.state._buroResult = { score: parseInt(testScore, 10), pagoMensual: 2000, dpd90: false, dpdMax: 0, person_found: true };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                }

                // Visible banner so testers never confuse this with prod data
                $('body').prepend(
                    '<div id="vk-test-banner" style="background:#f97316;color:#fff;padding:8px 14px;' +
                    'text-align:center;font-size:12px;font-weight:700;position:fixed;top:0;left:0;right:0;' +
                    'z-index:99999;box-shadow:0 2px 6px rgba(0,0,0,.15);">' +
                    '⚠ MODO TEST: ' + testMode + (testScore ? ' (score=' + testScore + ')' : '') +
                    ' &middot; <a href="' + window.location.pathname + '" style="color:#fff;text-decoration:underline;">Salir</a>' +
                    '</div>'
                );

                // Pre-compute _resultadoFinal so credito-loading routes
                // to the right next screen (PREAPROBADO → credito-aprobado,
                // others → credito-resultado → credito-pago auto-advance).
                if (typeof PreaprobacionV3 !== 'undefined') {
                    var _testModelo = self.getModelo(self.state.modeloSeleccionado);
                    if (_testModelo) {
                        var _testCredito = VkCalculadora.calcular(
                            _testModelo.precioContado,
                            self.state.enganchePorcentaje,
                            self.state.plazoMeses
                        );
                        var _br = self.state._buroResult || {};
                        self.state._resultadoFinal = PreaprobacionV3.evaluar({
                            ingreso_mensual_est:  self.state._ingresoMensual,
                            pago_semanal_voltika: _testCredito.pagoSemanal,
                            enganche_pct:         self.state.enganchePorcentaje,
                            score:                _br.score || null,
                            pago_mensual_buro:    _br.pagoMensual || 0,
                            dpd90_flag:           _br.dpd90 || false,
                            dpd_max:              _br.dpdMax || 0,
                            person_found:         (_br.person_found === undefined) ? null : _br.person_found
                        });
                    }
                }

                VkUI.renderProgressBar(4, 'credito');
                setTimeout(function() {
                    // Customer brief 2026-04-26: test URLs should also
                    // play the loading animation so testers see the same
                    // UX as real users. credito-loading auto-advances
                    // based on resultado.status.
                    self.irAPaso('credito-loading');
                }, 500);
                self.bindGlobalEvents();
                return;
            }

            VkUI.renderProgressBar(1, 'credito');
            Paso1.init(this);
            this.bindGlobalEvents();
        },

        getModelo: function(modeloId) {
            var modelos = VOLTIKA_PRODUCTOS.modelos;
            for (var i = 0; i < modelos.length; i++) {
                if (modelos[i].id === modeloId) return modelos[i];
            }
            return null;
        },

        seleccionarModelo: function(modeloId, metodoPago) {
            this.state.modeloSeleccionado = modeloId;
            this.state.metodoPago = metodoPago;
            this.state.colorSeleccionado = null;
            // Credit flux: model → calculator → color
            // Other flux:  model → color → delivery
            if (metodoPago === 'credito') {
                this.irAPaso(4); // Go to credit calculator first
            } else {
                this.irAPaso(2); // Go to color selector
            }
        },

        /**
         * Navigate to a step — accepts number OR string
         * Strings: 'resumen', 'credito-datos', 'credito-otp',
         *          'credito-consentimiento', 'credito-identidad',
         *          'credito-resultado', 'credito-enganche',
         *          'credito-contrato', 'credito-facturacion', 'facturacion', 'exito'
         */
        irAPaso: function(paso) {
            var self = this;
            var $current = $('.vk-paso--active');

            var targetId;
            if (typeof paso === 'number') {
                if (paso === 4) {
                    targetId = self.state.metodoPago === 'credito' ? 'vk-paso-4b' : 'vk-paso-4a';
                } else {
                    targetId = 'vk-paso-' + paso;
                }
            } else {
                targetId = 'vk-paso-' + paso;
            }

            var $target = $('#' + targetId);
            $current.addClass('vk-paso--exit');

            setTimeout(function() {
                $current.removeClass('vk-paso--active vk-paso--exit');
                // Hide fixed model tabs and remove scroll listener when leaving paso 1
                if (paso !== 1) {
                    $('#vk-modelo-nav-fixed').hide();
                    $(window).off('scroll.paso1hint');
                }
                self.inicializarPaso(paso);
                $target.addClass('vk-paso--active');

                // Progress bar: map named pasos to numeric equivalents
                var pasoNum = typeof paso === 'number' ? paso : self._pasoNumerico(paso);
                VkUI.renderProgressBar(pasoNum, self.state.metodoPago);

                if (paso === 1) {
                    $('#vk-paso-1 .vk-paso__header').show();
                }
                $('#vk-scroll-hint').hide();

                VkUI.scrollToTop();
                self.state.pasoActual = paso;
                // Push browser history so back button goes to previous paso
                if (!self._isPopState) {
                    try {
                        history.pushState({ paso: paso }, '', '');
                    } catch(e) {}
                }
                self._isPopState = false;
            }, 280);
        },

        _pasoNumerico: function(paso) {
            var mapa = {
                'resumen': 3,
                'credito-nombre': 3,
                'credito-nacimiento': 3,
                'credito-cp-dom': 3,
                'credito-domicilio': 3,
                'credito-ingresos': 3,
                'credito-datos': 4,
                'credito-otp': 4,
                'credito-consentimiento': 4,
                'credito-loading': 4,
                'credito-aprobado': 4,
                'credito-identidad': 4,
                'credito-resultado': 4,
                'credito-pago': 4,
                'credito-enganche': 4,
                'credito-contrato': 4,
                'credito-autopago': 4,
                'credito-facturacion': 4,
                'facturacion': 4,
                'exito': 4
            };
            return mapa[paso] || 3;
        },

        inicializarPaso: function(paso) {
            switch (paso) {
                case 1:
                    // Paso1 normally renders only at app startup. But if the
                    // user landed on a deep step via ?test_credito=... or via
                    // localStorage restore (credito-resultado / no_viable
                    // screens), Paso1.init() never ran and #vk-modelos-container
                    // is empty. Clicking "Cambiar" on the no-viable screen
                    // (irAPaso(1)) then showed a blank page (customer report
                    // 2026-04-29). Re-initialize lazily when the container has
                    // no rendered content yet — Paso1.render() is idempotent.
                    if ($('#vk-modelos-container').children().length === 0) {
                        Paso1.init(this);
                    }
                    break;
                case 2:
                    Paso2.init(this);
                    break;
                case 3:
                    Paso3.init(this);
                    break;
                case 4:
                    if (this.state.metodoPago === 'credito') {
                        Paso4B.init(this);
                    } else {
                        Paso4A.init(this);
                    }
                    break;
                case 'resumen':
                    PasoResumen.init(this);
                    break;
                case 'credito-nombre':
                    PasoCreditoNombre.init(this);
                    break;
                case 'credito-nacimiento':
                    PasoCreditoNacimiento.init(this);
                    break;
                case 'credito-cp-dom':
                    PasoCreditoCPDom.init(this);
                    break;
                case 'credito-domicilio':
                    PasoCreditoDomicilio.init(this);
                    break;
                case 'credito-ingresos':
                    PasoCreditoIngresos.init(this);
                    break;
                case 'credito-datos':
                    PasoCreditoDatos.init(this);
                    break;
                case 'credito-otp':
                    PasoCreditoOTP.init(this);
                    break;
                case 'credito-consentimiento':
                    PasoCreditoConsentimiento.init(this);
                    break;
                case 'credito-loading':
                    PasoCreditoLoading.init(this);
                    break;
                case 'credito-aprobado':
                    PasoCreditoAprobado.init(this);
                    break;
                case 'credito-identidad':
                    PasoCreditoIdentidad.init(this);
                    break;
                case 'credito-resultado':
                    PasoCreditoResultado.init(this);
                    break;
                case 'credito-pago':
                    PasoCreditoPago.init(this);
                    break;
                case 'credito-enganche':
                    PasoCreditoEnganche.init(this);
                    break;
                case 'credito-contrato':
                    PasoCreditoContrato.init(this);
                    break;
                case 'credito-autopago':
                    PasoCreditoAutopago.init(this);
                    break;
                case 'credito-facturacion':
                    PasoCreditoFacturacion.init(this);
                    break;
                case 'facturacion':
                    PasoFacturacion.init(this);
                    break;
                case 'exito':
                    PasoExito.init(this);
                    break;
            }
        },

        bindGlobalEvents: function() {
            var self = this;

            $(document).on('click', '.vk-back-btn', function() {
                // Use browser history to go to actual previous step
                history.back();
            });

            // Browser back button → go to previous paso instead of leaving page
            window.addEventListener('popstate', function(e) {
                self._isPopState = true;
                if (e.state && e.state.paso !== undefined) {
                    self.irAPaso(e.state.paso);
                } else {
                    // No previous state → go to paso 1
                    if (self.state.pasoActual !== 1) {
                        self.irAPaso(1);
                    }
                }
            });

            // Set initial state
            try {
                history.replaceState({ paso: 1 }, '', '');
            } catch(e) {}

            // Emergency reset: append ?reset=1 to the URL to wipe persisted
            // state and return to paso 1. Useful when the applicant gets
            // stuck on an old cached step after a deploy.
            try {
                if (/[?&]reset=1(\b|$)/.test(location.search)) {
                    sessionStorage.removeItem('vk_configurador_state_v1');
                    history.replaceState({}, '', location.pathname);
                }
            } catch (e) {}

            // ── State persistence (prevents lost work on iOS Safari reload
            //    after camera use, tab kill, or accidental navigation) ────
            // We persist only the safe, serializable parts of state. File
            // blobs are NOT persisted (too large + security). The user still
            // needs to re-pick photos after a reload — but we at least bring
            // them back to the same paso with their form data intact.
            self._installStatePersistence();
        },

        _PERSIST_KEY: 'vk_configurador_state_v1',
        _PERSIST_TTL_MS: 2 * 60 * 60 * 1000, // 2 hours

        _installStatePersistence: function() {
            var self = this;

            // Unsafe-to-resume steps: these depend on in-memory-only objects
            // that sessionStorage can't persist (e.g. _resultadoFinal,
            // _buroResult). Without those, resuming here would show stale UI
            // or skip credit-evaluation gates. If the persisted pasoActual is
            // any of these, rewind to credito-consentimiento so the applicant
            // re-runs the evaluation on this session.
            var RESUME_BLACKLIST = {
                'credito-loading':   'credito-consentimiento',
                'credito-aprobado':  'credito-consentimiento',
                'credito-resultado': 'credito-consentimiento',
            };

            // Restore on load (if recent enough).
            try {
                var raw = sessionStorage.getItem(self._PERSIST_KEY);
                if (raw) {
                    var parsed = JSON.parse(raw);
                    if (parsed && parsed.ts && (Date.now() - parsed.ts) < self._PERSIST_TTL_MS) {
                        // Merge saved fields into current state (excluding File/Blob refs).
                        Object.keys(parsed.state || {}).forEach(function(k) {
                            if (k[0] === '_') return; // skip internal caches
                            self.state[k] = parsed.state[k];
                        });
                        var resumePaso = parsed.state && parsed.state.pasoActual;
                        if (resumePaso && RESUME_BLACKLIST[resumePaso]) {
                            resumePaso = RESUME_BLACKLIST[resumePaso];
                        }
                        if (resumePaso && resumePaso !== 1) {
                            // Resume after a tick so other modules are ready.
                            setTimeout(function() { try { self.irAPaso(resumePaso); } catch (e) {} }, 50);
                        }
                    } else {
                        sessionStorage.removeItem(self._PERSIST_KEY);
                    }
                }
            } catch (e) {}

            // Save on any change. Hook irAPaso so every navigation persists.
            var origIrAPaso = self.irAPaso.bind(self);
            self.irAPaso = function(paso) {
                origIrAPaso(paso);
                self._saveState();
                // Clear state after reaching 'exito' (flow complete).
                if (paso === 'exito') {
                    try { sessionStorage.removeItem(self._PERSIST_KEY); } catch (e) {}
                }
            };

            // Re-render on bfcache restore (Safari returning from camera /
            // back-forward cache): if the page appears from bfcache, our
            // state is intact but the DOM may need re-activation.
            window.addEventListener('pageshow', function(e) {
                if (e.persisted) {
                    // Force the active paso to re-initialize — ensures click
                    // handlers on upload inputs are re-bound after bfcache.
                    try {
                        if (self.state.pasoActual) self.inicializarPaso(self.state.pasoActual);
                    } catch (err) {}
                }
            });
        },

        _saveState: function() {
            var self = this;
            try {
                // Copy only scalar / plain fields — skip File/Blob/Function
                // references (not serializable + privacy).
                //
                // EXCEPTION (customer brief 2026-04-27): a small whitelist
                // of plain-data result objects must persist across page
                // refresh, otherwise the user's CONDICIONAL state is lost
                // on F5 and Paso4B silently reverts to unrestricted mode.
                // These objects contain no PII / Files / Blobs — safe.
                var OBJECT_WHITELIST = {
                    '_resultadoFinal': true,
                    '_buroResult':     true,
                    '_truoraResult':   true
                };
                var safe = {};
                Object.keys(self.state).forEach(function(k) {
                    var v = self.state[k];
                    if (v === null || v === undefined) return;
                    var t = typeof v;
                    if (t === 'string' || t === 'number' || t === 'boolean') {
                        safe[k] = v;
                    } else if (t === 'object' && OBJECT_WHITELIST[k]) {
                        try {
                            // Verify the object is JSON-serializable and
                            // doesn't contain Files/Blobs/cycles before
                            // storing.
                            JSON.stringify(v);
                            safe[k] = v;
                        } catch (e) { /* skip if non-serializable */ }
                    }
                });
                sessionStorage.setItem(self._PERSIST_KEY, JSON.stringify({
                    ts: Date.now(),
                    state: safe
                }));
            } catch (e) {}
        }
    };

    $(document).ready(function() {
        Configurador.init();
    });

    window.VoltikConfigurador = Configurador;

})(jQuery);
