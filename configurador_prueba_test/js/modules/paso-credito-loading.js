/* ==========================================================================
   Voltika - Crédito: Loading / Analyzing Screen
   Shows animated motorcycle image for 4-5 seconds while "confirming" approval,
   then auto-advances to credito-aprobado.
   ========================================================================== */

var PasoCreditoLoading = {

    _timer: null,

    init: function(app) {
        this.app = app;
        this.render();
        this._startTimer();
    },

    render: function() {
        var base = window.VK_BASE_PATH || '';
        var html = '';

        html += '<div class="vk-loading-screen">';

        // Loading GIF — large, top position, blended into background
        html += '<div style="text-align:center;margin-bottom:20px;">';
        html += '<img src="' + base + 'img/loading.gif" alt="Cargando..." style="width:380px;max-width:95%;height:auto;border-radius:16px;mix-blend-mode:screen;">';
        html += '</div>';

        // Voltika logo (white)
        html += '<div style="text-align:center;margin-bottom:16px;">';
        html += '<img src="' + base + 'img/voltika_logo.svg" alt="Voltika" ' +
            'style="width:160px;max-width:60%;filter:brightness(0) invert(1);">';
        html += '</div>';

        // Text
        html += '<div class="vk-loading-screen__text">';
        html += '<h2>Un momento...</h2>';
        html += '<p>Estamos confirmando<br>tu aprobaci\u00f3n Voltika</p>';
        html += '</div>';

        html += '<div class="vk-loading-screen__hint">Esto toma solo unos segundos</div>';

        html += '</div>';

        jQuery('#vk-credito-loading-container').html(html);
    },

    _startTimer: function() {
        var self = this;
        if (this._timer) clearTimeout(this._timer);

        // Auto-advance after 4.5 seconds — but only if the evaluation
        // actually produced an approval. Without this gate the loading
        // screen blindly routes NO_VIABLE cases into credito-aprobado
        // which renders "¡Felicidades!" regardless of the real decision.
        this._timer = setTimeout(function() {
            var resultado = (self.app.state && self.app.state._resultadoFinal) || {};
            var s = resultado.status || '';
            if (s === 'PREAPROBADO' || s === 'PREAPROBADO_ESTIMADO' ||
                s === 'CONDICIONAL' || s === 'CONDICIONAL_ESTIMADO') {
                self.app.irAPaso('credito-aprobado');
            } else {
                // NO_VIABLE, ERROR, empty — render the real decision UI
                self.app.irAPaso('credito-resultado');
            }
        }, 4500);
    }
};
