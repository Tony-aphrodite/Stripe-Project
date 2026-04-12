<?php
/**
 * POST — Punto recibe moto: scan VIN, checklist, fotos
 * Body: { envio_id, moto_id, vin_escaneado, estado_fisico_ok, sin_danos, componentes_completos, bateria_ok, fotos:[], notas }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
$motoId  = (int)($d['moto_id'] ?? 0);
$vinScan = trim($d['vin_escaneado'] ?? '');

if (!$envioId || !$motoId || !$vinScan) puntoJsonOut(['error' => 'Datos incompletos'], 400);

$pdo = getDB();
// Verify moto belongs to this envio
$stmt = $pdo->prepare("SELECT m.*, e.id as envio_id FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    WHERE e.id=? AND e.moto_id=? AND e.punto_destino_id=?");
$stmt->execute([$envioId, $motoId, $ctx['punto_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) puntoJsonOut(['error' => 'Envío no corresponde a este punto'], 404);

$vinCoincide = (strcasecmp(trim($row['vin']), $vinScan) === 0) ? 1 : 0;
if (!$vinCoincide) {
    puntoJsonOut(['error' => 'VIN escaneado no coincide con la moto esperada', 'vin_esperado' => $row['vin'], 'vin_escaneado' => $vinScan], 400);
}

// Validate all checks passed
$checks = ['estado_fisico_ok','sin_danos','componentes_completos','bateria_ok'];
$allOk = true;
foreach ($checks as $c) if (empty($d[$c])) $allOk = false;

// Insert recepcion
$ins = $pdo->prepare("INSERT INTO recepcion_punto
    (envio_id, moto_id, punto_id, recibido_por, vin_escaneado, vin_coincide,
     estado_fisico_ok, sin_danos, componentes_completos, bateria_ok, fotos, notas, completado)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
$ins->execute([
    $envioId, $motoId, $ctx['punto_id'], $ctx['user_id'],
    $vinScan, $vinCoincide,
    (int)($d['estado_fisico_ok'] ?? 0),
    (int)($d['sin_danos'] ?? 0),
    (int)($d['componentes_completos'] ?? 0),
    (int)($d['bateria_ok'] ?? 0),
    json_encode($d['fotos'] ?? []),
    $d['notas'] ?? '',
    $allOk ? 1 : 0
]);

// Update envio → recibida
$pdo->prepare("UPDATE envios SET estado='recibida', fecha_recepcion=NOW(), recibido_por=? WHERE id=?")
    ->execute([$ctx['user_id'], $envioId]);

// Update moto status
$newEstado = $allOk ? 'recibida' : 'retenida';
$pdo->prepare("UPDATE inventario_motos SET estado=? WHERE id=?")->execute([$newEstado, $motoId]);

puntoLog('recibir_moto', ['moto_id' => $motoId, 'envio_id' => $envioId, 'estado' => $newEstado]);

// NOTE: The `lista_para_recoger` notification is NOT sent here. Per the flow diagram,
// the client is only notified after the point marks the moto as `lista_para_entrega`
// with a pickup date (see inventario/cambiar-estado.php). Reception alone is an
// intermediate state — assembly + pickup date come next.

puntoJsonOut(['ok' => true, 'recepcion_id' => $pdo->lastInsertId(), 'estado' => $newEstado]);
