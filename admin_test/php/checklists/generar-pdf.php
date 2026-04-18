<?php
/**
 * GET ?tipo=origen|ensamble|entrega&moto_id=N
 * Generates a printable HTML page for the checklist (browser Print → PDF)
 * Also saves the URL to the checklist record
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$tipo   = $_GET['tipo'] ?? '';
$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId || !in_array($tipo, ['origen','ensamble','entrega'])) {
    http_response_code(400);
    exit('Parámetros inválidos');
}

$pdo = getDB();

// Moto info
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) { http_response_code(404); exit('Moto no encontrada'); }

// Checklist data
$tableMap = ['origen'=>'checklist_origen','ensamble'=>'checklist_ensamble','entrega'=>'checklist_entrega_v2'];
$table = $tableMap[$tipo];
$stmt2 = $pdo->prepare("SELECT * FROM $table WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt2->execute([$motoId]);
$cl = $stmt2->fetch(PDO::FETCH_ASSOC);
if (!$cl) { http_response_code(404); exit('Checklist no encontrado'); }

$folio = 'CL-' . strtoupper(substr($tipo,0,3)) . '-' . $motoId . '-' . date('Ymd', strtotime($cl['freg']));
$fechaGen = date('d/m/Y H:i');

// Section definitions
$sections = [];
if ($tipo === 'origen') {
    $sections = [
        ['1. Estructura principal', ['frame_completo','chasis_sin_deformaciones','soportes_estructurales','charola_trasera']],
        ['2. Sistema de rodamiento', ['llanta_delantera','llanta_trasera','rines_sin_dano','ejes_completos']],
        ['3. Dirección y control', ['manubrio','soportes_completos','dashboard_incluido','controles_completos']],
        ['4. Sistema de frenado', ['freno_delantero','freno_trasero','discos_sin_dano','calipers_instalados','lineas_completas']],
        ['5. Sistema eléctrico', ['cableado_completo','conectores_correctos','controlador_instalado','encendido_operativo']],
        ['6. Motor', ['motor_instalado','motor_sin_dano','motor_conexion']],
        ['7. Baterías', ['bateria_1','bateria_2','baterias_sin_dano']],
        ['8. Accesorios', ['espejos','tornilleria_completa','birlos_completos','kit_herramientas','cargador_incluido']],
        ['9. Complementos', ['llaves_2','manual_usuario','carnet_garantia']],
        ['10. Validación eléctrica (sin movimiento)', ['sistema_enciende','dashboard_funcional','indicador_bateria','luces_funcionando','conectores_firmes','cableado_sin_dano']],
        ['11. Artes decorativos y acabados', ['calcomanias_correctas','alineacion_correcta','sin_burbujas','sin_desprendimientos','sin_rayones','acabados_correctos']],
        ['12. Empaque', ['embalaje_correcto','protecciones_colocadas','caja_sin_dano','sellos_colocados','empaque_accesorios','empaque_llaves']],
        ['14. Declaración legal', ['declaracion_aceptada']],
        ['15. Validación final', ['validacion_final']],
    ];
} elseif ($tipo === 'ensamble') {
    $sections = [
        ['F1: Recepción', ['recepcion_validada','primera_apertura','area_segura','herramientas_disponibles','equipo_proteccion','declaracion_fase1']],
        ['F2: Desembalaje', ['componentes_sin_dano','accesorios_separados','llanta_identificada']],
        ['F2: Base y asiento', ['base_instalada','asiento_instalado','tornilleria_base','torque_base_25']],
        ['F2: Manubrio', ['manubrio_instalado','cableado_sin_tension','alineacion_manubrio','torque_manubrio_25']],
        ['F2: Llanta', ['buje_corto','buje_largo','disco_alineado','eje_instalado','torque_llanta_50']],
        ['F2: Espejos', ['espejo_izq','espejo_der','roscas_ok','ajuste_espejos']],
        ['F3: Frenos', ['freno_del_funcional','freno_tras_funcional','luz_freno_operativa']],
        ['F3: Iluminación', ['direccionales_ok','intermitentes_ok','luz_alta','luz_baja']],
        ['F3: Sistema eléctrico', ['claxon_ok','dashboard_ok','bateria_cargando','puerto_carga_ok']],
        ['F3: Motor', ['modo_eco','modo_drive','modo_sport','reversa_ok']],
        ['F3: Acceso', ['nfc_ok','control_remoto_ok','llaves_funcionales']],
        ['F3: Validación mecánica', ['sin_ruidos','sin_interferencias','torques_verificados','declaracion_fase3']],
    ];
} else {
    $sections = [
        ['F1: Identidad', ['ine_presentada','nombre_coincide','foto_coincide','datos_confirmados','ultimos4_telefono','modelo_confirmado','forma_pago_confirmada']],
        ['F2: Pago', ['pago_confirmado','enganche_validado','metodo_pago_registrado','domiciliacion_confirmada']],
        ['F3: Unidad', ['vin_coincide','unidad_ensamblada','estado_fisico_ok','sin_danos','unidad_completa']],
        ['F4: OTP', ['otp_enviado','otp_validado']],
        ['F5: Acta legal', ['acta_aceptada','clausula_identidad','clausula_medios','clausula_uso_info','firma_digital']],
    ];
}

$tipoLabels = ['origen'=>'Checklist de Origen','ensamble'=>'Checklist de Ensamble','entrega'=>'Checklist de Entrega'];
$titulo = $tipoLabels[$tipo];

// Origen-specific constants + photo categories
$ORIGEN_LEGAL_TEXT = 'La unidad fue revisada, validada y empacada conforme a estándares Voltika. Se confirma que: contenido completo, sistema eléctrico funcional, estética en buen estado.';
$ORIGEN_REGLA_ORO  = 'Lo que no está validado en origen, no existe.';
$ORIGEN_PHOTO_CATS = [
    'foto_unidad_completa'         => 'Unidad completa',
    'foto_vin'                     => 'VIN',
    'foto_tablero_encendido'       => 'Tablero encendido',
    'foto_bateria'                 => 'Batería',
    'foto_contenido_previo_cierre' => 'Contenido previo cierre',
    'foto_caja_cerrada'            => 'Caja cerrada',
    'foto_sellos'                  => 'Sellos',
    'foto_detalle_calcomanias'     => 'Detalle calcomanías',
    'foto_empaque_accesorios'      => 'Empaque de accesorios',
    'foto_empaque_llaves'          => 'Empaque de llaves',
];

// Generate HTML
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($titulo) ?> — <?= htmlspecialchars($moto['vin_display'] ?? $moto['vin']) ?></title>
<style>
  @media print { .no-print { display:none !important; } body { margin:0; } }
  * { box-sizing:border-box; }
  body { font-family:'Segoe UI',Arial,sans-serif; font-size:12px; color:#333; padding:20px; max-width:800px; margin:0 auto; }
  .header { text-align:center; border-bottom:2px solid #039fe1; padding-bottom:12px; margin-bottom:16px; }
  .header h1 { color:#039fe1; font-size:20px; margin:0; }
  .header .folio { font-size:11px; color:#666; }
  .meta { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-bottom:16px; font-size:12px; }
  .meta div { background:#f7f9fc; padding:6px 10px; border-radius:4px; }
  .meta strong { color:#039fe1; }
  .section { margin-bottom:12px; }
  .section h3 { font-size:13px; color:#039fe1; border-bottom:1px solid #e0e0e0; padding-bottom:3px; margin:0 0 6px; }
  .items { display:grid; grid-template-columns:1fr 1fr; gap:2px 16px; }
  .item { display:flex; align-items:center; gap:6px; padding:2px 0; font-size:11px; }
  .check { display:inline-block; width:14px; height:14px; border:1.5px solid #999; border-radius:3px; text-align:center; line-height:13px; font-size:10px; flex-shrink:0; }
  .check.ok { background:#4CAF50; border-color:#4CAF50; color:#fff; }
  .footer { margin-top:20px; border-top:1px solid #ddd; padding-top:12px; font-size:11px; color:#888; text-align:center; }
  .hash { font-family:monospace; font-size:10px; word-break:break-all; background:#f5f5f5; padding:6px; border-radius:4px; margin-top:8px; }
  .photos { display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 10px; }
  .photos img { width:80px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #ddd; }
  .photo-cat { margin-bottom:8px; }
  .photo-cat .cat-label { font-size:11px; font-weight:600; color:#546E7A; margin-bottom:2px; }
  .nota { background:#FFF3E0; border-left:3px solid #FB8C00; padding:6px 10px; margin:4px 0 8px; font-size:11px; color:#795548; }
  .prologo { background:#F5F7FA; border:1px solid #e3e7ed; border-radius:4px; padding:8px 10px; margin:4px 0 8px; font-size:11px; color:#37474F; line-height:1.5; }
  .regla-oro { background:linear-gradient(135deg,#FFC107,#FF9800); color:#3E2723; border-radius:6px; padding:10px; margin:14px 0; text-align:center; font-weight:700; font-size:12px; }
  .btn-print { position:fixed; top:10px; right:10px; background:#039fe1; color:#fff; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-size:14px; }
</style>
</head>
<body>
<button class="btn-print no-print" onclick="window.print()">Imprimir / Guardar PDF</button>

<div class="header">
  <h1>VOLTIKA — <?= htmlspecialchars($titulo) ?></h1>
  <div class="folio">Folio: <?= $folio ?> &nbsp;|&nbsp; Generado: <?= $fechaGen ?></div>
</div>

<div class="meta">
  <div><strong>VIN:</strong> <?= htmlspecialchars($moto['vin_display'] ?? $moto['vin']) ?></div>
  <div><strong>Modelo:</strong> <?= htmlspecialchars($moto['modelo']) ?> — <?= htmlspecialchars($moto['color']) ?></div>
  <div><strong>Estado:</strong> <?= $cl['completado'] ? 'COMPLETADO' : 'EN PROGRESO' ?></div>
  <div><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($cl['freg'])) ?></div>
  <?php if (!empty($moto['cliente_nombre'])): ?>
  <div><strong>Cliente:</strong> <?= htmlspecialchars($moto['cliente_nombre']) ?></div>
  <?php endif; ?>
  <?php if (!empty($moto['pedido_num'])): ?>
  <div><strong>Pedido:</strong> <?= htmlspecialchars($moto['pedido_num']) ?></div>
  <?php endif; ?>
  <?php if ($tipo === 'origen'): ?>
  <div><strong>Núm. motor:</strong> <?= htmlspecialchars($cl['num_motor'] ?? '—') ?></div>
  <div><strong>Año modelo:</strong> <?= htmlspecialchars($moto['anio_modelo'] ?? $cl['anio_modelo'] ?? '—') ?></div>
  <div><strong>Config. baterías:</strong> <?= htmlspecialchars($cl['config_baterias'] ?? '1') ?></div>
  <div><strong>Núm. sellos:</strong> <?= htmlspecialchars((string)($cl['num_sellos'] ?? 0)) ?></div>
  <?php endif; ?>
</div>

<?php foreach ($sections as [$secTitle, $fields]): ?>
<div class="section">
  <h3><?= htmlspecialchars($secTitle) ?></h3>
  <?php if ($tipo === 'origen' && strpos($secTitle, '10.') === 0): ?>
    <div class="nota"><strong>NOTA:</strong> Validación sin rodaje</div>
  <?php endif; ?>
  <?php if ($tipo === 'origen' && strpos($secTitle, '14.') === 0): ?>
    <div class="prologo"><?= htmlspecialchars($ORIGEN_LEGAL_TEXT) ?></div>
  <?php endif; ?>
  <div class="items">
    <?php foreach ($fields as $f): ?>
    <div class="item">
      <span class="check <?= !empty($cl[$f]) ? 'ok' : '' ?>"><?= !empty($cl[$f]) ? '✓' : '' ?></span>
      <?= htmlspecialchars(str_replace('_', ' ', ucfirst($f))) ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($tipo === 'origen'): ?>
<div class="section">
  <h3>13. Evidencia fotográfica</h3>
  <?php
    $anyPhoto = false;
    foreach ($ORIGEN_PHOTO_CATS as $col => $label):
      $catFotos = json_decode($cl[$col] ?? '[]', true);
      if (!is_array($catFotos) || !count($catFotos)) continue;
      $anyPhoto = true;
  ?>
  <div class="photo-cat">
    <div class="cat-label">📸 <?= htmlspecialchars($label) ?> (<?= count($catFotos) ?>)</div>
    <div class="photos">
      <?php foreach ($catFotos as $url): ?>
      <img src="../../<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($label) ?>">
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php
    // Legacy single-bucket fotos (pre Phase 2)
    $legacy = json_decode($cl['fotos'] ?? '[]', true);
    if (is_array($legacy) && count($legacy)):
      $anyPhoto = true;
  ?>
  <div class="photo-cat">
    <div class="cat-label" style="color:#999">Legacy (sin categoría)</div>
    <div class="photos">
      <?php foreach ($legacy as $url): ?>
      <img src="../../<?= htmlspecialchars($url) ?>" alt="legacy">
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!$anyPhoto): ?>
  <div style="font-size:11px;color:#999;">Sin fotos registradas.</div>
  <?php endif; ?>
</div>

<div class="regla-oro">⚡ REGLA DE ORO — <?= htmlspecialchars($ORIGEN_REGLA_ORO) ?></div>
<?php endif; ?>

<?php if (!empty($cl['notas'])): ?>
<div class="section">
  <h3>Notas</h3>
  <p style="font-size:12px;"><?= nl2br(htmlspecialchars($cl['notas'])) ?></p>
</div>
<?php endif; ?>

<?php
// Show photos (ensamble + entrega only — origen uses its own per-category render above)
$fotoCols = [];
$fotoLabels = [
    'fotos'           => 'Fotos de evidencia',
    'fotos_fase1'     => 'Fotos de recepción',
    'fotos_base'      => 'Fotos base y asiento',
    'fotos_manubrio'  => 'Fotos manubrio',
    'fotos_llanta'    => 'Fotos llanta delantera',
    'fotos_espejos'   => 'Fotos espejos',
    'fotos_fase3'     => 'Fotos validación final',
    'fotos_identidad' => 'Fotos de identidad',
    'fotos_unidad'    => 'Fotos de la unidad',
];
if ($tipo !== 'origen') {
    foreach ($cl as $k => $v) {
        if (strpos($k, 'fotos') === 0 && $v) {
            $fotos = json_decode($v, true);
            if (is_array($fotos) && count($fotos)) $fotoCols[$k] = $fotos;
        }
    }
}
if ($fotoCols): ?>
<div class="section">
  <h3>Evidencia fotográfica</h3>
  <?php foreach ($fotoCols as $col => $urls): ?>
  <div style="font-size:11px;color:#666;margin-top:4px;"><?= htmlspecialchars($fotoLabels[$col] ?? $col) ?>:</div>
  <div class="photos">
    <?php foreach ($urls as $url): ?>
    <img src="../../<?= htmlspecialchars($url) ?>" alt="foto">
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($cl['firma_data'])): ?>
<div class="section">
  <h3>Firma digital</h3>
  <img src="<?= htmlspecialchars($cl['firma_data']) ?>" style="max-width:300px;height:auto;border:1px solid #ddd;border-radius:4px;">
</div>
<?php endif; ?>

<?php if (!empty($cl['hash_registro'])): ?>
<div class="hash">Hash de integridad: <?= htmlspecialchars($cl['hash_registro']) ?></div>
<?php endif; ?>

<div class="footer">
  Documento generado automáticamente por el sistema Voltika Admin.
  <?php if ($cl['completado']): ?>Este checklist está bloqueado y no puede ser modificado.<?php endif; ?>
</div>

</body>
</html>
