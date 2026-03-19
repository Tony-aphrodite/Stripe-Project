/* ==========================================================================
   Voltika - Centros de Entrega (Delivery Points)
   Real delivery locations where customers pick up their Voltika.
   Add new centers here — the app will match them by CP prefix.
   ========================================================================== */

var VOLTIKA_CENTROS = [
    // tipo 'center' = Voltika Center (exhibici\u00f3n, pruebas de manejo, entrega, servicio, refacciones)
    {
        id: 'voltika-center-santa-fe',
        nombre: 'Voltika Center Santa Fe',
        direccion: 'Ernesto J. Piper 9',
        ubicacion: 'Santa Fe \u2013 CDMX',
        colonia: 'Paseos de las Lomas, \u00c1lvaro Obreg\u00f3n',
        cp: '01330',
        ciudad: 'Ciudad de M\u00e9xico',
        estado: 'CDMX',
        horarios: 'Atenci\u00f3n por WhatsApp o cita previa',
        autorizado: true,
        tipo: 'center',
        tags: ['Exhibici\u00f3n', 'Pruebas de manejo', 'Entrega', 'Servicio t\u00e9cnico', 'Refacciones'],
        servicios: [
            'Exhibici\u00f3n de motos Voltika',
            'Pruebas de manejo disponibles',
            'Entrega y activaci\u00f3n de tu moto',
            'Servicio t\u00e9cnico especializado',
            'Refacciones originales Voltika'
        ],
        descripcion: 'Entrega en CDMX y todo M\u00e9xico'
    },
    // tipo 'certificado' = Punto Voltika certificado (exhibici\u00f3n, entrega, servicio)
    {
        id: 'godike-motors',
        nombre: 'Godike Motors',
        direccion: 'Av. Ermita Iztapalapa 2453',
        ubicacion: 'Iztapalapa, Ciudad de M\u00e9xico',
        cp: '09820',
        ciudad: 'Ciudad de Mexico',
        estado: 'CDMX',
        horarios: 'Lunes a Viernes 10:00am a 18:30hrs, S\u00e1bado 11:00am a 14:00hrs, Domingo cerrado',
        autorizado: true,
        tipo: 'certificado',
        tags: ['Exhibici\u00f3n', 'Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    },
    {
        id: 'garage-mushu',
        nombre: 'Garage Mushu',
        direccion: 'Av. Central 502',
        ubicacion: 'Las Am\u00e9ricas, Ecatepec',
        cp: '55076',
        ciudad: 'Ecatepec de Morelos',
        estado: 'M\u00e9xico',
        horarios: 'Lunes a Viernes 10:00am a 18:00hrs',
        autorizado: true,
        tipo: 'certificado',
        tags: ['Exhibici\u00f3n', 'Entrega', 'Servicio t\u00e9cnico'],
        descripcion: 'Entrega y soporte t\u00e9cnico autorizados por Voltika.'
    }
];

/* Metro area groupings: CPs that share the same delivery zone */
var _VOLTIKA_ZONAS = [
    // CDMX (CP 01xxx-16xxx)
    ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16'],
    // Zona Metropolitana EdoMex (CP 52xxx-57xxx)
    ['52','53','54','55','56','57']
];

/* Utility: find matching centers for a given CP */
VOLTIKA_CENTROS.buscar = function(cp) {
    if (!cp || cp.length < 2) return [];

    var prefix2 = cp.substring(0, 2);

    // Find which zone this CP belongs to
    var zona = null;
    for (var z = 0; z < _VOLTIKA_ZONAS.length; z++) {
        for (var p = 0; p < _VOLTIKA_ZONAS[z].length; p++) {
            if (_VOLTIKA_ZONAS[z][p] === prefix2) {
                zona = _VOLTIKA_ZONAS[z];
                break;
            }
        }
        if (zona) break;
    }

    var results = [];
    for (var i = 0; i < this.length; i++) {
        var centro = this[i];
        var centroPrefix = centro.cp.substring(0, 2);

        if (zona) {
            // Same metro zone: match any CP prefix in the zone
            for (var j = 0; j < zona.length; j++) {
                if (zona[j] === centroPrefix) {
                    results.push(centro);
                    break;
                }
            }
        } else if (centroPrefix === prefix2) {
            // Fallback: exact 2-digit prefix match
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
