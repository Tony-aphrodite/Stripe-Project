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

        // Voltika shield + logo
        html += '<div class="vk-loading-screen__logo">';
        html += '<img src="' + base + 'img/voltika_shield.svg" alt="" style="height:28px;margin-right:6px;vertical-align:middle;" onerror="this.style.display=\'none\'">';
        html += '<span style="font-size:22px;font-weight:700;color:white;vertical-align:middle;letter-spacing:0.5px;">voltika</span>';
        html += '</div>';

        // Motorcycle image with rotating wheel overlays
        html += '<div class="vk-loading-screen__moto">';
        html += '<img src="' + base + 'img/loding.png" alt="" class="vk-loading-moto__img">';
        html += '<div class="vk-loading-wheel vk-loading-wheel--rear"></div>';
        html += '<div class="vk-loading-wheel vk-loading-wheel--front"></div>';
        html += '</div>';

        // Text
        html += '<div class="vk-loading-screen__text">';
        html += '<h2>Un momento...</h2>';
        html += '<p>Estamos confirmando<br>tu aprobaci\u00f3n Voltika</p>';
        html += '</div>';

        html += '<div class="vk-loading-screen__hint">Esto toma solo unos segundos</div>';

        // Progress dots
        html += '<div class="vk-loading-screen__dots">';
        html += '<span class="vk-loading-dot"></span>';
        html += '<span class="vk-loading-dot"></span>';
        html += '<span class="vk-loading-dot"></span>';
        html += '</div>';

        html += '</div>';

        jQuery('#vk-credito-loading-container').html(html);
    },

    _startTimer: function() {
        var self = this;
        if (this._timer) clearTimeout(this._timer);

        // Auto-advance after 4.5 seconds
        this._timer = setTimeout(function() {
            self.app.irAPaso('credito-aprobado');
        }, 4500);
    }
};
