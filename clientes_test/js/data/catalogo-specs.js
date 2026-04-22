/* ==========================================================================
   Voltika clientes · Catálogo unificado de specs
   Fuente única de verdad:
     - velocidad / autonomía: configurador_prueba/js/data/productos.js
       (cargado antes que este archivo en index.php)
     - voltaje batería: mapa local aquí (productos.js no lo incluye)

   Bug histórico que esto previene:
   Antes el portal tenía un MODELO_SPECS hardcodeado con valores distintos
   al configurador (M05 mostraba 85 km/h en el portal y 75 km/h en la
   tienda). El cliente veía specs contradictorias según la pantalla.

   Exposición:
     VK_SPECS.forModel(modeloStr) → { vel, auton, bat, anio, modelo }
     VK_SPECS.lookupProducto(modeloStr) → objeto del catálogo (o null)
   ========================================================================== */
(function(global){

  // Voltajes de batería por modelo. productos.js no tiene este campo —
  // si alguna vez se agrega ahí, estos defaults se ignoran vía _VOLTAJE.
  var BATERIA_VOLTAJE = {
    'm03':        '48V',
    'm05':        '60V',
    'mc10':       '72V',
    'pesgo-plus': '60V',
    'mino':       '48V',
    'ukko-s':     '72V'
  };

  // Tolerant lookup key normalizer: matches values that live in
  //   transacciones.modelo / inventario_motos.modelo — which can be any of:
  //     "M05", "Pesgo Plus", "Voltika Tromox M05", "m05", "Mino-B"
  // It collapses to the productos.js `id` (lowercase, dash-separated).
  function _slug(raw){
    if (!raw) return '';
    var s = String(raw).toLowerCase().trim();
    // Strip legacy prefixes that Ship.js used to emit
    s = s.replace(/^(voltika\s+tromox\s+|voltika\s+|tromox\s+)/, '').trim();
    // Normalize whitespace + remove plus/extra
    s = s.replace(/\s+/g, '-');
    // Special cases: "mino-b" → "mino", "ukko-s+" → "ukko-s"
    s = s.replace(/^mino(-b)?$/, 'mino');
    s = s.replace(/^ukko(-s)?(\+)?$/, 'ukko-s');
    // "pesgo" alone → pesgo-plus (legacy)
    if (s === 'pesgo') s = 'pesgo-plus';
    return s;
  }

  function _findProducto(modeloRaw){
    if (!global.VOLTIKA_PRODUCTOS || !Array.isArray(global.VOLTIKA_PRODUCTOS.modelos)) return null;
    var slug = _slug(modeloRaw);
    if (!slug) return null;
    var list = global.VOLTIKA_PRODUCTOS.modelos;
    // 1) exact id match
    for (var i=0;i<list.length;i++) if (list[i].id === slug) return list[i];
    // 2) name match (case-insensitive)
    var lower = String(modeloRaw||'').toLowerCase().trim()
                  .replace(/^(voltika\s+tromox\s+|voltika\s+|tromox\s+)/, '').trim();
    for (var j=0;j<list.length;j++) {
      if ((list[j].nombre||'').toLowerCase() === lower) return list[j];
    }
    return null;
  }

  function forModel(modeloRaw){
    var p = _findProducto(modeloRaw);
    var slug = _slug(modeloRaw);
    var bat = BATERIA_VOLTAJE[slug] || '—';
    if (!p) {
      // Fallback — preserve display rather than throwing. Admin can see the
      // raw value in the pedido section even when the model is not in the
      // catalog (e.g. a discontinued SKU still showing in a legacy order).
      return {
        vel:   '—',
        auton: '—',
        bat:   bat,
        anio:  '2026',
        modelo: modeloRaw || '—',
      };
    }
    return {
      vel:   (p.velocidad != null ? p.velocidad + 'km/h' : '—'),
      auton: (p.autonomia != null ? p.autonomia + 'km'   : '—'),
      bat:   bat,
      anio:  '2026',
      modelo: p.nombre,
    };
  }

  global.VK_SPECS = {
    forModel:        forModel,
    lookupProducto:  _findProducto,
    slug:            _slug,
  };

})(window);
