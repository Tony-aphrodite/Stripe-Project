<?php
/**
 * POST — Change shipping status (lista_para_enviar → enviada → recibida)
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
$nuevoEstado = $d['estado'] ?? '';
if (!$envioId || !in_array($nuevoEstado, ['enviada','recibida'])) {
    adminJsonOut(['error' => 'envio_id y estado válido requeridos'], 400);
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT e.*, m.cliente_id, m.cliente_nombre, m.cliente_telefono, m.cliente_email, m.modelo, pv.nombre AS punto_nombre
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id
    WHERE e.id=?");
$stmt->execute([$envioId]);
$envio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$envio) adminJsonOut(['error' => 'Envío no encontrado'], 404);

$updates = ['estado' => $nuevoEstado];
if ($nuevoEstado === 'enviada') {
    $updates['fecha_envio'] = date('Y-m-d');
    // Update moto status
    $pdo->prepare("UPDATE inventario_motos SET estado='por_llegar' WHERE id=?")->execute([$envio['moto_id']]);
    // Notify
    if ($envio['cliente_telefono'] || $envio['cliente_email']) {
        require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
        try {
            voltikaNotify('moto_enviada', [
                'cliente_id' => $envio['cliente_id'] ?? null,
                'nombre'     => $envio['cliente_nombre'] ?? '',
                'modelo'     => $envio['modelo'] ?? '',
                'punto'      => $envio['punto_nombre'] ?? '',
                'fecha'      => $envio['fecha_estimada_llegada'] ?: 'próximamente',
                'telefono'   => $envio['cliente_telefono'] ?? '',
                'email'      => $envio['cliente_email'] ?? '',
            ]);
        } catch (Throwable $e) { error_log('notify moto_enviada: ' . $e->getMessage()); }
    }
}

$sets = []; $params = [];
foreach ($updates as $k => $v) { $sets[] = "$k=?"; $params[] = $v; }
$params[] = $envioId;
$pdo->prepare("UPDATE envios SET " . implode(',', $sets) . " WHERE id=?")->execute($params);

adminLog('envio_estado', ['envio_id' => $envioId, 'estado' => $nuevoEstado]);
adminJsonOut(['ok' => true]);
