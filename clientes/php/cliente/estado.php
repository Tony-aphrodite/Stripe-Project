<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

try {
    $info = portalComputeAccountState($cid);
} catch (Throwable $e) {
    error_log('cliente/estado computeAccountState: ' . $e->getMessage());
    $info = ['state'=>'no_subscription','subscripcion'=>null,'proximoCiclo'=>null,'progreso'=>0,'total_ciclos'=>0,'ciclos_pagados'=>0];
}
$sub  = $info['subscripcion'];
$next = $info['proximoCiclo'];

$pdo = getDB();
$cliente = [];
try {
    $stmt = $pdo->prepare("SELECT nombre, apellido_paterno, email, telefono FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('cliente/estado clientes: ' . $e->getMessage()); }

$nombre = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido_paterno'] ?? ''));
if ($nombre === '' && $sub) $nombre = 'Cliente Voltika';

portalJsonOut([
    'cliente' => [
        'id' => $cid,
        'nombre' => $nombre,
        'nombrePila' => $cliente['nombre'] ?? 'Cliente',
        'email' => $cliente['email'] ?? null,
        'telefono' => $cliente['telefono'] ?? null,
    ],
    'state' => $info['state'],
    'subscripcion' => $sub ? [
        'id' => (int)$sub['id'],
        'modelo' => $sub['modelo'] ?? null,
        'color' => $sub['color'] ?? null,
        'serie' => $sub['serie'] ?? null,
        'monto_semanal' => (float)($sub['monto_semanal'] ?? 0),
        'plazo_meses' => (int)($sub['plazo_meses'] ?? 0),
        'fecha_entrega' => $sub['fecha_entrega'] ?? null,
    ] : null,
    'proximo_pago' => $next ? [
        'semana_num' => (int)$next['semana_num'],
        'fecha_vencimiento' => $next['fecha_vencimiento'],
        'monto' => (float)$next['monto'],
        'estado' => $next['estado'],
    ] : null,
    'progreso' => [
        'porcentaje' => $info['progreso'],
        'pagados' => $info['ciclos_pagados'],
        'total' => $info['total_ciclos'],
        'restantes' => max(0, $info['total_ciclos'] - $info['ciclos_pagados']),
    ],
]);
