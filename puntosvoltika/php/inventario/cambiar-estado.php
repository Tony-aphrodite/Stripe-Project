<?php
/**
 * POST — Point transitions a motorcycle between lifecycle states.
 *
 * Input: { moto_id, nuevo_estado, fecha_entrega_estimada?, notas? }
 *
 * Allowed transitions (per dashboards_diagrams.pdf):
 *   recibida           → en_ensamble         (start assembly)
 *   en_ensamble        → lista_para_entrega  (assembly done, requires fecha_entrega_estimada)
 *   recibida           → lista_para_entrega  (skip assembly, still requires date)
 *
 * On lista_para_entrega: fires `lista_para_recoger` notification including the pickup date.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId        = (int)($d['moto_id'] ?? 0);
$nuevoEstado   = trim($d['nuevo_estado'] ?? '');
$fechaEntrega  = trim($d['fecha_entrega_estimada'] ?? '');
$notas         = trim($d['notas'] ?? '');

if (!$motoId || !$nuevoEstado) {
    puntoJsonOut(['error' => 'moto_id y nuevo_estado son obligatorios'], 400);
}

$allowed = ['en_ensamble', 'lista_para_entrega'];
if (!in_array($nuevoEstado, $allowed, true)) {
    puntoJsonOut(['error' => 'Estado no permitido: ' . $nuevoEstado], 400);
}

$pdo = getDB();

// Load the moto and verify it belongs to this point
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en tu inventario'], 404);

$estadoActual = $moto['estado'] ?? '';

// Transition rules
$validTransitions = [
    'recibida'    => ['en_ensamble', 'lista_para_entrega'],
    'en_ensamble' => ['lista_para_entrega'],
];
if (!isset($validTransitions[$estadoActual]) || !in_array($nuevoEstado, $validTransitions[$estadoActual], true)) {
    puntoJsonOut(['error' => 'Transición no permitida: ' . $estadoActual . ' → ' . $nuevoEstado], 400);
}

// lista_para_entrega requires a valid future pickup date
if ($nuevoEstado === 'lista_para_entrega') {
    if (!$fechaEntrega || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEntrega)) {
        puntoJsonOut(['error' => 'Fecha de entrega estimada requerida (YYYY-MM-DD)'], 400);
    }
    $ts = strtotime($fechaEntrega);
    if (!$ts || $ts < strtotime('today')) {
        puntoJsonOut(['error' => 'La fecha debe ser hoy o posterior'], 400);
    }
}

// Ensure fecha_entrega_estimada column exists on inventario_motos
try {
    $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fecha_entrega_estimada', $cols, true)) {
        $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN fecha_entrega_estimada DATE NULL");
    }
} catch (Throwable $e) { error_log('cambiar-estado ensure column: ' . $e->getMessage()); }

// Apply the transition
if ($nuevoEstado === 'lista_para_entrega') {
    $pdo->prepare("UPDATE inventario_motos
        SET estado=?, fecha_entrega_estimada=?, fmod=NOW()
        WHERE id=?")->execute([$nuevoEstado, $fechaEntrega, $motoId]);
} else {
    $pdo->prepare("UPDATE inventario_motos SET estado=?, fmod=NOW() WHERE id=?")
        ->execute([$nuevoEstado, $motoId]);
}

puntoLog('cambiar_estado', [
    'moto_id'                => $motoId,
    'estado_anterior'        => $estadoActual,
    'estado_nuevo'           => $nuevoEstado,
    'fecha_entrega_estimada' => $fechaEntrega ?: null,
    'notas'                  => $notas ?: null,
]);

// On lista_para_entrega → notify the client with the pickup date
if ($nuevoEstado === 'lista_para_entrega' && !empty($moto['cliente_telefono'])) {
    require_once __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php';
    try {
        $pq = $pdo->prepare("SELECT nombre, direccion, horarios FROM puntos_voltika WHERE id=?");
        $pq->execute([$ctx['punto_id']]);
        $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: [];

        // Format the pickup date for humans: "15 de abril de 2026"
        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $dt = new DateTime($fechaEntrega);
        $fechaHuman = $dt->format('j') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y');

        voltikaNotify('lista_para_recoger', [
            'cliente_id'    => $moto['cliente_id'] ?? null,
            'nombre'        => $moto['cliente_nombre'] ?? '',
            'modelo'        => $moto['modelo'] ?? '',
            'punto'         => $punto['nombre'] ?? '',
            'direccion'     => $punto['direccion'] ?? '',
            'horario'       => $punto['horarios'] ?? '',
            'fecha_entrega' => $fechaHuman,
            'telefono'      => $moto['cliente_telefono'],
            'email'         => $moto['cliente_email'] ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify lista_para_recoger: ' . $e->getMessage()); }
}

puntoJsonOut([
    'ok'                     => true,
    'moto_id'                => $motoId,
    'estado'                 => $nuevoEstado,
    'fecha_entrega_estimada' => $fechaEntrega ?: null,
]);
