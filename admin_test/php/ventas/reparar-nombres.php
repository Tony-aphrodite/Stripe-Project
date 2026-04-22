<?php
/**
 * POST — Back-fill full customer names (nombre + apellidoPaterno + apellidoMaterno)
 * into transacciones.nombre + inventario_motos.cliente_nombre when only the
 * first name was originally captured (pre-2026-04-22 credit flow bug).
 *
 * Sources of the full name, in priority order:
 *   1. preaprobaciones (nombre + apellido_paterno + apellido_materno)
 *   2. verificaciones_identidad (nombre + apellidos)
 *
 * Match key: email OR telefono (whichever is present on the transacción).
 *
 * Runs in dry-run mode by default; add &confirm=1 to actually write.
 *
 * Access: admin role only.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$confirm = !empty($_GET['confirm']);
$pdo = getDB();

// Find transacciones rows whose `nombre` looks like a single token (no space)
// — a decent heuristic for first-name-only entries.
$rows = $pdo->query("
    SELECT id, pedido, nombre, email, telefono, stripe_pi
    FROM transacciones
    WHERE nombre IS NOT NULL
      AND nombre <> ''
      AND nombre NOT LIKE '% %'
    ORDER BY id DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$updates = [];
foreach ($rows as $r) {
    $fullName = null;
    $source   = null;

    // 1) preaprobaciones
    if (!$fullName) {
        $q = $pdo->prepare("
            SELECT nombre, apellido_paterno, apellido_materno
            FROM preaprobaciones
            WHERE (email = :em AND :em <> '')
               OR (telefono = :tel AND :tel <> '')
            ORDER BY id DESC LIMIT 1
        ");
        $q->execute([':em' => $r['email'] ?? '', ':tel' => $r['telefono'] ?? '']);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $candidate = trim(($p['nombre'] ?? '') . ' '
                . ($p['apellido_paterno'] ?? '') . ' '
                . ($p['apellido_materno'] ?? ''));
            if (strpos($candidate, ' ') !== false) {
                $fullName = $candidate;
                $source   = 'preaprobaciones';
            }
        }
    }

    // 2) verificaciones_identidad
    if (!$fullName) {
        try {
            $q = $pdo->prepare("
                SELECT nombre, apellidos
                FROM verificaciones_identidad
                WHERE (email = :em AND :em <> '')
                   OR (telefono = :tel AND :tel <> '')
                ORDER BY id DESC LIMIT 1
            ");
            $q->execute([':em' => $r['email'] ?? '', ':tel' => $r['telefono'] ?? '']);
            $v = $q->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $candidate = trim(($v['nombre'] ?? '') . ' ' . ($v['apellidos'] ?? ''));
                if (strpos($candidate, ' ') !== false) {
                    $fullName = $candidate;
                    $source   = 'verificaciones_identidad';
                }
            }
        } catch (PDOException $e) { /* table may not exist */ }
    }

    if ($fullName) {
        $updates[] = [
            'id'        => $r['id'],
            'pedido'    => $r['pedido'],
            'before'    => $r['nombre'],
            'after'     => $fullName,
            'source'    => $source,
            'email'     => $r['email'],
            'telefono'  => $r['telefono'],
            'stripe_pi' => $r['stripe_pi'],
        ];
    }
}

if ($confirm && count($updates)) {
    $pdo->beginTransaction();
    try {
        $uTrans = $pdo->prepare("UPDATE transacciones SET nombre = ? WHERE id = ?");
        $uInv   = $pdo->prepare("UPDATE inventario_motos SET cliente_nombre = ? WHERE pedido_num = CONCAT('VK-', ?) AND (cliente_nombre NOT LIKE '% %' OR cliente_nombre IS NULL)");
        foreach ($updates as $u) {
            $uTrans->execute([$u['after'], $u['id']]);
            $uInv->execute([$u['after'], $u['pedido']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        adminJsonOut(['error' => $e->getMessage()], 500);
    }
}

adminJsonOut([
    'ok'            => true,
    'mode'          => $confirm ? 'applied' : 'dry_run',
    'hint'          => $confirm ? null : 'Agrega &confirm=1 para ejecutar el UPDATE',
    'scanned_rows'  => count($rows),
    'updates_found' => count($updates),
    'updates'       => $updates,
]);
