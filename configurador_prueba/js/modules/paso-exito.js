/* ==========================================================================
   Voltika - Exito / Confirmacion Final
   Image 4: Credit success - "¡Listo! Tu Voltika fue apartada 🚀"
   Image 5: Contado/MSI success - "¡Compra confirmada!"
   - Celebration header with icons
   - 48hr asesor contact info
   - Security key (SMS code on delivery)
   - WhatsApp/email contact
   - "Entendido" button → reload
   ========================================================================== */

var PasoExito = {

    init: function(app) {
        this.app = app;
        // Email already sent by confirmar-orden.php — no duplicate
        this.render();
        this.bindEvents();
    },

    render: function() {
        var state   = this.app.state;
        var modelo  = this.app.getModelo(state.modeloSeleccionado);
        var base    = window.VK_BASE_PATH || '';
        var esCredito = state.metodoPago === 'credito';

        if (esCredito) {
            this._renderCredito(state, modelo, base);
        } else {
            this._renderContado(state, modelo, base);
        }
    },

    // ===============================
    // IMAGE 4: Credit success screen
    // ===============================
    _renderCredito: function(state, modelo, base) {
        var html = '';

        // === Celebration header ===
        html += '<div style="text-align:center;padding:24px 0 16px;position:relative;">';
        // Confetti-like decorations (CSS)
        html += '<div style="display:inline-block;margin-bottom:12px;">';
        html += '<img src="' + base + 'img/Archivo 4/last_elements-07.png" alt="" style="width:280px;max-width:90%;height:auto;">';
        html += '</div>';
        html += '<h2 style="font-size:26px;font-weight:800;color:#333;margin:0 0 4px;">\u00a1Listo!</h2>';
        html += '<p style="font-size:18px;color:#333;margin:0;font-weight:700;">Tu Voltika fue apartada &#127881;</p>';
        html += '</div>';


        // === Contact info card ===
        html += '<div class="vk-card" style="padding:20px;margin-bottom:16px;">';

        // Show registered phone
        var _tel = state.telefono || '';
        if (_tel) {
            var _telFmt = '+52 ' + _tel.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3');
            html += '<div style="text-align:center;margin-bottom:12px;font-size:14px;color:#333;">Registrado con: <strong>' + _telFmt + '</strong></div>';
        }

        html += '<p style="font-size:14px;color:#555;line-height:1.7;margin:0 0 12px;">';
        html += 'En m\u00e1ximo <strong style="color:#333;">48 horas</strong>, un asesor <strong style="color:#333;">Voltika</strong> ';
        html += 'te contactar\u00e1 para coordinar la entrega.<br>';
        html += '<span style="font-size:13px;color:#888;">Av\u00edsanos si cambias de n\u00famero o email.</span>';
        html += '</p>';

        // Contact badges
        html += '<div style="background:#F5F5F5;border-radius:10px;padding:12px;margin-bottom:14px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<img src="' + base + 'img/Archivo 4/last_elements-10.png" style="width:36px;height:36px;object-fit:contain;">';
        html += '<span style="font-size:13px;color:#333;">Te contactaremos por <strong>WhatsApp o email</strong></span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<img src="' + base + 'img/Archivo 4/last_elements-11.png" style="width:36px;height:36px;object-fit:contain;">';
        html += '<span style="font-size:13px;color:#333;"><strong>Mantente</strong> pendiente de nuestros mensajes</span>';
        html += '</div>';
        html += '</div>';

        // Security key section (before Felicidades)
        html += '<div style="background:#F3E8FD;border-radius:12px;padding:18px;margin:14px 0;border:1px solid #E0D0F0;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">';
        html += '<img src="' + base + 'img/Archivo 4/last_elements-05.png" style="width:42px;height:42px;object-fit:contain;">';
        html += '<span style="font-size:15px;font-weight:800;color:#333;">Tu tel\u00e9fono ser\u00e1 tu llave de seguridad</span>';
        html += '</div>';
        html += '<p style="font-size:14px;color:#555;line-height:1.6;margin:0 0 10px;">Para proteger tu compra, la entrega de tu Voltika se autoriza <strong>\u00fanicamente</strong> con un c\u00f3digo <strong>SMS</strong> enviado a tu tel\u00e9fono.</p>';
        html += '<p style="font-size:14px;color:#555;line-height:1.6;margin:0;">El d\u00eda de la entrega recibir\u00e1s un <strong>c\u00f3digo de confirmaci\u00f3n</strong> que deber\u00e1s mostrar para recibir tu moto.</p>';
        html += '</div>';

        // Felicidades
        html += '<div style="border-top:1px solid #eee;padding-top:14px;">';
        html += '<p style="font-size:15px;color:#333;margin:0;">';
        html += '<span style="color:#4CAF50;font-weight:700;">&#10003; Felicidades!</span> Pr\u00f3ximamente recibir\u00e1s tu moto el\u00e9ctrica <strong>Voltika</strong>.</p>';
        html += '<p style="font-size:13px;color:#777;margin:8px 0 0;">Gracias por confiar en nosotros &#128522;</p>';
        html += '</div>';

        html += '</div>'; // end card

        // === Entendido button ===
        html += '<button class="vk-btn vk-btn--primary" id="vk-exito-entendido" ' +
            'style="font-size:16px;font-weight:800;padding:16px;margin-bottom:16px;">Entendido</button>';

        // === Footer ===
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<p style="font-size:13px;color:var(--vk-green-primary);font-weight:700;margin:0 0 12px;">Voltika siempre contigo</p>';

        html += '<div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:12px;">';
        html += '<a href="https://wa.me/525513416370" target="_blank" style="display:flex;align-items:center;gap:6px;text-decoration:none;color:#333;font-size:13px;">';
        html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#25D366;">';
        html += '<span style="color:#fff;font-size:13px;">&#128172;</span></span>';
        html += '+52 55 1341 6370</a>';

        html += '<a href="mailto:ventas@voltika.mx" style="display:flex;align-items:center;gap:6px;text-decoration:none;color:#333;font-size:13px;">';
        html += '<span style="font-size:16px;">&#9993;</span>';
        html += 'ventas@voltika.mx</a>';
        html += '</div>';

        html += '<p style="font-size:11px;color:#999;margin:0;">Si necesitas ayuda, cont\u00e1ctanos al <strong>+52 55 1341 6370</strong></p>';
        html += '</div>';

        jQuery('#vk-exito-container').html(html);
    },

    // ===================================
    // IMAGE 5: Contado/MSI success screen
    // ===================================
    _renderContado: function(state, modelo, base) {
        var html = '';

        // === Celebration header ===
        html += '<div style="text-align:center;padding:24px 0 16px;">';
        // Big WhatsApp-style check + rocket
        html += '<div style="display:inline-flex;align-items:center;gap:4px;margin-bottom:16px;">';
        html += '<div style="width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#25D366,#128C7E);display:inline-flex;align-items:center;justify-content:center;">';
        html += '<span style="color:#fff;font-size:36px;">&#10003;</span>';
        html += '</div>';
        html += '';
        html += '</div>';

        if (state._pagoPendiente) {
            html += '<h2 style="font-size:26px;font-weight:800;color:#333;margin:0 0 6px;">Tu Voltika te est\u00e1 esperando.</h2>';
            html += '<p style="font-size:14px;color:#555;margin:0;">Completa tu pago para asegurar tu <strong>Voltika</strong>.</p>';
        } else {
            html += '<h2 style="font-size:26px;font-weight:800;color:#333;margin:0 0 6px;">\u00a1Compra confirmada!</h2>';
            html += '<p style="font-size:14px;color:#555;margin:0;">Tu <strong>Voltika</strong> ya est\u00e1 en preparaci\u00f3n para entrega.</p>';
        }
        html += '</div>';

        // === Asesor contact card ===
        html += '<div class="vk-card" style="padding:20px;margin-bottom:16px;">';

        // Show registered phone
        var _tel2 = state.telefono || '';
        if (_tel2) {
            var _telFmt2 = '+52 ' + _tel2.replace(/(\d{2})(\d{4})(\d{4})/, '$1 $2 $3');
            html += '<div style="text-align:center;margin-bottom:12px;font-size:14px;color:#333;">Registrado con: <strong>' + _telFmt2 + '</strong></div>';
        }

        html += '<p style="font-size:14px;color:#555;line-height:1.7;margin:0 0 14px;">';
        html += 'En m\u00e1ximo <strong style="color:#333;">48 horas</strong>, un asesor <strong style="color:#333;">Voltika</strong> ';
        html += 'te contactar\u00e1 para:';
        html += '</p>';

        // Checklist
        html += '<div style="margin-bottom:14px;padding-left:4px;">';
        var items = [
            '<strong>Confirmar</strong> el punto de <strong>entrega</strong>',
            '<strong>Coordinar</strong> fecha y <strong>horario</strong>',
            'Resolver cualquier duda'
        ];
        for (var i = 0; i < items.length; i++) {
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
            html += '<span style="color:#4CAF50;font-size:16px;font-weight:700;">&#10003;</span>';
            html += '<span style="font-size:14px;color:#333;">' + items[i] + '</span>';
            html += '</div>';
        }
        html += '</div>';

        html += '</div>'; // end card

        // === Security key section ===
        html += '<div class="vk-card" style="padding:18px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">';
        html += '<span style="font-size:22px;flex-shrink:0;">&#128274;</span>';
        html += '<div style="font-size:16px;font-weight:800;color:#333;">Tu tel\u00e9fono ser\u00e1 tu llave de seguridad</div>';
        html += '</div>';

        html += '<p style="font-size:13px;color:#555;line-height:1.6;margin:0 0 12px;">';
        html += 'Para proteger tu compra, la entrega de tu Voltika se autoriza <strong>\u00fanicamente</strong> con un c\u00f3digo SMS enviado a tu tel\u00e9fono.';
        html += '</p>';

        html += '<p style="font-size:13px;color:#555;line-height:1.6;margin:0;">';
        html += 'El d\u00eda de la entrega recibir\u00e1s un <strong>c\u00f3digo de confirmaci\u00f3n</strong> que deber\u00e1s mostrar para recibir tu moto.';
        html += '</p>';
        html += '</div>';

        // === Contact badges ===
        html += '<div style="background:#F5F5F5;border-radius:10px;padding:14px;margin-bottom:16px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#4CAF50;">';
        html += '<span style="color:#fff;font-size:11px;">&#10003;</span></span>';
        html += '<span style="font-size:13px;color:#333;">Te contactaremos por <strong>WhatsApp o email</strong></span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<span style="font-size:18px;">&#128276;</span>';
        html += '<span style="font-size:13px;color:#333;"><strong>Mantente</strong> pendiente de nuestros mensajes</span>';
        html += '</div>';
        html += '</div>';

        // === Felicidades ===
        html += '<div style="margin-bottom:16px;padding:0 4px;">';
        html += '<p style="font-size:15px;color:#333;margin:0;">';
        html += '<span style="color:#4CAF50;font-weight:700;">&#10003; Felicidades!</span> Pr\u00f3ximamente recibir\u00e1s tu <strong>Voltika</strong>.</p>';
        html += '<p style="font-size:13px;color:#777;margin:6px 0 0;">Gracias por confiar en nosotros &#128522;</p>';
        html += '</div>';

        // === Entendido button ===
        html += '<button class="vk-btn vk-btn--primary" id="vk-exito-entendido" ' +
            'style="font-size:16px;font-weight:800;padding:16px;margin-bottom:16px;">Entendido</button>';

        // === Footer ===
        html += '<div style="text-align:center;margin-bottom:20px;">';

        html += '<div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:12px;">';
        html += '<a href="https://wa.me/525513416370" target="_blank" style="display:flex;align-items:center;gap:6px;text-decoration:none;color:#333;font-size:13px;">';
        html += '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#25D366;">';
        html += '<span style="color:#fff;font-size:13px;">&#128172;</span></span>';
        html += '+52 55 1341 6370</a>';

        html += '<a href="mailto:ventas@voltika.mx" style="display:flex;align-items:center;gap:6px;text-decoration:none;color:#333;font-size:13px;">';
        html += '<span style="font-size:16px;">&#9993;</span>';
        html += 'ventas@voltika.mx</a>';
        html += '</div>';

        html += '<p style="font-size:11px;color:#999;margin:0;">Si necesitas ayuda, cont\u00e1ctanos al <strong>+52 55 1341 6370</strong></p>';
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
        if (!state.email || !state.nombre) return;

        jQuery.ajax({
            url: (window.VK_BASE_PATH || '') + 'php/confirmar-pedido.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                nombre:     state.nombre,
                email:      state.email,
                telefono:   state.telefono,
                modelo:     modelo ? modelo.nombre : '',
                color:      state.colorSeleccionado || '',
                metodoPago: state.metodoPago,
                ciudad:     state.ciudad,
                estado:     state.estado,
                cp:         state.codigoPostal,
                total:      state.totalPagado || 0,
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
