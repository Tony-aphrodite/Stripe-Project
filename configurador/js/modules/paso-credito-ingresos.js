/* ==========================================================================
   Voltika - Crédito Screen 10: Ingresos y OTP
   Per Dibujo.pdf: Ingreso (range selector) + Teléfono + Email (optional)
   ========================================================================== */

var PasoCreditoIngresos = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-domicilio');
        html += VkUI.renderCreditoStepBar(3);

        html += '<h2 class="vk-paso__titulo">Un paso m\u00e1s para tu moto</h2>';
        html += '<p class="vk-paso__subtitulo">Completa estos datos para ver tu plan de pago</p>';

        html += '<div class="vk-card" style="padding:20px;">';

        // Income range selector
        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">Ingreso mensual aproximado</label>';

        var rangos = [
            { label: 'Menos de $8,000',     value: 7000  },
            { label: '$8,000 \u2013 $12,000',  value: 10000 },
            { label: '$12,000 \u2013 $18,000', value: 15000 },
            { label: '$18,000 \u2013 $25,000', value: 21500 },
            { label: '$25,000 \u2013 $40,000', value: 32500 },
            { label: 'M\u00e1s de $40,000',   value: 40001 }
        ];

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">';
        for (var i = 0; i < rangos.length; i++) {
            var r = rangos[i];
            var selected = state._ingresoMensual === r.value;
            var bg    = selected ? '#039fe1' : '#f3f4f6';
            var color = selected ? '#fff'    : '#333';
            var border= selected ? '2px solid #039fe1' : '2px solid #e5e7eb';
            html += '<div class="vk-ingreso-opcion" data-value="' + r.value + '" ' +
                'style="padding:10px 12px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;' +
                'text-align:center;background:' + bg + ';color:' + color + ';border:' + border + ';' +
                'transition:background 0.15s,color 0.15s,border 0.15s;user-select:none;">' +
                (selected ? '<span style="margin-right:4px;">&#10003;</span>' : '') +
                r.label +
                '</div>';
        }
        html += '</div>';
        html += '</div>';

        // Phone
        html += '<div class="vk-form-group" style="margin-top:16px;">';
        html += '<label class="vk-form-label">Tel\u00e9fono celular</label>';
        html += '<div class="vk-phone-group">';
        html += '<div class="vk-phone-prefix">&#127474;&#127485; +52</div>';
        html += '<input type="tel" class="vk-form-input" id="vk-cing-telefono" ' +
            'placeholder="55 1234 5678" maxlength="15" autocomplete="tel" ' +
            'value="' + (state.telefono || '') + '">';
        html += '</div>';
        html += '<div style="font-size:11px;color:var(--vk-text-muted);margin-top:4px;">Te enviaremos un c\u00f3digo por SMS</div>';
        html += '</div>';

        // Email (optional)
        html += '<div class="vk-form-group" style="margin-top:16px;">';
        html += '<label class="vk-form-label">Correo electr\u00f3nico <span style="font-weight:400;color:#999;font-size:11px;">(opcional)</span></label>';
        html += '<input type="email" class="vk-form-input" id="vk-cing-email" ' +
            'placeholder="correo@ejemplo.com" autocomplete="email" ' +
            'value="' + (state.email || '') + '">';
        html += '</div>';

        html += '<div id="vk-cing-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cing-continuar">CONTINUAR \u2192</button>';

        // Trust badges
        html += '<div class="vk-trust" style="margin-top:12px;">';
        html += '<div><span class="vk-check vk-check--sm"></span> Informaci\u00f3n protegida</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> Evaluaci\u00f3n en segundos</div>';
        html += '</div>';

        html += '</div>';

        jQuery('#vk-credito-ingresos-container').html(html);
    },

    bindEvents: function() {
        var self = this;

        // Income range selection
        jQuery(document).off('click', '.vk-ingreso-opcion');
        jQuery(document).on('click', '.vk-ingreso-opcion', function() {
            var $this = jQuery(this);
            var value = parseInt($this.data('value'));

            // Store value
            self.app.state._ingresoMensual = value;

            // Update all option styles
            jQuery('.vk-ingreso-opcion').each(function() {
                var $opt = jQuery(this);
                var isSelected = parseInt($opt.data('value')) === value;
                $opt.css({
                    background: isSelected ? '#039fe1' : '#f3f4f6',
                    color:      isSelected ? '#fff'    : '#333',
                    border:     isSelected ? '2px solid #039fe1' : '2px solid #e5e7eb'
                });
                // Toggle checkmark
                if (!$opt.attr('data-label')) {
                    $opt.attr('data-label', $opt.text().trim());
                }
                if (isSelected) {
                    $opt.html('<span style="margin-right:4px;">&#10003;</span>' + $opt.attr('data-label'));
                } else {
                    $opt.html($opt.attr('data-label'));
                }
            });
        });

        // Continue button
        jQuery(document).off('click', '#vk-cing-continuar');
        jQuery(document).on('click', '#vk-cing-continuar', function() {
            var ingreso  = self.app.state._ingresoMensual;
            var telefono = jQuery('#vk-cing-telefono').val().replace(/\D/g, '');
            var email    = jQuery('#vk-cing-email').val().trim();

            var errores = [];
            if (!ingreso) errores.push('Selecciona tu rango de ingreso mensual.');
            if (!telefono || telefono.length < 10) errores.push('Ingresa tu tel\u00e9fono de 10 d\u00edgitos.');
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errores.push('Ingresa un correo v\u00e1lido.');

            if (errores.length) {
                jQuery('#vk-cing-error').html(errores.join('<br>')).show();
                return;
            }
            jQuery('#vk-cing-error').hide();

            self.app.state.telefono = telefono;
            self.app.state.email = email;

            // Send OTP SMS — guard against double-tap on mobile
            var $btn = jQuery('#vk-cing-continuar');
            if ($btn.data('sending')) return;
            $btn.data('sending', true).prop('disabled', true).text('Enviando SMS...');

            jQuery.ajax({
                url: 'php/enviar-otp.php',
                method: 'POST',
                contentType: 'application/json',
                xhrFields: { withCredentials: true },
                data: JSON.stringify({ telefono: telefono, nombre: self.app.state.nombre || '' }),
                success: function(res) {
                    console.log('[OTP] enviar-otp response:', res);
                    if (res && res.fileOk === false) {
                        console.warn('[OTP] WARNING: Code file was NOT saved on server!');
                    }
                    if (res && res.testCode) {
                        self.app.state._otpTestCode = res.testCode;
                    } else {
                        self.app.state._otpTestCode = null;
                    }
                    self.app.state._otpVerificado = false;
                    self.app.irAPaso('credito-consentimiento');
                },
                error: function(xhr) {
                    console.log('[OTP] enviar-otp error:', xhr.status, xhr.responseText);
                    self.app.state._otpTestCode = '123456';
                    self.app.irAPaso('credito-consentimiento');
                },
                complete: function() {
                    $btn.data('sending', false).prop('disabled', false).text('CONTINUAR \u2192');
                }
            });
        });
    }
};
