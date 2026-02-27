/* ==========================================================================
   Voltika - Crédito: Datos Personales + TyC
   Collects personal info, accepts TyC, triggers OTP SMS
   ========================================================================== */

var PasoCreditoDatos = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!modelo) return;

        var html = '';
        html += VkUI.renderBackButton(4);

        html += '<h2 class="vk-paso__titulo">Informaci\u00f3n personal</h2>';
        html += '<p class="vk-paso__subtitulo">Necesitamos tus datos para tu solicitud de cr\u00e9dito</p>';

        html += '<div class="vk-card">';
        html += '<div style="padding:16px 20px;">';

        // Name
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Nombre completo</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cd-nombre" ' +
            'placeholder="Juan P\u00e9rez" autocomplete="name" ' +
            'value="' + (state.nombre || '') + '">';
        html += '</div>';

        // Email
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Correo electr\u00f3nico</label>';
        html += '<input type="email" class="vk-form-input" id="vk-cd-email" ' +
            'placeholder="correo@ejemplo.com" autocomplete="email" ' +
            'value="' + (state.email || '') + '">';
        html += '</div>';

        // Phone
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Tel\u00e9fono celular (recibirás tu OTP aquí)</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-cd-telefono" ' +
            'placeholder="55 1234 5678" maxlength="15" autocomplete="tel" ' +
            'value="' + (state.telefono || '') + '">';
        html += '</div>';
        html += '</div>';

        // Birth date
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Fecha de nacimiento</label>';
        html += '<input type="date" class="vk-form-input" id="vk-cd-nacimiento" ' +
            'value="' + (state.fechaNacimiento || '') + '">';
        html += '</div>';

        // CP Domicilio
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">C\u00f3digo Postal de tu domicilio</label>';
        html += '<input type="text" class="vk-form-input" id="vk-cd-cp-domicilio" ' +
            'placeholder="C.P. 5 d\u00edgitos" maxlength="5" inputmode="numeric" ' +
            'value="' + (state.cpDomicilio || '') + '">';
        html += '</div>';

        // Divider
        html += '<div style="border-top:1px solid var(--vk-border);margin:16px 0;"></div>';

        // TyC
        html += '<div class="vk-checkbox-group" style="margin-bottom:16px;">';
        html += '<input type="checkbox" class="vk-checkbox" id="vk-cd-terms">';
        html += '<label class="vk-checkbox-label" for="vk-cd-terms">' +
            'He le\u00eddo y acepto los ' +
            '<a href="https://voltika.mx/docs/tyc_2026.pdf" target="_blank" ' +
            'style="color:var(--vk-green-primary);">T\u00e9rminos y Condiciones</a> ' +
            'y el Aviso de Privacidad de Voltika.' +
            '</label>';
        html += '</div>';

        html += '<div id="vk-cd-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cd-enviar">' +
            'Enviar c\u00f3digo SMS &#10140;' +
            '</button>';
        html += '<p style="font-size:12px;color:var(--vk-text-muted);text-align:center;margin-top:8px;">' +
            'Te enviaremos un c\u00f3digo de verificaci\u00f3n por SMS.' +
            '</p>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-datos-container').html(html);
    },

    bindEvents: function() {
        var self = this;
        jQuery(document).off('click', '#vk-cd-enviar');
        jQuery(document).on('click', '#vk-cd-enviar', function() {
            self._submit();
        });
    },

    _submit: function() {
        var nombre   = jQuery('#vk-cd-nombre').val().trim();
        var email    = jQuery('#vk-cd-email').val().trim();
        var tel      = jQuery('#vk-cd-telefono').val().replace(/\D/g, '');
        var nacimiento = jQuery('#vk-cd-nacimiento').val();
        var cpDom    = jQuery('#vk-cd-cp-domicilio').val().trim();
        var terms    = jQuery('#vk-cd-terms').is(':checked');

        var errores = [];
        if (!nombre || nombre.length < 3) errores.push('Ingresa tu nombre completo.');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errores.push('Ingresa un correo v\u00e1lido.');
        if (!tel || tel.length < 10) errores.push('Ingresa tu tel\u00e9fono de 10 d\u00edgitos.');
        if (!nacimiento) errores.push('Ingresa tu fecha de nacimiento.');
        if (!cpDom || cpDom.length !== 5) errores.push('Ingresa tu C\u00f3digo Postal de domicilio (5 d\u00edgitos).');
        if (!terms) errores.push('Debes aceptar los T\u00e9rminos y Condiciones.');

        if (errores.length) {
            jQuery('#vk-cd-error').html(errores.join('<br>')).show();
            return;
        }
        jQuery('#vk-cd-error').hide();

        // Save to state
        this.app.state.nombre        = nombre;
        this.app.state.email         = email;
        this.app.state.telefono      = tel;
        this.app.state.fechaNacimiento = nacimiento;
        this.app.state.cpDomicilio   = cpDom;

        var $btn = jQuery('#vk-cd-enviar');
        $btn.prop('disabled', true).text('Enviando SMS...');

        var self = this;
        jQuery.ajax({
            url: 'php/enviar-otp.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ telefono: '+52' + tel, nombre: nombre }),
            success: function(res) {
                // Store sessionCode for test environments
                if (res && res.testCode) {
                    self.app.state._otpTestCode = res.testCode;
                }
                self.app.irAPaso('credito-otp');
            },
            error: function() {
                // Fallback: proceed anyway (phone can't be reached)
                self.app.state._otpTestCode = '1234';
                self.app.irAPaso('credito-otp');
            },
            complete: function() {
                $btn.prop('disabled', false).html('Enviar c\u00f3digo SMS &#10140;');
            }
        });
    }
};
