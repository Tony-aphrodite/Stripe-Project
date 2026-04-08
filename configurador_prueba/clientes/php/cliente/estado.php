<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$info = portalComputeAccountState($cid);
$sub  = $info['subscripcion'];
$next = $info['proximoCiclo'];

$pdo = getDB();
$stmt = $pdo->prepare("SELECT nombre, apellido_paterno, email, telefono FROM clientes WHERE id = ?");
$stmt->execute([$cid]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
