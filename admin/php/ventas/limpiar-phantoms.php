<?php
/**
 * POST — Clean up phantom inventario_motos records + add fecha_estimada_entrega.
 *
 * confirmar-orden.php used to auto-INSERT a virtual bike record into
 * inventario_motos every time a purchase was confirmed. These records
 * have VINs like "VK-M05-{pedidoNum}" and do NOT represent real physical
 * motorcycles. They made every order appear "assigned" in the dashboard,
 * violating the business rule that CEDIS must manually assign real bikes.
 *
 * This script:
 *   1) Adds fecha_estimada_entrega column to transacciones (if missing)
 *   2) Identifies phantom records by VIN pattern: /^VK-[A-Z0-9]+-\d+-[a-f0-9]+$/
 *   3) For each phantom: if the order has a REAL bike also assigned,
 *      deactivate only the phantom. If the phantom is the ONLY moto record,
 *      deactivate it (the order goes back to "sin asignar").
 *   4) Reports what was cleaned.
 *
 * Body: { dry_run: bool }
 * Response: { ok, phantoms_found, deactivated, kept, errors[], actions[] }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

// ── Migration: add fecha_estimada_entrega to transacciones ──────────────
try {
    $existing = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fecha_estimada_entrega', $existing, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN fecha_estimada_entrega DATE NULL");
    }
} catch (Throwable $e) { /* noop — column might already exist */ }

// ── Identify phantom records ────────────────────────────────────────────
// Phantom VINs match: VK-{MODEL}-{timestamp}-{hex4} e.g. "VK-M05-1775953741-cb3a"
// Real VINs are either warehouse-entered (alphanumeric) or don't start with VK-
$rows = $pdo->query("
    SELECT id, vin, vin_display, modelo, color, pedido_num, cliente_email,
           stripe_pi, transaccion_id, activo
    FROM inventario_motos
    WHERE vin REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
      AND activo = 1
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$found       = count($rows);
$deactivated = 0;
$kept        = 0;
$errors      = [];
$actions     = [];

foreach ($rows as $r) {
    $motoId   = (int)$r['id'];
    $vin      = $r['vin'];
    $pedido   = $r['pedido_num'] ?? '';
    $txId     = $r['transaccion_id'] ? (int)$r['transaccion_id'] : 0;

    // Check if a REAL moto is also assigned to the same order
    $hasReal = false;
    if ($pedido) {
        $chk = $pdo->prepare("
            SELECT id FROM inventario_motos
            WHERE pedido_num = ?
              AND id <> ?
              AND activo = 1
              AND vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
            LIMIT 1
        ");
        $chk->execute([$pedido, $motoId]);
        $hasReal = (bool)$chk->fetchColumn();
    }

    $action = [
        'moto_id'   => $motoId,
        'vin'       => $vin,
        'pedido'    => $pedido,
        'has_real'  => $hasReal,
    ];

    if ($dryRun) {
        $action['would_deactivate'] = true;
        $actions[] = $action;
        $deactivated++;
        continue;
    }

    try {
        // Deactivate the phantom record. Clear customer data so it doesn't
        // interfere with motos-disponibles.php filters.
        $pdo->prepare("
            UPDATE inventario_motos
            SET activo = 0,
                notas  = CONCAT(IFNULL(notas,''), '\n[limpiar-phantoms] Desactivado — registro virtual creado por confirmar-orden.php')
            WHERE id = ?
        ")->execute([$motoId]);
        $deactivated++;
        $action['deactivated'] = true;
        $actions[] = $action;
    } catch (Throwable $e) {
        $errors[] = "moto_id={$motoId}: " . $e->getMessage();
    }
}

// ── Set fecha_estimada_entrega for orders without a real moto ───────────
// Per business rule: if inventory for the requested modelo/color is 0,
// delivery estimate is current date + 2 months.
try {
    $orders = $pdo->query("
        SELECT t.id, t.modelo, t.color, t.fecha_estimada_entrega
        FROM transacciones t
        LEFT JOIN inventario_motos m
               ON m.pedido_num = CONCAT('VK-', t.pedido)
              AND m.activo = 1
              AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
        WHERE m.id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    $twoMonths = date('Y-m-d', strtotime('+2 months'));
    $updStmt = $pdo->prepare("
        UPDATE transacciones SET fecha_estimada_entrega = ? WHERE id = ? AND fecha_estimada_entrega IS NULL
    ");

    foreach ($orders as $o) {
        if (!$dryRun && empty($o['fecha_estimada_entrega'])) {
            $updStmt->execute([$twoMonths, $o['id']]);
        }
    }
    $actions[] = ['set_fecha_estimada' => count($orders) . ' órdenes sin moto real → fecha_estimada_entrega = ' . $twoMonths];
} catch (Throwable $e) {
    $errors[] = 'fecha_estimada_entrega: ' . $e->getMessage();
}

adminLog('limpiar_phantoms', [
    'dry_run'      => $dryRun,
    'found'        => $found,
    'deactivated'  => $deactivated,
    'errors'       => count($errors),
]);

adminJsonOut([
    'ok'             => count($errors) === 0,
    'dry_run'        => $dryRun,
    'phantoms_found' => $found,
    'deactivated'    => $deactivated,
    'kept'           => $kept,
    'errors'         => $errors,
    'actions'        => $actions,
]);
