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
            metodoPago: 'credito',      // default tab
            colorSeleccionado: null,
            codigoPostal: null,
            ciudad: null,
            estado: null,
            costoLogistico: 0,
            nombre: null,
            email: null,
            telefono: null,
            aceptaTerminos: false,
            enganchePorcentaje: 0.30,
            plazoMeses: 12
        },

        /**
         * Initialize the configurator
         */
        init: function() {
            // Hide progress bar on step 1
            VkUI.renderProgressBar(1);

            // Initialize PASO 1
            Paso1.init(this);

            // Bind global navigation
            this.bindGlobalEvents();
        },

        /**
         * Get model data by ID
         */
        getModelo: function(modeloId) {
            var modelos = VOLTIKA_PRODUCTOS.modelos;
            for (var i = 0; i < modelos.length; i++) {
                if (modelos[i].id === modeloId) return modelos[i];
            }
            return null;
        },

        /**
         * Called from PASO 1 when user clicks CONTINUAR
         */
        seleccionarModelo: function(modeloId, metodoPago) {
            this.state.modeloSeleccionado = modeloId;
            this.state.metodoPago = metodoPago;
            this.state.colorSeleccionado = null; // reset for new selection
            this.irAPaso(2);
        },

        /**
         * Navigate to a specific step
         */
        irAPaso: function(numeroPaso) {
            var self = this;
            var $current = $('.vk-paso--active');

            // Determine target element
            var targetId;
            if (numeroPaso === 4) {
                targetId = self.state.metodoPago === 'credito' ? 'vk-paso-4b' : 'vk-paso-4a';
            } else {
                targetId = 'vk-paso-' + numeroPaso;
            }
            var $target = $('#' + targetId);

            // Animate out
            $current.addClass('vk-paso--exit');

            setTimeout(function() {
                // Hide current
                $current.removeClass('vk-paso--active vk-paso--exit');

                // Initialize target step
                self.inicializarPaso(numeroPaso);

                // Show target
                $target.addClass('vk-paso--active');

                // Update progress bar
                VkUI.renderProgressBar(numeroPaso);

                // Update scroll hint visibility
                if (numeroPaso === 1) {
                    $('#vk-scroll-hint').show();
                } else {
                    $('#vk-scroll-hint').hide();
                }

                // Show/hide paso 1 header
                if (numeroPaso === 1) {
                    $('#vk-paso-1 .vk-paso__header').show();
                }

                // Scroll to top
                VkUI.scrollToTop();

                self.state.pasoActual = numeroPaso;
            }, 280);
        },

        /**
         * Initialize the content for a step
         */
        inicializarPaso: function(paso) {
            switch (paso) {
                case 1:
                    // PASO 1 is already rendered on init
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
            }
        },

        /**
         * Bind global events (back buttons, etc.)
         */
        bindGlobalEvents: function() {
            var self = this;

            // Back button navigation
            $(document).on('click', '.vk-back-btn', function() {
                var targetPaso = parseInt($(this).data('go-to'));
                if (targetPaso >= 1) {
                    self.irAPaso(targetPaso);
                }
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        Configurador.init();
    });

    // Expose globally for embedding
    window.VoltikConfigurador = Configurador;

})(jQuery);
