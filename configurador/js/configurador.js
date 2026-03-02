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
            nombre: null,
            email: null,
            telefono: null,
            fechaNacimiento: null,
            cpDomicilio: null,
            aceptaTerminos: false,
            enganchePorcentaje: 0.30,
            plazoMeses: 12,
            totalPagado: null,
            pagoCompletado: false,
            creditoAprobado: false
        },

        init: function() {
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
            // For credito, skip to paso 4B (calculator) after paso 2
            this.irAPaso(2);
        },

        /**
         * Navigate to a step — accepts number OR string
         * Strings: 'resumen', 'credito-datos', 'credito-otp',
         *          'facturacion', 'exito'
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
                self.inicializarPaso(paso);
                $target.addClass('vk-paso--active');

                // Progress bar: map named pasos to numeric equivalents
                var pasoNum = typeof paso === 'number' ? paso : self._pasoNumerico(paso);
                VkUI.renderProgressBar(pasoNum, self.state.metodoPago);

                if (paso === 1) {
                    $('#vk-scroll-hint').show();
                    $('#vk-paso-1 .vk-paso__header').show();
                } else {
                    $('#vk-scroll-hint').hide();
                }

                VkUI.scrollToTop();
                self.state.pasoActual = paso;
            }, 280);
        },

        _pasoNumerico: function(paso) {
            var mapa = {
                'resumen': 3,
                'credito-datos': 4,
                'credito-otp': 4,
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
                case 'credito-datos':
                    PasoCreditoDatos.init(this);
                    break;
                case 'credito-otp':
                    PasoCreditoOTP.init(this);
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
                var raw = $(this).data('go-to');
                var num = parseInt(raw);
                if (!isNaN(num) && num >= 1) {
                    self.irAPaso(num);
                } else if (raw) {
                    self.irAPaso(String(raw));
                }
            });
        }
    };

    $(document).ready(function() {
        Configurador.init();
    });

    window.VoltikConfigurador = Configurador;

})(jQuery);
