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

    $fechaIng = trim($m['fecha_ingreso_pais'] ?? '');
    if (!$fechaIng) {
        adminJsonOut(['error' => 'Fecha de ingreso al país es obligatoria (VIN: ' . $vin . ')'], 400);
    }

    // Check duplicate
    $chk = $pdo->prepare("SELECT id FROM inventario_motos WHERE vin=? LIMIT 1");
    $chk->execute([$vin]);
    if ($chk->fetch()) continue;

    // Admin can register either physical stock (recibida) or upcoming shipment (por_llegar).
    // Default 'recibida' so manually added motos appear as available inventory immediately.
    $estadoInicial = in_array($m['estado'] ?? '', ['por_llegar','recibida'], true)
        ? $m['estado']
        : 'recibida';

    $log = json_encode([['estado' => $estadoInicial, 'fecha' => date('Y-m-d H:i:s'), 'usuario' => $uid, 'origen' => 'inventario_crear_manual']]);

    $stmt->execute([
        $vin,
        $m['vin_display'] ?? strtoupper($vin),
        $m['modelo'] ?? '',
        $m['color'] ?? '',
        $estadoInicial,
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
