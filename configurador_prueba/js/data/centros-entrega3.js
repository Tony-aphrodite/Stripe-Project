/* ==========================================================================
   Voltika - Centros de Entrega (Delivery Points)
   Now loads from database via API instead of hardcoded data.
   Keeps same VOLTIKA_CENTROS.buscar(cp, estado) interface for paso3-delivery.js
   ========================================================================== */

var VOLTIKA_CENTROS = [];

// Load from API on page load
(function(){
    var basePath = window.VK_BASE_PATH || '';
    $.ajax({
        url: basePath + 'php/get-puntos.php',
        dataType: 'json',
        timeout: 10000,
        async: true,
        success: function(r){
            if(r.ok && r.puntos && r.puntos.length){
                // Replace array contents keeping same reference
                for(var i = 0; i < r.puntos.length; i++){
                    VOLTIKA_CENTROS.push(r.puntos[i]);
                }
            }
        },
        error: function(){
            // Silently fail — paso3 will show "Centro Voltika cercano" fallback
            console.warn('Voltika: Could not load delivery points from API');
        }
    });
})();

/* Estado name normalization map for matching */
var _ESTADO_ALIAS = {
    'Ciudad de México': 'CDMX',
    'Ciudad de Mexico': 'CDMX',
    'Distrito Federal': 'CDMX',
    'Estado de México': 'México',
    'Estado de Mexico': 'México',
    'Mexico': 'México'
};

function _normEstado(e) {
    if (!e) return '';
    return _ESTADO_ALIAS[e] || e;
}

/* Utility: find matching centers by estado (state) */
VOLTIKA_CENTROS.buscar = function(cp, estado) {
    if (!estado && (!cp || cp.length < 2)) return [];

    // If estado provided, match by estado
    if (estado) {
        var normEst = _normEstado(estado);
        var results = [];
        for (var i = 0; i < this.length; i++) {
            var centro = this[i];
            var centroEst = _normEstado(centro.estado);
            if (centroEst === normEst) {
                results.push(centro);
            }
        }
        // Sort by tipo priority: center > certificado > entrega
        var tipoPriority = { 'center': 0, 'certificado': 1, 'entrega': 2 };
        results.sort(function(a, b) {
            var pa = tipoPriority[a.tipo] !== undefined ? tipoPriority[a.tipo] : 3;
            var pb = tipoPriority[b.tipo] !== undefined ? tipoPriority[b.tipo] : 3;
            return pa - pb;
        });
        return results;
    }

    // Fallback: CP prefix match
    var prefix2 = cp.substring(0, 2);
    var results2 = [];
    for (var k = 0; k < this.length; k++) {
        var c = this[k];
        if (c.zonas && c.zonas.length) {
            for (var j = 0; j < c.zonas.length; j++) {
                if (c.zonas[j] === prefix2) { results2.push(c); break; }
            }
        } else {
            if (c.cp && c.cp.substring(0, 2) === prefix2) results2.push(c);
        }
    }
    return results2;
};
