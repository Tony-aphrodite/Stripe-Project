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
        url: basePath + 'php/get-puntos.php?t=' + Date.now(),
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
    'ciudad de méxico': 'CDMX',
    'ciudad de mexico': 'CDMX',
    'distrito federal': 'CDMX',
    'cdmx': 'CDMX',
    'd.f.': 'CDMX',
    'df': 'CDMX',
    'estado de méxico': 'México',
    'estado de mexico': 'México',
    'mexico': 'México'
};

function _normEstado(e) {
    if (!e) return '';
    var key = e.trim().toLowerCase();
    return _ESTADO_ALIAS[key] || e.trim();
}

/* Utility: find matching centers by estado (state) */
VOLTIKA_CENTROS.buscar = function(cp, estado) {
    if (!estado && (!cp || cp.length < 2)) return [];

    // If estado provided, match by estado or ciudad
    if (estado) {
        var normEst = _normEstado(estado);
        var results = [];
        for (var i = 0; i < this.length; i++) {
            var centro = this[i];
            var centroEst = _normEstado(centro.estado);
            var centroCiudad = _normEstado(centro.ciudad);
            if (centroEst === normEst || centroCiudad === normEst) {
                results.push(centro);
            }
        }
        // Deduplicate by id
        var seen = {};
        results = results.filter(function(c) {
            if (seen[c.id]) return false;
            seen[c.id] = true;
            return true;
        });
        // Sort by orden first, then tipo priority: center > certificado > entrega
        var tipoPriority = { 'center': 0, 'certificado': 1, 'entrega': 2 };
        results.sort(function(a, b) {
            var oa = (a.orden || 0), ob = (b.orden || 0);
            if (oa !== ob) return oa - ob;
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
