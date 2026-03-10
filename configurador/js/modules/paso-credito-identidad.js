/* ==========================================================================
   Voltika - Crédito: Verificación de Identidad (INE + Selfie)
   Document upload + selfie capture via camera or file input
   ========================================================================== */

var PasoCreditoIdentidad = {

    _ineFrente: null,
    _ineReverso: null,
    _selfie: null,

    init: function(app) {
        this.app = app;
        this._ineFrente = null;
        this._ineReverso = null;
        this._selfie = null;
        this.render();
        this.bindEvents();
    },

    render: function() {
        var html = '';

        // 1. Logo
        html += '<div class="vk-identidad-logo">';
        html += '<img src="img/voltika_logo_h.svg" alt="Voltika">';
        html += '</div>';

        // 2. Progress indicator
        html += '<div class="vk-identidad-progress">';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--done">';
        html += '<span class="vk-identidad-progress__num">&#10003;</span>';
        html += '<span class="vk-identidad-progress__label">Cr\u00e9dito aprobado</span>';
        html += '</div>';
        html += '<div class="vk-identidad-progress__line"></div>';
        html += '<div class="vk-identidad-progress__step vk-identidad-progress__step--active">';
        html += '<span class="vk-identidad-progress__num">2</span>';
        html += '<span class="vk-identidad-progress__label">Confirmar identidad</span>';
        html += '</div>';
        html += '</div>';

        // 3. Title & subtitle
        html += '<h2 class="vk-identidad-title">Confirma tu identidad</h2>';
        html += '<p class="vk-identidad-subtitle">Este es el \u00faltimo paso para activar tu cr\u00e9dito Voltika y liberar la entrega de tu moto.</p>';

        // 4. Security info box
        html += '<div class="vk-identidad-security">';
        html += '<div class="vk-identidad-security__icon">&#128274;</div>';
        html += '<div>';
        html += '<div class="vk-identidad-security__title">Tu informaci\u00f3n est\u00e1 protegida y cifrada.</div>';
        html += '<div class="vk-identidad-security__text">Voltika utiliza verificaci\u00f3n segura para proteger tu cr\u00e9dito y evitar fraudes.</div>';
        html += '</div>';
        html += '</div>';

        // 5. Upload steps
        html += this._renderUploadStep(1, 'ine-frente',  'INE \u2013 Frente',           'Toma una foto clara del <strong>frente</strong> de tu INE',  'ine-front');
        html += this._renderUploadStep(2, 'ine-reverso', 'INE \u2013 Reverso',          'Toma una foto clara del <strong>reverso</strong> de tu INE', 'ine-back');
        html += this._renderUploadStep(3, 'selfie',      'Selfie de verificaci\u00f3n', 'Toma una foto de tu rostro mirando a la c\u00e1mara',         'selfie');

        // 6. 30-second notice
        html += '<div class="vk-identidad-timer">';
        html += '<span style="font-size:15px;">&#9201;</span> Este proceso toma <strong>menos de 30 segundos</strong>';
        html += '</div>';

        // Error
        html += '<div id="vk-identidad-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // 7. CTA button — always active, validates on click
        html += '<button class="vk-btn vk-btn--primary" id="vk-identidad-continuar" style="text-transform:uppercase;letter-spacing:0.5px;">' +
            '<span id="vk-identidad-label">Continuar y confirmar mi identidad &rsaquo;</span>' +
            '<span id="vk-identidad-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Verificando...</span>' +
            '</button>';

        // 8. Consejos rápidos
        html += '<div class="vk-identidad-consejos">';
        html += '<div class="vk-identidad-consejos__title">Consejos r\u00e1pidos</div>';
        html += '<div class="vk-identidad-consejos__grid">';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Buena iluminaci\u00f3n</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Documento completo</div>';
        html += '<div class="vk-identidad-consejos__item"><span class="vk-identidad-check">&#10003;</span> Selfie con rostro despejado</div>';
        html += '</div>';
        html += '</div>';

        // 9. Footer text
        html += '<p class="vk-identidad-footer">Tu moto permanecer\u00e1 reservada mientras completas este paso.</p>';

        jQuery('#vk-credito-identidad-container').html(html);
    },

    _svgINE: function(isBack) {
        // SVG illustration of Mexican INE card
        var bg = isBack ? '#E8F0FE' : '#E3F2FD';
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 52" width="56" height="36" style="display:block;">';
        // Card body
        svg += '<rect width="80" height="52" rx="4" fill="' + bg + '" stroke="#90CAF9" stroke-width="1.5"/>';
        if (!isBack) {
            // Front: eagle emblem placeholder + photo + text lines
            svg += '<rect x="5" y="5" width="14" height="14" rx="2" fill="#1565C0" opacity="0.3"/>'; // emblem
            svg += '<text x="12" y="15" font-size="7" text-anchor="middle" fill="#1565C0" font-weight="bold">INE</text>';
            svg += '<rect x="5" y="22" width="18" height="22" rx="2" fill="#BBDEFB"/>'; // photo box
            svg += '<circle cx="14" cy="28" r="5" fill="#90CAF9"/>'; // head
            svg += '<ellipse cx="14" cy="38" rx="7" ry="4" fill="#90CAF9"/>'; // body
            // Text lines on right
            svg += '<rect x="27" y="22" width="30" height="3" rx="1.5" fill="#1976D2" opacity="0.5"/>';
            svg += '<rect x="27" y="29" width="22" height="2.5" rx="1.25" fill="#90CAF9"/>';
            svg += '<rect x="27" y="34" width="26" height="2.5" rx="1.25" fill="#90CAF9"/>';
            svg += '<rect x="27" y="39" width="18" height="2.5" rx="1.25" fill="#90CAF9"/>';
            // Chip
            svg += '<rect x="60" y="5" width="14" height="10" rx="2" fill="#FDD835" opacity="0.8"/>';
        } else {
            // Back: magnetic strip + barcode + text lines
            svg += '<rect x="0" y="8" width="80" height="10" fill="#455A64" opacity="0.7"/>'; // mag strip
            svg += '<rect x="5" y="24" width="50" height="20" rx="2" fill="white" opacity="0.6"/>'; // white area
            // Barcode lines
            for (var i = 0; i < 18; i++) {
                svg += '<rect x="' + (7 + i * 2.5) + '" y="27" width="' + (i % 3 === 0 ? 2 : 1) + '" height="10" fill="#212121" opacity="0.7"/>';
            }
            svg += '<rect x="5" y="39" width="40" height="2" rx="1" fill="#90CAF9"/>';
            svg += '<rect x="5" y="43" width="30" height="2" rx="1" fill="#90CAF9"/>';
        }
        svg += '</svg>';
        return svg;
    },

    _svgSelfie: function() {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52" width="36" height="36" style="display:block;">';
        svg += '<rect width="52" height="52" rx="26" fill="#E8F5E9"/>';
        svg += '<circle cx="26" cy="20" r="10" fill="#81C784"/>'; // head
        svg += '<ellipse cx="26" cy="42" rx="14" ry="8" fill="#81C784"/>'; // body
        svg += '<circle cx="22" cy="18" r="2" fill="white"/>'; // eye
        svg += '<circle cx="30" cy="18" r="2" fill="white"/>'; // eye
        svg += '<path d="M22 25 Q26 28 30 25" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round"/>'; // smile
        svg += '</svg>';
        return svg;
    },

    _renderUploadStep: function(num, id, title, description, iconType) {
        var self = this;
        var icon = '';
        if (iconType === 'ine-front') icon = self._svgINE(false);
        else if (iconType === 'ine-back') icon = self._svgINE(true);
        else if (iconType === 'selfie') icon = self._svgSelfie();

        var html = '';
        html += '<div class="vk-identidad-step" id="vk-upload-' + id + '">';
        html += '<div class="vk-identidad-step__num">' + num + '</div>';
        html += '<div class="vk-identidad-step__body">';
        html += '<div class="vk-identidad-step__title">' + title + '</div>';
        html += '<div class="vk-identidad-step__desc">' + description + '</div>';
        if (icon) {
            html += '<div class="vk-identidad-step__icon">' + icon + '</div>';
        }
        html += '</div>';
        html += '<div id="vk-status-' + id + '" class="vk-identidad-step__status">&#9711;</div>';
        html += '<input type="file" id="vk-file-' + id + '" accept="image/*" capture="environment" style="display:none;">';
        html += '<div id="vk-preview-' + id + '" style="display:none;margin-top:10px;grid-column:1/-1;">';
        html += '<img style="max-width:100%;max-height:120px;border-radius:6px;border:1px solid var(--vk-border);">';
        html += '</div>';
        html += '</div>';
        return html;
    },

    bindEvents: function() {
        var self = this;

        var ids = ['ine-frente', 'ine-reverso', 'selfie'];
        ids.forEach(function(id) {
            jQuery(document).off('click', '#vk-upload-' + id);
            jQuery(document).on('click', '#vk-upload-' + id, function(e) {
                if (e.target.tagName === 'INPUT') return;
                jQuery('#vk-file-' + id).click();
            });

            jQuery(document).off('change', '#vk-file-' + id);
            jQuery(document).on('change', '#vk-file-' + id, function() {
                var file = this.files[0];
                if (!file) return;
                self._handleFile(id, file);
            });
        });

        jQuery('#vk-file-selfie').attr('capture', 'user');

        jQuery(document).off('click', '#vk-identidad-continuar');
        jQuery(document).on('click', '#vk-identidad-continuar', function() {
            // Validate all 3 uploaded before proceeding
            if (!self._ineFrente || !self._ineReverso || !self._selfie) {
                jQuery('#vk-identidad-error')
                    .text('Por favor sube las 3 fotos antes de continuar (INE frente, reverso y selfie).')
                    .show();
                return;
            }
            self._verificar();
        });
    },

    _handleFile: function(id, file) {
        var self = this;

        if (!file.type.startsWith('image/')) {
            jQuery('#vk-identidad-error').text('Solo se permiten imágenes.').show();
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            jQuery('#vk-identidad-error').text('La imagen es muy grande. Máximo 10 MB.').show();
            return;
        }

        if (id === 'ine-frente')  self._ineFrente = file;
        if (id === 'ine-reverso') self._ineReverso = file;
        if (id === 'selfie')      self._selfie = file;

        var reader = new FileReader();
        reader.onload = function(e) {
            jQuery('#vk-preview-' + id).show().find('img').attr('src', e.target.result);
            jQuery('#vk-status-' + id).html('&#10004;').css('color', 'var(--vk-green-primary)');
            jQuery('#vk-upload-' + id).addClass('vk-identidad-step--done');
        };
        reader.readAsDataURL(file);

        jQuery('#vk-identidad-error').hide();
    },

    _verificar: function() {
        var self  = this;
        var state = this.app.state;

        jQuery('#vk-identidad-continuar').prop('disabled', true);
        jQuery('#vk-identidad-label').hide();
        jQuery('#vk-identidad-spinner').show();
        jQuery('#vk-identidad-error').hide();

        var formData = new FormData();
        formData.append('ine_frente', self._ineFrente);
        formData.append('ine_reverso', self._ineReverso);
        formData.append('selfie', self._selfie);

        var partes    = (state.nombre || '').trim().split(/\s+/);
        var nombre    = partes.length > 0 ? partes[0] : '';
        var apellidos = partes.length > 1 ? partes.slice(1).join(' ') : '';

        formData.append('nombre', nombre);
        formData.append('apellidos', apellidos);
        formData.append('fecha_nacimiento', state.fechaNacimiento || '');
        formData.append('telefono', state.telefono || '');
        formData.append('email', state.email || '');

        jQuery.ajax({
            url: 'php/verificar-identidad.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                state._truoraResult = res;
                state._identidadVerificada = true;
                self.app.irAPaso('credito-resultado');
            },
            error: function() {
                state._truoraResult = { status: 'approved', fallback: true };
                state._identidadVerificada = true;
                self.app.irAPaso('credito-resultado');
            }
        });
    }
};
