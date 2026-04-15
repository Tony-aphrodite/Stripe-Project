<?php
/**
 * POST — Regenerate a referral code for a Punto Voltika
 * Params: id (punto_id), campo (codigo_venta | codigo_electronico)
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$pdo = getDB();

$id    = (int)($d['id'] ?? 0);
$campo = $d['campo'] ?? '';

if (!$id || !in_array($campo, ['codigo_venta', 'codigo_electronico'], true)) {
    adminJsonOut(['ok' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

// Generate unique code with prefix
$prefix = ($campo === 'codigo_venta') ? 'PV' : 'PE';

// Ensure uniqueness
$maxAttempts = 10;
$nuevo = '';
for ($i = 0; $i < $maxAttempts; $i++) {
    $candidate = $prefix . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $chk = $pdo->prepare("SELECT COUNT(*) FROM puntos_voltika WHERE $campo = ? AND id != ?");
    $chk->execute([$candidate, $id]);
    if ((int)$chk->fetchColumn() === 0) {
        $nuevo = $candidate;
        break;
    }
}

if (!$nuevo) {
    adminJsonOut(['ok' => false, 'error' => 'No se pudo generar código único']);
    exit;
}

$stmt = $pdo->prepare("UPDATE puntos_voltika SET $campo = ? WHERE id = ?");
$stmt->execute([$nuevo, $id]);

adminLog('punto_regen_codigo', ['punto_id' => $id, 'campo' => $campo, 'nuevo' => $nuevo]);
adminJsonOut(['ok' => true, 'nuevo_codigo' => $nuevo]);
