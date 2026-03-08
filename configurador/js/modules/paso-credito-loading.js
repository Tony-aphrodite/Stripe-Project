/* ==========================================================================
   Voltika - Crédito: Loading / Analyzing Screen
   Shows animated motorcycle for 4-5 seconds while "confirming" approval,
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
        var html = '';

        html += '<div class="vk-loading-screen">';

        // Voltika logo
        html += '<div class="vk-loading-screen__logo">';
        html += '<img src="img/voltika-logo-white.png" alt="Voltika" style="height:36px;" onerror="this.style.display=\'none\'">';
        html += '</div>';

        // Animated motorcycle
        html += '<div class="vk-loading-screen__moto">';
        html += '<div class="vk-loading-moto">';
        html += '<svg viewBox="0 0 200 120" class="vk-loading-moto__svg">';
        // Simplified motorcycle silhouette
        html += '<g fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2">';
        // Body
        html += '<path d="M60,60 Q80,30 120,35 Q140,37 150,50 L145,60" />';
        html += '<path d="M60,60 L80,70 L130,65 L145,60" />';
        // Front wheel
        html += '<circle cx="150" cy="80" r="22" class="vk-loading-moto__wheel" />';
        html += '<circle cx="150" cy="80" r="3" fill="rgba(255,255,255,0.6)" />';
        // Rear wheel
        html += '<circle cx="65" cy="80" r="22" class="vk-loading-moto__wheel" />';
        html += '<circle cx="65" cy="80" r="3" fill="rgba(255,255,255,0.6)" />';
        // Fork
        html += '<path d="M145,60 L150,58" />';
        // Handlebar
        html += '<path d="M118,35 L125,28" />';
        // Seat
        html += '<path d="M85,45 Q95,38 110,40" stroke-width="4" stroke-linecap="round" />';
        html += '</g>';
        // Wheel spokes animation
        html += '<g class="vk-loading-moto__spokes">';
        html += '<line x1="150" y1="62" x2="150" y2="98" stroke="rgba(255,255,255,0.3)" stroke-width="1" />';
        html += '<line x1="132" y1="80" x2="168" y2="80" stroke="rgba(255,255,255,0.3)" stroke-width="1" />';
        html += '<line x1="65" y1="62" x2="65" y2="98" stroke="rgba(255,255,255,0.3)" stroke-width="1" />';
        html += '<line x1="47" y1="80" x2="83" y2="80" stroke="rgba(255,255,255,0.3)" stroke-width="1" />';
        html += '</g>';
        // Speed lines
        html += '<g stroke="rgba(255,255,255,0.4)" stroke-width="1">';
        html += '<line x1="5" y1="55" x2="35" y2="55" class="vk-loading-moto__speed1" />';
        html += '<line x1="10" y1="70" x2="40" y2="70" class="vk-loading-moto__speed2" />';
        html += '<line x1="15" y1="85" x2="45" y2="85" class="vk-loading-moto__speed3" />';
        html += '</g>';
        html += '</svg>';
        html += '</div>';
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
