/* ==========================================================================
   Voltika - Crédito: Loading / Analyzing Screen
   Shows animated neon motorcycle for 4-5 seconds while "confirming" approval,
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

        // Animated neon motorcycle
        html += '<div class="vk-loading-screen__moto">';
        html += '<svg viewBox="0 0 320 190" class="vk-loading-moto__svg">';
        html += '<defs>';
        // Neon glow filter
        html += '<filter id="neonGlow" x="-50%" y="-50%" width="200%" height="200%">';
        html += '<feGaussianBlur in="SourceGraphic" stdDeviation="3" result="blur"/>';
        html += '<feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>';
        html += '</filter>';
        html += '<filter id="strongGlow" x="-50%" y="-50%" width="200%" height="200%">';
        html += '<feGaussianBlur in="SourceGraphic" stdDeviation="6" result="blur"/>';
        html += '<feMerge><feMergeNode in="blur"/><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>';
        html += '</filter>';
        // Wheel gradient
        html += '<linearGradient id="wheelGrad" x1="0%" y1="0%" x2="100%" y2="100%">';
        html += '<stop offset="0%" stop-color="#00d4ff"/>';
        html += '<stop offset="100%" stop-color="#0088ff"/>';
        html += '</linearGradient>';
        html += '</defs>';

        // Speed lines (behind moto)
        html += '<g class="vk-speed-lines">';
        html += '<line x1="20" y1="85" x2="65" y2="85" stroke="rgba(0,180,255,0.4)" stroke-width="1.5" stroke-linecap="round" class="vk-speed-line"/>';
        html += '<line x1="10" y1="100" x2="70" y2="100" stroke="rgba(0,180,255,0.3)" stroke-width="1" stroke-linecap="round" class="vk-speed-line"/>';
        html += '<line x1="25" y1="115" x2="60" y2="115" stroke="rgba(0,180,255,0.35)" stroke-width="1.5" stroke-linecap="round" class="vk-speed-line"/>';
        html += '<line x1="15" y1="130" x2="55" y2="130" stroke="rgba(0,180,255,0.25)" stroke-width="1" stroke-linecap="round" class="vk-speed-line"/>';
        html += '</g>';

        // Motorcycle body
        html += '<g class="vk-moto-body" fill="none">';
        html += '<path d="M105,100 L130,65 L175,55 L210,50 L240,55 L255,70" stroke="rgba(150,200,255,0.5)" stroke-width="2.5"/>';
        html += '<path d="M145,62 Q165,48 195,50 Q210,52 215,60 L200,68 Q175,72 155,68 Z" fill="rgba(100,170,255,0.15)" stroke="rgba(150,200,255,0.6)" stroke-width="1.5"/>';
        html += '<path d="M120,68 Q130,58 148,62" stroke="rgba(150,200,255,0.5)" stroke-width="4" stroke-linecap="round"/>';
        html += '<path d="M105,100 L115,78 Q118,72 122,68" stroke="rgba(150,200,255,0.4)" stroke-width="2"/>';
        html += '<path d="M155,90 L175,80 L195,85 L190,100 L165,105 Z" fill="rgba(80,140,220,0.1)" stroke="rgba(120,170,255,0.35)" stroke-width="1.5"/>';
        html += '<path d="M130,110 L105,118 Q95,120 90,118" stroke="rgba(150,200,255,0.3)" stroke-width="2.5" stroke-linecap="round"/>';
        html += '<path d="M240,55 L252,65 L258,110 L260,140" stroke="rgba(150,200,255,0.45)" stroke-width="2"/>';
        html += '<path d="M165,105 L110,140" stroke="rgba(150,200,255,0.4)" stroke-width="2.5"/>';
        html += '<path d="M235,48 L248,40" stroke="rgba(150,200,255,0.5)" stroke-width="2.5" stroke-linecap="round"/>';
        html += '<path d="M240,52 L252,58" stroke="rgba(150,200,255,0.4)" stroke-width="2" stroke-linecap="round"/>';
        html += '<path d="M155,108 L115,138" stroke="rgba(100,160,255,0.2)" stroke-width="1" stroke-dasharray="3 2"/>';
        html += '</g>';

        // Headlight glow
        html += '<g class="vk-headlight" filter="url(#strongGlow)">';
        html += '<circle cx="260" cy="68" r="4" fill="#00d4ff" opacity="0.8"/>';
        html += '<path d="M262,62 L280,55 L282,68 L280,80 L262,74 Z" fill="rgba(0,200,255,0.15)"/>';
        html += '</g>';

        // Rear wheel - neon glow
        html += '<g class="vk-neon-wheel vk-neon-wheel--rear">';
        html += '<circle cx="110" cy="140" r="34" fill="none" stroke="rgba(0,150,255,0.15)" stroke-width="8" filter="url(#neonGlow)"/>';
        html += '<circle cx="110" cy="140" r="34" fill="none" stroke="rgba(0,180,255,0.6)" stroke-width="3" class="vk-wheel-spin"/>';
        html += '<circle cx="110" cy="140" r="24" fill="none" stroke="rgba(0,200,255,0.3)" stroke-width="1"/>';
        html += '<line x1="110" y1="118" x2="110" y2="162" stroke="rgba(0,180,255,0.2)" stroke-width="1"/>';
        html += '<line x1="88" y1="140" x2="132" y2="140" stroke="rgba(0,180,255,0.2)" stroke-width="1"/>';
        html += '<line x1="94" y1="124" x2="126" y2="156" stroke="rgba(0,180,255,0.15)" stroke-width="1"/>';
        html += '<line x1="126" y1="124" x2="94" y2="156" stroke="rgba(0,180,255,0.15)" stroke-width="1"/>';
        html += '<circle cx="110" cy="140" r="5" fill="rgba(0,180,255,0.3)" stroke="rgba(0,200,255,0.5)" stroke-width="1"/>';
        html += '</g>';

        // Front wheel - neon glow
        html += '<g class="vk-neon-wheel">';
        html += '<circle cx="258" cy="140" r="34" fill="none" stroke="rgba(0,150,255,0.15)" stroke-width="8" filter="url(#neonGlow)"/>';
        html += '<circle cx="258" cy="140" r="34" fill="none" stroke="rgba(0,180,255,0.6)" stroke-width="3" class="vk-wheel-spin"/>';
        html += '<circle cx="258" cy="140" r="24" fill="none" stroke="rgba(0,200,255,0.3)" stroke-width="1"/>';
        html += '<line x1="258" y1="118" x2="258" y2="162" stroke="rgba(0,180,255,0.2)" stroke-width="1"/>';
        html += '<line x1="236" y1="140" x2="280" y2="140" stroke="rgba(0,180,255,0.2)" stroke-width="1"/>';
        html += '<line x1="242" y1="124" x2="274" y2="156" stroke="rgba(0,180,255,0.15)" stroke-width="1"/>';
        html += '<line x1="274" y1="124" x2="242" y2="156" stroke="rgba(0,180,255,0.15)" stroke-width="1"/>';
        html += '<circle cx="258" cy="140" r="5" fill="rgba(0,180,255,0.3)" stroke="rgba(0,200,255,0.5)" stroke-width="1"/>';
        html += '</g>';

        // Ground reflection
        html += '<line x1="60" y1="178" x2="300" y2="178" stroke="rgba(0,150,255,0.15)" stroke-width="1"/>';
        html += '<ellipse cx="184" cy="180" rx="100" ry="3" fill="rgba(0,150,255,0.05)"/>';

        html += '</svg>';
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
