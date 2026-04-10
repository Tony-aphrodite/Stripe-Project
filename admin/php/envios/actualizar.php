<?php
/**
 * POST — Update shipment (tracking number, carrier, notas, fecha_estimada)
 * Body: { envio_id, tracking_number?, carrier?, notas?, fecha_estimada? }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
if (!$envioId) adminJsonOut(['error' => 'envio_id requerido'], 400);

$pdo = getDB();

// Verify envio exists
$stmt = $pdo->prepare("SELECT * FROM envios WHERE id=?");
$stmt->execute([$envioId]);
$envio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$envio) adminJsonOut(['error' => 'Envío no encontrado'], 404);

$allowed = ['tracking_number','carrier','notas','fecha_estimada_llegada'];
$map = [
    'tracking_number'       => 'tracking_number',
    'carrier'               => 'carrier',
    'notas'                 => 'notas',
    'fecha_estimada'        => 'fecha_estimada_llegada',
];

$sets = []; $vals = [];
foreach ($map as $input => $col) {
    if (array_key_exists($input, $d)) {
        $sets[] = "$col = ?";
        $vals[] = $d[$input];
    }
}

if (empty($sets)) adminJsonOut(['error' => 'Sin cambios'], 400);

$vals[] = $envioId;
$pdo->prepare("UPDATE envios SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);

adminLog('envio_actualizar', ['envio_id' => $envioId, 'campos' => array_keys($sets)]);
adminJsonOut(['ok' => true]);
