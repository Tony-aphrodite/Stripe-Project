/* ==========================================================================
   Voltika - Éxito / Resumen de Compra
   Final confirmation screen — sends data to email + Zoho
   ========================================================================== */

var PasoExito = {

    init: function(app) {
        this.app = app;
        this._enviarConfirmacion();
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        var base   = window.VK_BASE_PATH || '';

        var html = '';

        // Header
        html += '<div style="text-align:center;padding:20px 0 12px;">';
        html += '<h2 style="font-size:26px;font-weight:800;color:#333;margin:0 0 4px;">\u00a1Listo!</h2>';
        html += '<p style="font-size:17px;color:#333;margin:0;font-weight:600;">Tu Voltika fue apartada &#127881;</p>';
        html += '</div>';

        // Green check circle
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<div style="display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;' +
            'border-radius:50%;background:#4CAF50;">';
        html += '<span style="color:#fff;font-size:32px;">&#10003;</span>';
        html += '</div>';
        html += '</div>';

        // Moto + Asesor images side by side
        html += '<div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:20px;">';
        if (modelo) {
            var motoImg = VkUI.getImagenMoto(modelo.id, state.colorSeleccionado || modelo.colorDefault);
            html += '<img src="' + base + motoImg + '" alt="Voltika" style="width:45%;max-width:160px;height:auto;">';
        }
        html += '<img src="' + base + 'img/asesor_icon.jpg" alt="Asesor Voltika" ' +
            'style="width:45%;max-width:160px;height:auto;border-radius:12px;">';
        html += '</div>';

        // Info card
        html += '<div class="vk-card" style="padding:20px;margin-bottom:16px;">';

        html += '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 14px;">';
        html += 'En m\u00e1ximo <strong style="color:#333;">48 horas</strong>, un asesor <strong style="color:#333;">Voltika</strong> ';
        html += 'te contactar\u00e1 para coordinar la entrega.';
        html += '</p>';

        html += '<p style="font-size:13px;color:#777;margin:0 0 16px;">';
        html += 'Av\u00edsanos si cambias de n\u00famero o email.';
        html += '</p>';

        html += '<div style="margin-bottom:10px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<span class="vk-check vk-check--sm"></span>';
        html += '<span style="font-size:13px;color:#333;">Te contactaremos por <strong>WhatsApp o email</strong></span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<span style="font-size:18px;">&#128241;</span>';
        html += '<span style="font-size:13px;color:#333;">Mantente pendiente de nuestros mensajes</span>';
        html += '</div>';
        html += '</div>';

        html += '<div style="border-top:1px solid #eee;margin:14px 0;"></div>';

        html += '<p style="font-size:14px;color:#333;margin:0;">';
        html += '&#127881; <strong>Felicidades!</strong> Pr\u00f3ximamente recibir\u00e1s tu moto el\u00e9ctrica <strong>Voltika</strong>.';
        html += '</p>';
        html += '<p style="font-size:13px;color:#777;margin:8px 0 0;">Gracias por confiar en nosotros &#129309;</p>';

        html += '</div>';

        // Entendido button
        html += '<button class="vk-btn vk-btn--primary" id="vk-exito-entendido" ' +
            'style="font-size:16px;font-weight:700;margin-bottom:16px;">Entendido</button>';

        // Footer
        html += '<div style="text-align:center;margin-bottom:12px;">';
        html += '<p style="font-size:13px;color:var(--vk-green-primary);font-weight:600;margin:0 0 12px;">Voltika siempre contigo</p>';

        html += '<div style="font-size:13px;color:#555;line-height:1.8;">';
        html += '<div>&#128222; <a href="tel:+525513416370" style="color:#333;text-decoration:none;">+52 55 1341 6370</a></div>';
        html += '<div>&#9993; <a href="mailto:ventas@voltika.mx" style="color:#333;text-decoration:none;">ventas@voltika.mx</a></div>';
        html += '</div>';

        html += '<p style="font-size:11px;color:#999;margin:10px 0 0;">Si necesitas ayuda cont\u00e1ctanos al +52 55 1341 6370</p>';
        html += '</div>';

        jQuery('#vk-exito-container').html(html);
    },

    bindEvents: function() {
        jQuery(document).off('click', '#vk-exito-entendido');
        jQuery(document).on('click', '#vk-exito-entendido', function() {
            window.location.reload();
        });
    },

    _enviarConfirmacion: function() {
        var state  = this.app.state;
        var modelo = this.app.getModelo(state.modeloSeleccionado);
        if (!state.email && !state.nombre) return;

        jQuery.ajax({
            url: 'php/confirmar-pedido.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:    state.nombre,
                email:     state.email,
                telefono:  state.telefono,
                modelo:    modelo ? modelo.nombre : '',
                color:     state.colorSeleccionado || '',
                metodoPago: state.metodoPago,
                ciudad:    state.ciudad,
                estado:    state.estado,
                cp:        state.codigoPostal,
                total:     state.totalPagado || 0,
                asesoriaPlacos: state.asesoriaPlacos || false,
                seguro:         state.seguro || false,
                credito:   state.metodoPago === 'credito' ? {
                    enganchePct: state.enganchePorcentaje,
                    plazoMeses:  state.plazoMeses
                } : null
            })
        });
    }
};
