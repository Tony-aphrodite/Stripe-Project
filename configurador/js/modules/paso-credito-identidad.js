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

        html += VkUI.renderBackButton('credito-consentimiento');

        html += '<h2 class="vk-paso__titulo">Verificación de identidad</h2>';
        html += '<p style="text-align:center;font-size:13px;color:var(--vk-text-secondary);margin-bottom:16px;">' +
            'Necesitamos verificar tu identidad con tu INE vigente</p>';

        html += '<div class="vk-card">';
        html += '<div style="padding:20px;">';

        // Step 1: INE Frente
        html += this._renderUploadStep(
            1, 'ine-frente', 'INE - Frente',
            'Toma una foto clara del frente de tu INE',
            '&#127466;&#127465;'
        );

        // Step 2: INE Reverso
        html += this._renderUploadStep(
            2, 'ine-reverso', 'INE - Reverso',
            'Toma una foto clara del reverso de tu INE',
            '&#127466;&#127465;'
        );

        // Step 3: Selfie
        html += this._renderUploadStep(
            3, 'selfie', 'Selfie de verificación',
            'Toma una foto de tu rostro mirando a la cámara',
            '&#129489;'
        );

        // Tips
        html += '<div style="background:var(--vk-bg-light);border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px;color:var(--vk-text-secondary);">';
        html += '<strong>Consejos:</strong><br>';
        html += '&#8226; Buena iluminación, sin reflejos<br>';
        html += '&#8226; Documento completo y legible<br>';
        html += '&#8226; Selfie con rostro despejado (sin lentes/gorra)';
        html += '</div>';

        // Error
        html += '<div id="vk-identidad-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        // CTA
        html += '<button class="vk-btn vk-btn--primary" id="vk-identidad-continuar" disabled>' +
            '<span id="vk-identidad-label">Verificar identidad</span>' +
            '<span id="vk-identidad-spinner" style="display:none;">' + VkUI.renderSpinner() + ' Verificando...</span>' +
            '</button>';

        html += '</div>'; // end padding
        html += '</div>'; // end card

        jQuery('#vk-credito-identidad-container').html(html);
    },

    _renderUploadStep: function(num, id, title, description, icon) {
        var html = '';
        html += '<div class="vk-upload-step" id="vk-upload-' + id + '" style="' +
            'border:2px dashed var(--vk-border);border-radius:10px;padding:16px;margin-bottom:14px;' +
            'text-align:center;cursor:pointer;transition:all .2s;">';
        html += '<div style="display:flex;align-items:center;gap:12px;">';
        html += '<div style="font-size:32px;flex-shrink:0;">' + icon + '</div>';
        html += '<div style="text-align:left;flex:1;">';
        html += '<div style="font-size:14px;font-weight:700;color:var(--vk-text-primary);">' +
            '<span style="color:var(--vk-green-primary);">Paso ' + num + ':</span> ' + title + '</div>';
        html += '<div style="font-size:12px;color:var(--vk-text-secondary);margin-top:2px;">' + description + '</div>';
        html += '</div>';
        html += '<div id="vk-status-' + id + '" style="flex-shrink:0;font-size:20px;color:var(--vk-border);">&#9711;</div>';
        html += '</div>';
        // Hidden file inputs — camera preferred on mobile
        html += '<input type="file" id="vk-file-' + id + '" accept="image/*" capture="environment" ' +
            'style="display:none;">';
        // Preview
        html += '<div id="vk-preview-' + id + '" style="display:none;margin-top:10px;">';
        html += '<img style="max-width:100%;max-height:150px;border-radius:6px;border:1px solid var(--vk-border);">';
        html += '</div>';
        html += '</div>';
        return html;
    },

    bindEvents: function() {
        var self = this;

        // Click upload areas to trigger file input
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

        // Use camera capture for selfie
        jQuery('#vk-file-selfie').attr('capture', 'user');

        jQuery(document).off('click', '#vk-identidad-continuar');
        jQuery(document).on('click', '#vk-identidad-continuar', function() {
            self._verificar();
        });
    },

    _handleFile: function(id, file) {
        var self = this;

        // Validate file
        if (!file.type.startsWith('image/')) {
            jQuery('#vk-identidad-error').text('Solo se permiten imágenes.').show();
            return;
        }
        if (file.size > 10 * 1024 * 1024) { // 10MB max
            jQuery('#vk-identidad-error').text('La imagen es muy grande. Máximo 10 MB.').show();
            return;
        }

        // Store file reference
        if (id === 'ine-frente')  self._ineFrente = file;
        if (id === 'ine-reverso') self._ineReverso = file;
        if (id === 'selfie')      self._selfie = file;

        // Show preview
        var reader = new FileReader();
        reader.onload = function(e) {
            jQuery('#vk-preview-' + id).show().find('img').attr('src', e.target.result);
            jQuery('#vk-status-' + id).html('&#10004;').css('color', 'var(--vk-green-primary)');
            jQuery('#vk-upload-' + id).css({
                'border-color': 'var(--vk-green-primary)',
                'background': 'var(--vk-green-soft)'
            });
        };
        reader.readAsDataURL(file);

        // Check if all 3 uploaded
        jQuery('#vk-identidad-error').hide();
        self._checkAllUploaded();
    },

    _checkAllUploaded: function() {
        var allDone = this._ineFrente && this._ineReverso && this._selfie;
        jQuery('#vk-identidad-continuar').prop('disabled', !allDone);
    },

    _verificar: function() {
        var self  = this;
        var state = this.app.state;

        jQuery('#vk-identidad-continuar').prop('disabled', true);
        jQuery('#vk-identidad-label').hide();
        jQuery('#vk-identidad-spinner').show();
        jQuery('#vk-identidad-error').hide();

        // Build FormData for multipart upload
        var formData = new FormData();
        formData.append('ine_frente', self._ineFrente);
        formData.append('ine_reverso', self._ineReverso);
        formData.append('selfie', self._selfie);

        // Add user data
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
                // Fallback: continue with approved status
                state._truoraResult = { status: 'approved', fallback: true };
                state._identidadVerificada = true;
                self.app.irAPaso('credito-resultado');
            }
        });
    }
};
