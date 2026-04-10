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

// Auto-fill empty name from inventario_motos or transacciones
if (empty($cliente['nombre'])) {
    $tel = $cliente['telefono'] ?? null;
    $em  = $cliente['email'] ?? null;
    $foundName = null;
    if ($tel) {
        $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_telefono = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$tel]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName && $em) {
        $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_email = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$em]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName && ($tel || $em)) {
        $q = $tel ? "telefono = ?" : "email = ?";
        $nStmt = $pdo->prepare("SELECT nombre FROM transacciones WHERE $q AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$tel ?: $em]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if ($foundName) {
        $pdo->prepare("UPDATE clientes SET nombre = ? WHERE id = ? AND (nombre IS NULL OR nombre = '')")->execute([$foundName, $cid]);
        $cliente['nombre'] = $foundName;
    }
}

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
