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
        ['Estructura principal', ['frame_completo','chasis_sin_deformaciones','soportes_estructurales','charola_trasera']],
        ['Sistema de rodamiento', ['llanta_delantera','llanta_trasera','rines_sin_dano','ejes_completos']],
        ['Dirección y control', ['manubrio','soportes_completos','dashboard_incluido','controles_completos']],
        ['Sistema de frenado', ['freno_delantero','freno_trasero','discos_sin_dano','calipers_instalados','lineas_completas']],
        ['Sistema eléctrico', ['cableado_completo','conectores_correctos','controlador_instalado','encendido_operativo']],
        ['Motor', ['motor_instalado','motor_sin_dano','motor_conexion']],
        ['Baterías', ['bateria_1','bateria_2','baterias_sin_dano','cargador_incluido']],
        ['Accesorios', ['espejos','tornilleria_completa','birlos_completos','kit_herramientas']],
        ['Complementos', ['llaves_2','manual_usuario','carnet_garantia']],
        ['Validación eléctrica', ['sistema_enciende','dashboard_funcional','indicador_bateria','luces_funcionando','conectores_firmes','cableado_sin_dano']],
        ['Artes decorativos', ['calcomanias_correctas','alineacion_correcta','sin_burbujas','sin_desprendimientos','sin_rayones','acabados_correctos']],
        ['Empaque', ['embalaje_correcto','protecciones_colocadas','caja_sin_dano','sellos_colocados']],
        ['Declaración final', ['declaracion_aceptada','validacion_final']],
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
  .photos { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0; }
  .photos img { width:80px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #ddd; }
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
</div>

<?php foreach ($sections as [$secTitle, $fields]): ?>
<div class="section">
  <h3><?= htmlspecialchars($secTitle) ?></h3>
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

<?php if (!empty($cl['notas'])): ?>
<div class="section">
  <h3>Notas</h3>
  <p style="font-size:12px;"><?= nl2br(htmlspecialchars($cl['notas'])) ?></p>
</div>
<?php endif; ?>

<?php
// Show photos
$fotoCols = [];
foreach ($cl as $k => $v) {
    if (strpos($k, 'fotos') === 0 && $v) {
        $fotos = json_decode($v, true);
        if (is_array($fotos) && count($fotos)) $fotoCols[$k] = $fotos;
    }
}
if ($fotoCols): ?>
<div class="section">
  <h3>Evidencia fotográfica</h3>
  <?php foreach ($fotoCols as $col => $urls): ?>
  <div style="font-size:11px;color:#666;margin-top:4px;"><?= htmlspecialchars($col) ?>:</div>
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
