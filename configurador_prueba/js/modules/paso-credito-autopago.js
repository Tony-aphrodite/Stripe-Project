/* ==========================================================================
   Voltika - Credito: Activar Pago Automatico
   Image 3: Stripe card registration for recurring weekly payments
   - SetupIntent (no charge now, just save card)
   - Card form: number, expiry, CVV
   - "Activar mis pagos" button
   - Clear messaging: no charge until delivery
   ========================================================================== */

var PasoCreditoAutopago = {

    _stripe: null,
    _elements: null,
    _cardNumberElement: null,
    _cardExpiryElement: null,
    _cardCvcElement: null,

    STRIPE_PUBLISHABLE_KEY: 'pk_test_51Rr5XCDPx1FQbvVSr8odW16SQzUgPoyMJroHp5emN9PttKU4oHs0jAOsBpxM50ISn4xmUzemZomXX6IuIrQN8FRB00NM2T7eGp',
    SETUP_INTENT_URL: 'php/create-setup-intent.php',

    init: function(app) {
        this.app = app;
        this._stripe = null;
        this._cardNumberElement = null;
        this._cardExpiryElement = null;
        this._cardCvcElement = null;
        // Verify previous step
        if (!app.state.contratoFirmado) {
            console.warn('PasoCreditoAutopago: contract not signed, state may be incomplete');
        }
        this.render();
        this.bindEvents();
        var self = this;
        setTimeout(function() { self._mountStripe(); }, 300);
    },

    render: function() {
        var html = '';

        html += VkUI.renderBackButton('credito-contrato');

        // === Header ===
        html += '<div style="text-align:center;margin-bottom:20px;padding-top:10px;">';
        html += '<h2 style="font-size:24px;font-weight:800;color:#333;margin:0 0 8px;">Activa tu pago autom\u00e1tico</h2>';
        html += '<p style="font-size:14px;color:#555;margin:0;">Configura el m\u00e9todo de pago para tu <strong>cr\u00e9dito Voltika</strong>.</p>';
        html += '</div>';

        // === Benefits checkmarks ===
        html += '<div style="margin-bottom:20px;padding:0 4px;">';
        var benefits = [
            'Se activar\u00e1 solo cuando recibas tu Voltika',
            'No tendr\u00e1s que recordar pagos',
            'Puedes adelantar pagos cuando quieras'
        ];
        for (var i = 0; i < benefits.length; i++) {
            html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
            html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#4CAF50;flex-shrink:0;">';
            html += '<span style="color:#fff;font-size:12px;">&#10003;</span></span>';
            html += '<span style="font-size:15px;font-weight:600;color:#333;">' + benefits[i] + '</span>';
            html += '</div>';
        }
        html += '</div>';

        // === No charge now notice ===
        html += '<div style="display:flex;align-items:flex-start;gap:10px;background:#FFF8E1;border-radius:10px;padding:14px;margin-bottom:20px;border:1px solid #FFE082;">';
        html += '<span style="font-size:20px;flex-shrink:0;">&#128161;</span>';
        html += '<div>';
        html += '<div style="font-size:14px;font-weight:800;color:#333;margin-bottom:4px;">No se realizar\u00e1 ning\u00fan cargo ahora</div>';
        html += '<div style="font-size:13px;color:#555;line-height:1.5;">Tu pago autom\u00e1tico solo se activar\u00e1 cuando recibas y aceptes tu Voltika en la entrega.</div>';
        html += '</div>';
        html += '</div>';

        // === Card form ===
        html += '<div class="vk-card" style="padding:20px;margin-bottom:16px;">';

        // Card number
        html += '<div style="margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;border:1.5px solid #ddd;border-radius:8px;padding:14px;background:#fff;" id="vk-autopago-number-wrap">';
        html += '<span style="font-size:16px;color:#999;">&#128179;</span>';
        html += '<div id="vk-autopago-card-number" style="flex:1;min-height:20px;"></div>';
        html += '</div>';
        html += '</div>';

        // Expiry + CVV row
        html += '<div style="display:flex;gap:12px;margin-bottom:14px;">';
        // Expiry
        html += '<div style="flex:1;">';
        html += '<div style="display:flex;align-items:center;gap:8px;border:1.5px solid #ddd;border-radius:8px;padding:14px;background:#fff;" id="vk-autopago-expiry-wrap">';
        html += '<span style="font-size:14px;color:#999;">&#128197;</span>';
        html += '<div id="vk-autopago-card-expiry" style="flex:1;min-height:20px;"></div>';
        html += '</div>';
        html += '</div>';
        // CVV
        html += '<div style="flex:1;">';
        html += '<div style="display:flex;align-items:center;gap:8px;border:1.5px solid #ddd;border-radius:8px;padding:14px;background:#fff;" id="vk-autopago-cvc-wrap">';
        html += '<span style="font-size:14px;color:#999;">&#128274;</span>';
        html += '<div id="vk-autopago-card-cvc" style="flex:1;min-height:20px;"></div>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Error display
        html += '<div id="vk-autopago-error" style="display:none;color:#C62828;font-size:13px;background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA: Activar mis pagos
        html += '<button class="vk-btn vk-btn--primary" id="vk-autopago-activar" ' +
            'style="font-size:16px;font-weight:800;padding:16px;margin-bottom:12px;">';
        html += '<span id="vk-autopago-btn-label">Activar mis pagos</span>';
        html += '<span id="vk-autopago-btn-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Procesando...</span>';
        html += '</button>';

        html += '</div>'; // end card

        // === Bottom info ===
        html += '<div style="padding:0 4px;margin-bottom:16px;">';

        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">';
        html += '<span style="font-size:16px;">&#128274;</span>';
        html += '<span style="font-size:14px;font-weight:700;color:#333;">Sin cargos antes de tu entrega</span>';
        html += '</div>';

        html += '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:16px;">';
        html += '<span style="font-size:16px;flex-shrink:0;">&#128161;</span>';
        html += '<span style="font-size:13px;color:#555;line-height:1.5;"><strong>Podr\u00e1s cambiar tu tarjeta</strong> cuando quieras desde tu cuenta Voltika.</span>';
        html += '</div>';

        html += '</div>';

        jQuery('#vk-credito-autopago-container').html(html);
    },

    _mountStripe: function() {
        var self = this;

        if (typeof Stripe === 'undefined') {
            jQuery('#vk-autopago-card-number').html(
                '<p style="color:#C62828;font-size:13px;">&#9888; Error: Stripe.js no disponible.</p>'
            );
            return;
        }

        if (self._stripe) return;

        self._stripe   = Stripe(self.STRIPE_PUBLISHABLE_KEY);
        self._elements = self._stripe.elements({ locale: 'es' });

        var style = {
            base: {
                fontFamily: 'Inter, Arial, sans-serif',
                fontSize: '16px',
                color: '#111827',
                '::placeholder': { color: '#9CA3AF' }
            },
            invalid: { color: '#C62828' }
        };

        // Card number
        self._cardNumberElement = self._elements.create('cardNumber', {
            style: style,
            placeholder: 'N\u00famero de tarjeta'
        });
        self._cardNumberElement.mount('#vk-autopago-card-number');

        // Expiry
        self._cardExpiryElement = self._elements.create('cardExpiry', {
            style: style,
            placeholder: 'MM / AA'
        });
        self._cardExpiryElement.mount('#vk-autopago-card-expiry');

        // CVC
        self._cardCvcElement = self._elements.create('cardCvc', {
            style: style,
            placeholder: 'CVV'
        });
        self._cardCvcElement.mount('#vk-autopago-card-cvc');

        // Error handling for all elements
        var handleChange = function(event) {
            var $err = jQuery('#vk-autopago-error');
            if (event.error) {
                $err.text(event.error.message).show();
            } else {
                $err.hide();
            }
        };
        self._cardNumberElement.on('change', handleChange);
        self._cardExpiryElement.on('change', handleChange);
        self._cardCvcElement.on('change', handleChange);

        // Focus styles
        self._cardNumberElement.on('focus', function() { jQuery('#vk-autopago-number-wrap').css('border-color', '#039fe1'); });
        self._cardNumberElement.on('blur', function() { jQuery('#vk-autopago-number-wrap').css('border-color', '#ddd'); });
        self._cardExpiryElement.on('focus', function() { jQuery('#vk-autopago-expiry-wrap').css('border-color', '#039fe1'); });
        self._cardExpiryElement.on('blur', function() { jQuery('#vk-autopago-expiry-wrap').css('border-color', '#ddd'); });
        self._cardCvcElement.on('focus', function() { jQuery('#vk-autopago-cvc-wrap').css('border-color', '#039fe1'); });
        self._cardCvcElement.on('blur', function() { jQuery('#vk-autopago-cvc-wrap').css('border-color', '#ddd'); });
    },

    bindEvents: function() {
        var self = this;

        jQuery(document).off('click', '#vk-autopago-activar');
        jQuery(document).on('click', '#vk-autopago-activar', function(e) {
            e.preventDefault();
            self._handleSetup();
        });
    },

    _handleSetup: function() {
        var self  = this;
        var state = self.app.state;

        if (!self._stripe || !self._cardNumberElement) {
            jQuery('#vk-autopago-error').text('El m\u00f3dulo de pago no est\u00e1 listo. Recarga la p\u00e1gina.').show();
            return;
        }

        self._setLoading(true);
        jQuery('#vk-autopago-error').hide();

        // Step 1: Create SetupIntent on backend
        jQuery.ajax({
            url: (window.VK_BASE_PATH || '') + self.SETUP_INTENT_URL,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:   state.nombre,
                email:    state.email,
                telefono: state.telefono
            }),
            success: function(response) {
                if (!response || !response.clientSecret) {
                    // If backend not ready, simulate success for testing
                    console.warn('SetupIntent backend not available, simulating...');
                    self._simulateSuccess();
                    return;
                }

                // Step 2: Confirm SetupIntent with card
                self._stripe.confirmCardSetup(response.clientSecret, {
                    payment_method: {
                        card: self._cardNumberElement,
                        billing_details: {
                            name:  state.nombre,
                            email: state.email,
                            phone: state.telefono ? '+52' + state.telefono : undefined
                        }
                    }
                }).then(function(result) {
                    if (result.error) {
                        jQuery('#vk-autopago-error').text(result.error.message).show();
                        self._setLoading(false);
                    } else if (result.setupIntent && (result.setupIntent.status === 'succeeded' || result.setupIntent.status === 'processing')) {
                        state.autopagoActivado = true;
                        state._setupIntentId = result.setupIntent.id;
                        state._stripeCustomerId = response.customerId || null;

                        // Persist to backend so collections can charge weekly.
                        // We WAIT for the response so a backend failure blocks
                        // the UI from advancing to the final success step
                        // (previously this was fire-and-forget and masked errors).
                        var modelo = self.app.getModelo(state.modeloSeleccionado);
                        var pagoSemanal = 0;
                        try {
                            pagoSemanal = VkCalculadora.calcular(
                                modelo.precioContado,
                                state.enganchePorcentaje || 0.30,
                                state.plazoMeses || 36
                            ).pagoSemanal;
                        } catch (err) { /* fallback to 0 */ }

                        jQuery.ajax({
                            url: (window.VK_BASE_PATH || '') + 'php/confirmar-autopago.php',
                            method: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({
                                setupIntentId:   result.setupIntent.id,
                                customerId:      response.customerId,
                                paymentMethodId: result.setupIntent.payment_method || null,
                                montoSemanal:    pagoSemanal,
                                nombre:          state.nombre,
                                email:           state.email,
                                telefono:        state.telefono,
                                modelo:          (modelo && modelo.nombre) || state.modeloSeleccionado,
                                color:           state.colorSeleccionado || (modelo && modelo.colorDefault) || '',
                                precioContado:   (modelo && modelo.precioContado) || 0,
                                plazoMeses:      state.plazoMeses || 36
                            }),
                            success: function(resp) {
                                self._setLoading(false);
                                self.app.irAPaso('credito-facturacion');
                            },
                            error: function() {
                                self._setLoading(false);
                                jQuery('#vk-autopago-error')
                                    .text('No pudimos guardar tu método de pago automático. ' +
                                          'Tu tarjeta no fue cobrada. Por favor intenta de nuevo ' +
                                          'o contacta soporte.')
                                    .show();
                                // NO avanzamos — el cliente necesita retry válido.
                            }
                        });
                    } else {
                        // Unexpected status
                        jQuery('#vk-autopago-error').text('No se pudo procesar la tarjeta. Intenta de nuevo.').show();
                        self._setLoading(false);
                    }
                });
            },
            error: function(xhr) {
                self._setLoading(false);
                if (window.VK_DEBUG_SIMULATE_AUTOPAGO === true) {
                    // Sólo simula si el debug flag está explícitamente activo
                    // — evita que un backend caído parezca un éxito en prod.
                    console.warn('VK_DEBUG: SetupIntent backend not available, simulating.');
                    self._simulateSuccess();
                    return;
                }
                var msg = 'No pudimos inicializar el pago automático. ' +
                          'Verifica tu conexión o intenta más tarde. ' +
                          'Tu tarjeta no ha sido cobrada.';
                jQuery('#vk-autopago-error').text(msg).show();
            }
        });
    },

    _simulateSuccess: function() {
        var self = this;
        // TEST-ONLY: never reachable unless window.VK_DEBUG_SIMULATE_AUTOPAGO is true.
        if (window.VK_DEBUG_SIMULATE_AUTOPAGO !== true) {
            console.error('VK: _simulateSuccess() called without debug flag — blocking.');
            return;
        }
        setTimeout(function() {
            self.app.state.autopagoActivado = true;
            self.app.state._setupIntentId = 'simulated_' + Date.now();
            self._setLoading(false);
            self.app.irAPaso('credito-facturacion');
        }, 2000);
    },

    _setLoading: function(isLoading) {
        var $btn = jQuery('#vk-autopago-activar');
        if (isLoading) {
            $btn.prop('disabled', true);
            jQuery('#vk-autopago-btn-label').hide();
            jQuery('#vk-autopago-btn-spinner').show();
        } else {
            $btn.prop('disabled', false);
            jQuery('#vk-autopago-btn-label').show();
            jQuery('#vk-autopago-btn-spinner').hide();
        }
    }
};
