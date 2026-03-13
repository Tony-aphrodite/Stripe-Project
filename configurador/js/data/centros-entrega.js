/* ==========================================================================
   Voltika - Centros de Entrega (Delivery Points)
   Real delivery locations where customers pick up their Voltika.
   Add new centers here — the app will match them by CP prefix.
   ========================================================================== */

var VOLTIKA_CENTROS = [
    {
        id: 'garage-mushu',
        nombre: 'Garage Mushu',
        direccion: 'Av Ignacio Allende 114-58A, Fraccionamiento Las Americas, Las Am\u00e9ricas',
        cp: '55076',
        ciudad: 'Ecatepec de Morelos',
        estado: 'M\u00e9xico',
        horarios: 'Lunes a Viernes 10:00am a 18:00hrs',
        autorizado: true
    },
    {
        id: 'godike-motors',
        nombre: 'Godike Motors',
        direccion: 'Capilla del Carmen #7, Col. El Santuario, Iztapalapa',
        cp: '09820',
        ciudad: 'Ciudad de Mexico',
        estado: 'CDMX',
        horarios: 'Lunes a Viernes 10:00am a 18:30hrs, S\u00e1bado 11:00am a 14:00hrs, Domingo cerrado',
        autorizado: true
    }
];

/* Utility: find matching centers for a given CP */
VOLTIKA_CENTROS.buscar = function(cp) {
    if (!cp || cp.length < 2) return [];

    var prefix2 = cp.substring(0, 2);
    var results = [];

    for (var i = 0; i < this.length; i++) {
        var centro = this[i];
        // Match by first 2 digits of CP (same state/metro area)
        if (centro.cp.substring(0, 2) === prefix2) {
            results.push(centro);
        }
    }

    // Sort: exact CP match first, then by CP proximity
    var cpNum = parseInt(cp);
    results.sort(function(a, b) {
        var distA = Math.abs(parseInt(a.cp) - cpNum);
        var distB = Math.abs(parseInt(b.cp) - cpNum);
        return distA - distB;
    });

    return results;
};
