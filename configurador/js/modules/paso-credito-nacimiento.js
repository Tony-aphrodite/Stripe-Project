/* ==========================================================================
   Voltika - Crédito Screen 7: CURP del cliente
   ──────────────────────────────────────────────────────────────────────────
   Customer brief 2026-04-30: matching by name was rejecting valid users
   for trivial differences (accent on Pérez vs Perez, double space, hyphen)
   and burning credit-bureau queries on each retry. The CURP is a unique
   18-character identifier with no whitespace/accent ambiguity, AND it
   embeds the date of birth (positions 5-10 = YYMMDD) — so a single CURP
   field replaces the previous birthday picker AND becomes the anchor we
   compare against the CURP Truora extracts from the INE photo.
   This screen now collects the CURP, derives fechaNacimiento from it for
   the CDC bureau call, and persists state.curp for the Truora token
   endpoint to use as expected_curp.
   Format: AAAA YYMMDD H/M EE EEE C  (regex below).
   ========================================================================== */

var PasoCreditoNacimiento = {

    init: function(app) {
        this.app = app;
        this.render();
        this.bindEvents();
    },

    // CURP regex — RENAPO standard. 4 letters + 6 digits + H/M + 5 letters
    // + 1 alphanumeric homoclave + 1 digit verifier.
    _curpRegex: /^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/,

    // ── CURP-to-name local validation (anti-fraud, customer brief 2026-04-30) ──
    // The first 4 letters of every CURP are deterministically derived from the
    // person's name per RENAPO rules:
    //   1. First letter of apellido paterno
    //   2. First INTERNAL vowel of apellido paterno (A/E/I/O/U after position 0)
    //   3. First letter of apellido materno (X if absent)
    //   4. First letter of nombre (skipping JOSE/MARIA/MA/J prefix)
    // If the typed name's derived initials don't match the typed CURP's
    // first 4 letters, one of the two is fake → reject before hitting CDC,
    // saving a bureau query and blocking impersonation attempts.
    _stripAccents: function(s) {
        if (!s) return '';
        return String(s)
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toUpperCase()
            .replace(/[^A-ZÑ ]/g, ' ')   // keep letters, Ñ, spaces
            .replace(/\s+/g, ' ')
            .trim();
    },
    _stripPreps: function(s) {
        // Drop leading prepositions (DE, DEL, LA, LAS, LOS, MC, VAN, VON, Y).
        // RENAPO ignores them when deriving the CURP initials.
        var preps = {DE:1,DEL:1,LA:1,LAS:1,LOS:1,MC:1,VAN:1,VON:1,Y:1};
        var parts = (s || '').split(/\s+/);
        while (parts.length > 1 && preps[parts[0]]) parts.shift();
        return parts.join(' ');
    },
    _curpInitialsFromName: function(nombre, paterno, materno) {
        var clean = this._stripAccents;
        var nom = clean(nombre);
        var pat = this._stripPreps(clean(paterno));
        var mat = this._stripPreps(clean(materno));

        // Composite first names: skip JOSE/MARIA/MA/J prefix when more
        // names follow — RENAPO uses the SECOND name in that case.
        var nomParts = nom.split(/\s+/);
        if (nomParts.length > 1 && {JOSE:1, MARIA:1, MA:1, J:1}[nomParts[0]]) {
            nomParts.shift();
        }
        nom = nomParts[0] || '';

        if (pat.length === 0 || nom.length === 0) return null;

        // Letter 1 — first char of paterno (Ñ → X per RENAPO)
        var l1 = pat.charAt(0) === 'Ñ' ? 'X' : pat.charAt(0);

        // Letter 2 — first internal vowel of paterno
        var l2 = 'X';
        for (var i = 1; i < pat.length; i++) {
            var c = pat.charAt(i);
            if (c === 'A' || c === 'E' || c === 'I' || c === 'O' || c === 'U') {
                l2 = c; break;
            }
        }

        // Letter 3 — first char of materno (X if absent)
        var l3 = 'X';
        if (mat) {
            var c3 = mat.charAt(0);
            l3 = (c3 === 'Ñ') ? 'X' : c3;
        }

        // Letter 4 — first char of (non-prefix) nombre
        var l4 = nom.charAt(0) === 'Ñ' ? 'X' : nom.charAt(0);

        return l1 + l2 + l3 + l4;
    },
    _curpInitialsMatch: function(typed, expected) {
        // Allow X anywhere on either side. RENAPO substitutes X when the
        // computed initials would form a banned word (BUEY/KACA/etc.) so a
        // valid CURP can legitimately contain X where the name predicts a
        // different letter.
        if (!typed || !expected || typed.length !== 4 || expected.length !== 4) return false;
        for (var i = 0; i < 4; i++) {
            var t = typed.charAt(i), e = expected.charAt(i);
            if (t !== e && t !== 'X' && e !== 'X') return false;
        }
        return true;
    },

    render: function() {
        var state = this.app.state;
        var html = '';

        html += VkUI.renderBackButton('credito-nombre');
        html += VkUI.renderCreditoStepBar(1);

        html += '<h2 class="vk-paso__titulo">¿Cuál es tu CURP?</h2>';
        html += '<p class="vk-paso__subtitulo">La usamos para validar tu identidad y aprobar tu crédito Voltika.</p>';
        html += '<p class="vk-trust-highlight"><span class="vk-check"></span> Tu aprobación tarda <strong>menos de 2 minutos</strong></p>';

        var defaultCurp = state.curp || '';

        html += '<div class="vk-card" style="padding:20px;">';

        html += '<div class="vk-form-group">';
        html += '<label class="vk-form-label">CURP (18 caracteres)</label>';
        html += '<div style="font-size:13px;color:var(--vk-text-secondary);margin-bottom:8px;">Debe coincidir exactamente con la CURP de tu INE.</div>';
        html += '<input type="text" class="vk-form-input" id="vk-cnac-curp" ' +
            'value="' + defaultCurp + '" ' +
            'maxlength="18" ' +
            'autocapitalize="characters" autocorrect="off" autocomplete="off" spellcheck="false" ' +
            'placeholder="Ej. PEMA850712HDFRRR05" ' +
            'style="color:#111;font-size:15px;padding:12px 14px;letter-spacing:1px;text-transform:uppercase;font-family:ui-monospace,Menlo,monospace;">';
        html += '</div>';

        // Live age preview — once the CURP parses, show the derived
        // birthday so the user sees what we'll send to the bureau.
        html += '<div id="vk-cnac-fecha-preview" style="display:none;font-size:12px;color:#16a34a;margin:-4px 0 12px;">';
        html += 'Fecha de nacimiento detectada: <strong id="vk-cnac-fecha-label"></strong>';
        html += '</div>';

        html += '<div id="vk-cnac-error" style="display:none;color:#C62828;font-size:13px;' +
            'background:#FFEBEE;border-radius:6px;padding:10px;margin-bottom:12px;"></div>';

        html += '<a href="https://www.gob.mx/curp/" target="_blank" rel="noopener" ' +
            'style="display:block;font-size:12px;color:#039fe1;text-align:center;margin-bottom:12px;">' +
            '¿No recuerdas tu CURP? Consultarla en gob.mx</a>';

        html += '<button class="vk-btn vk-btn--primary" id="vk-cnac-continuar">CONTINUAR</button>';

        html += '<div class="vk-trust">';
        html += '<div><span class="vk-check vk-check--sm"></span> Proceso seguro</div>';
        html += '<div><span class="vk-check vk-check--sm"></span> No afecta tu historial crediticio</div>';
        html += '</div>';

        html += '</div>';

        jQuery('#vk-credito-nacimiento-container').html(html);

        // Re-show the birthday preview if state already has a parsed CURP
        // (returning to this paso via back button etc.).
        if (defaultCurp && this._curpRegex.test(defaultCurp)) {
            this._showBirthdayPreview(defaultCurp);
        }
    },

    bindEvents: function() {
        var self = this;

        // Live formatting + birthday preview as the user types.
        jQuery(document).off('input', '#vk-cnac-curp');
        jQuery(document).on('input', '#vk-cnac-curp', function() {
            var raw = jQuery(this).val() || '';
            var clean = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').substr(0, 18);
            if (clean !== raw) jQuery(this).val(clean);
            jQuery('#vk-cnac-error').hide();
            if (clean.length === 18 && self._curpRegex.test(clean)) {
                self._showBirthdayPreview(clean);
            } else {
                jQuery('#vk-cnac-fecha-preview').hide();
            }
        });

        jQuery(document).off('click', '#vk-cnac-continuar');
        jQuery(document).on('click', '#vk-cnac-continuar', function() {
            var curp = (jQuery('#vk-cnac-curp').val() || '').toUpperCase().trim();

            if (!curp) {
                jQuery('#vk-cnac-error').text('Ingresa tu CURP.').show();
                return;
            }
            if (curp.length !== 18) {
                jQuery('#vk-cnac-error').text('La CURP debe tener exactamente 18 caracteres.').show();
                return;
            }
            if (!self._curpRegex.test(curp)) {
                jQuery('#vk-cnac-error').text('Formato de CURP inválido. Revisa el orden de letras y números.').show();
                return;
            }

            // ── Anti-fraud: CURP initials must match the typed name ──────
            // Customer brief 2026-04-30: blocks the case where a person
            // submits a real CURP under a fake/typo'd name (e.g. "MARTEINEZ
            // MENDE" — a real Carlos Martinez Mendez's CURP) so that CDC's
            // loose matching can't be used to impersonate a real bureau
            // history. The check is purely local — no bureau call yet.
            var st = self.app.state || {};
            var expected = self._curpInitialsFromName(st.nombre, st.apellidoPaterno, st.apellidoMaterno);
            var typedInitials = curp.substring(0, 4);
            if (expected && !self._curpInitialsMatch(typedInitials, expected)) {
                jQuery('#vk-cnac-error').html(
                    '<strong>Tu CURP no coincide con el nombre que ingresaste.</strong><br>' +
                    'Esperado (por nombre): <code>' + expected + '·····</code><br>' +
                    'Tu CURP comienza con: <code>' + typedInitials + '·····</code><br>' +
                    'Verifica nombre, apellidos y CURP. ' +
                    '<a href="#" id="vk-cnac-corregir-nombre" style="color:#039fe1;font-weight:700;">Regresar a corregir →</a>'
                ).show();
                jQuery(document).off('click', '#vk-cnac-corregir-nombre');
                jQuery(document).on('click', '#vk-cnac-corregir-nombre', function(e) {
                    e.preventDefault();
                    self.app.irAPaso('credito-nombre');
                });
                return;
            }

            var fecha = self._birthdayFromCurp(curp);
            if (!fecha) {
                jQuery('#vk-cnac-error').text('No pudimos extraer la fecha de nacimiento de tu CURP. Verifícala.').show();
                return;
            }

            // Age >= 18 check (same business rule as before, just derived
            // from the CURP instead of a separate date input).
            var nacimiento = new Date(fecha + 'T00:00:00');
            var hoy = new Date();
            var edad = hoy.getFullYear() - nacimiento.getFullYear();
            var mesDiff = hoy.getMonth() - nacimiento.getMonth();
            if (mesDiff < 0 || (mesDiff === 0 && hoy.getDate() < nacimiento.getDate())) {
                edad--;
            }
            if (edad < 18) {
                jQuery('#vk-cnac-error').text('Debes tener al menos 18 años para solicitar un crédito Voltika.').show();
                return;
            }

            jQuery('#vk-cnac-error').hide();

            // Persist BOTH so downstream code keeps working unchanged.
            // - state.curp        → truora-token.php expected_curp anchor
            // - state.fechaNacimiento → CDC bureau call
            self.app.state.curp = curp;
            self.app.state.fechaNacimiento = fecha;
            self.app.irAPaso('credito-cp-dom');
        });
    },

    // Position 5-10 of CURP (0-indexed 4-9) = YYMMDD. Positions 25-99
    // belong to the 21st century (year 2000-2099); positions 00-24 to
    // the 20th century (1925-1999). Standard RENAPO interpretation.
    _birthdayFromCurp: function(curp) {
        if (!curp || curp.length < 10) return null;
        var yy = parseInt(curp.substr(4, 2), 10);
        var mm = parseInt(curp.substr(6, 2), 10);
        var dd = parseInt(curp.substr(8, 2), 10);
        if (isNaN(yy) || isNaN(mm) || isNaN(dd)) return null;
        if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
        // The 11th char (homoclave letter) used to disambiguate century:
        // letters A-Z → 21st century (2000+); digits 0-9 → 20th century.
        // Pre-1996 CURPs used digits at this position; post-1996 use letters.
        var homo = curp.substr(16, 1);
        var century = /[A-Z]/.test(homo) ? 2000 : 1900;
        var fullYear = century + yy;
        // Build YYYY-MM-DD with zero-padding.
        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
        return fullYear + '-' + pad(mm) + '-' + pad(dd);
    },

    _showBirthdayPreview: function(curp) {
        var fecha = this._birthdayFromCurp(curp);
        if (!fecha) {
            jQuery('#vk-cnac-fecha-preview').hide();
            return;
        }
        // Display as DD/MM/YYYY for the user (Spanish locale convention).
        var parts = fecha.split('-');
        var pretty = parts[2] + '/' + parts[1] + '/' + parts[0];
        jQuery('#vk-cnac-fecha-label').text(pretty);
        jQuery('#vk-cnac-fecha-preview').show();
    }
};
