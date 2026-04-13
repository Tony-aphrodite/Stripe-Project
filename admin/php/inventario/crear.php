<?php
/**
 * POST — Create or import new moto(s) into inventory
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']); // Only admin can add/remove inventory

$d = adminJsonIn();
$pdo = getDB();

// Support batch import (array) or single
$motos = isset($d['motos']) ? $d['motos'] : [$d];
$created = 0;

$stmt = $pdo->prepare("INSERT INTO inventario_motos
    (vin, vin_display, modelo, color, estado, anio_modelo, num_motor, potencia,
     config_baterias, descripcion, hecho_en, num_pedimento, fecha_ingreso_pais,
     aduana, cedis_origen, notas, log_estados)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

foreach ($motos as $m) {
    $vin = trim($m['vin'] ?? '');
    if (!$vin) continue;

    // Check duplicate
    $chk = $pdo->prepare("SELECT id FROM inventario_motos WHERE vin=? LIMIT 1");
    $chk->execute([$vin]);
    if ($chk->fetch()) continue;

    $log = json_encode([['estado' => 'por_llegar', 'fecha' => date('Y-m-d H:i:s'), 'usuario' => $uid]]);

    $stmt->execute([
        $vin,
        $m['vin_display'] ?? strtoupper($vin),
        $m['modelo'] ?? '',
        $m['color'] ?? '',
        'por_llegar',
        $m['anio_modelo'] ?? date('Y'),
        $m['num_motor'] ?? '',
        $m['potencia'] ?? '',
        $m['config_baterias'] ?? '1',
        $m['descripcion'] ?? '',
        $m['hecho_en'] ?? '',
        $m['num_pedimento'] ?? '',
        $m['fecha_ingreso_pais'] ?? null,
        $m['aduana'] ?? '',
        $m['cedis_origen'] ?? '',
        $m['notas'] ?? '',
        $log
    ]);
    $created++;
}

adminLog('inventario_crear', ['cantidad' => $created]);
adminJsonOut(['ok' => true, 'creados' => $created]);
