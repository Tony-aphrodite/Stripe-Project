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

            // ── Test mode: ?test_credito=condicional|no_viable|truora_fail ──
            var urlParams = new URLSearchParams(window.location.search);
            var testMode  = urlParams.get('test_credito');

            if (testMode) {
                // Set minimum required state for the resultado screen
                self.state.modeloSeleccionado = 'm05';
                self.state.metodoPago = 'credito';
                self.state.colorSeleccionado = 'negro';
                self.state.enganchePorcentaje = 0.30;
                self.state.plazoMeses = 12;
                self.state._ingresoMensual = 10000;

                if (testMode === 'condicional') {
                    // Círculo de Crédito low score → enganche increase screen
                    self.state._buroResult = { score: 430, pagoMensual: 5000, dpd90: false, dpdMax: 0 };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                } else if (testMode === 'no_viable') {
                    // Círculo de Crédito very low score → credit denied screen
                    self.state._buroResult = { score: 350, pagoMensual: 8000, dpd90: true, dpdMax: 120 };
                    self.state._truoraResult = { status: 'approved', fallback: true };
                } else if (testMode === 'truora_fail') {
                    // Truora identity verification failed
                    self.state._buroResult = { score: 500, pagoMensual: 3000, dpd90: false, dpdMax: 0 };
                    self.state._truoraResult = { status: 'rejected', fallback: false };
                }

                VkUI.renderProgressBar(4, 'credito');
                setTimeout(function() {
                    self.irAPaso('credito-resultado');
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
        }
    };

    $(document).ready(function() {
        Configurador.init();
    });

    window.VoltikConfigurador = Configurador;

})(jQuery);
