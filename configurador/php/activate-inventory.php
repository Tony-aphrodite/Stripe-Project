<?php
/**
 * Voltika — bulk activate imported inventory.
 *
 * Customer brief 2026-04-30 (launch day): after the bulk Excel import
 * via /admin → CEDIS → Importar Excel, all 122 motos sit at
 * estado='recibida' but with no checklist_origen row → the public
 * configurator filters them out as "Próxima entrega" because
 * inventory-utils.php requires `EXISTS checklist_origen WHERE
 * completado = 1`.
 *
 * This script lets ops bulk-create + mark complete the checklist_origen
 * row for every moto that doesn't have one yet, so the entire imported
 * batch becomes immediately sellable. The CEDIS operator can still
 * physically re-inspect each unit later — this script just unblocks the
 * launch.
 *
 * Usage:
 *   1. ?action=diag&token=voltika_activate_2026
 *      → diagnostic: count inventory by availability filter, show why
 *        each filter excludes motos.
 *   2. ?action=plan&token=voltika_activate_2026
 *      → list motos that would be activated.
 *   3. ?action=execute&token=voltika_activate_2026&confirm=YES_ACTIVATE
 *      → create + complete checklist_origen for every imported moto
 *        that's missing one.
 *
 * After successful execute, delete this file via FileZilla.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '0');
header('Content-Type: text/plain; charset=utf-8');

// ── Token gate ─────────────────────────────────────────────────────────────
$expectedToken = getenv('ACTIVATE_TOKEN') ?: 'voltika_activate_2026';
if (!hash_equals($expectedToken, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

$action = (string)($_GET['action'] ?? 'diag');

try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "DB connect failed: " . $e->getMessage() . "\n";
    exit;
}

echo "================================================================\n";
echo "  Voltika inventory activator\n";
echo "================================================================\n";
echo "Action : $action\n";
echo "Time   : " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------------------\n\n";

// ─────────────────────────────────────────────────────────────────────────
// ACTION: diag — show why each filter excludes motos
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'diag') {
    // Total inventory
    $total = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos")->fetchColumn();
    echo "Total inventario_motos rows : $total\n\n";

    echo "Filter breakdown (each filter applied alone):\n";
    $filters = [
        "activo = 1"                          => "m.activo = 1 OR m.activo IS NULL",
        "estado NOT IN (entregada, retenida)" => "m.estado NOT IN ('entregada','retenida')",
        "pedido_num is NULL/empty"            => "(m.pedido_num IS NULL OR m.pedido_num = '')",
        "cliente_email is NULL/empty"         => "(m.cliente_email IS NULL OR m.cliente_email = '')",
        "VIN not placeholder"                 => "m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'",
        "bloqueado_venta = 0/NULL"            => "(m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)",
        "punto_voltika_id NULL/0 (CEDIS)"     => "(m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)",
    ];
    foreach ($filters as $name => $where) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos m WHERE $where")->fetchColumn();
        printf("  %-40s : %3d / %d rows pass\n", $name, $n, $total);
    }

    echo "\nChecklist_origen analysis:\n";
    $hasChk = (int)$pdo->query("SELECT COUNT(*) FROM checklist_origen")->fetchColumn();
    echo "  checklist_origen rows total       : $hasChk\n";
    $hasChkComplete = (int)$pdo->query("SELECT COUNT(*) FROM checklist_origen WHERE completado = 1")->fetchColumn();
    echo "  checklist_origen rows complete    : $hasChkComplete\n";

    $motosWithChk = (int)$pdo->query("
        SELECT COUNT(DISTINCT m.id) FROM inventario_motos m
        JOIN checklist_origen co ON co.moto_id = m.id AND co.completado = 1
    ")->fetchColumn();
    echo "  motos with completed checklist    : $motosWithChk\n";

    $motosWithoutChk = (int)$pdo->query("
        SELECT COUNT(*) FROM inventario_motos m
        WHERE NOT EXISTS (
            SELECT 1 FROM checklist_origen co
            WHERE co.moto_id = m.id AND co.completado = 1
        )
    ")->fetchColumn();
    echo "  motos WITHOUT completed checklist : $motosWithoutChk  ← these are blocked from sale\n";

    echo "\nFinal availability (all filters combined, like the configurator sees):\n";
    $finalAvail = (int)$pdo->query("
        SELECT COUNT(*) FROM inventario_motos m
        WHERE m.estado NOT IN ('entregada','retenida')
          AND (m.pedido_num IS NULL OR m.pedido_num = '')
          AND (m.cliente_email IS NULL OR m.cliente_email = '')
          AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
          AND (m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)
          AND (m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)
          AND EXISTS (SELECT 1 FROM checklist_origen co WHERE co.moto_id = m.id AND co.completado = 1)
    ")->fetchColumn();
    echo "  Disponibles for sale : $finalAvail\n\n";

    // Show by model + color (what the configurator sees)
    echo "Disponibles by model + color (what configurator queries):\n";
    $rs = $pdo->query("
        SELECT m.modelo, LOWER(TRIM(m.color)) AS color, COUNT(*) AS n
        FROM inventario_motos m
        WHERE m.estado NOT IN ('entregada','retenida')
          AND (m.pedido_num IS NULL OR m.pedido_num = '')
          AND (m.cliente_email IS NULL OR m.cliente_email = '')
          AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
          AND (m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)
          AND (m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)
          AND EXISTS (SELECT 1 FROM checklist_origen co WHERE co.moto_id = m.id AND co.completado = 1)
        GROUP BY m.modelo, LOWER(TRIM(m.color))
        ORDER BY m.modelo, color
    ");
    while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
        printf("  %s / %s : %d\n", $r['modelo'], $r['color'], $r['n']);
    }

    echo "\nSee what would be activated:\n";
    echo "  ?action=plan&token=" . urlencode($expectedToken) . "\n";
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: plan — list candidates that would be activated
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'plan' || $action === 'execute') {

    // Find all motos that pass every filter EXCEPT checklist_origen.
    $candidates = $pdo->query("
        SELECT m.id, m.vin, m.modelo, m.color, m.estado
          FROM inventario_motos m
         WHERE m.estado NOT IN ('entregada','retenida')
           AND (m.pedido_num IS NULL OR m.pedido_num = '')
           AND (m.cliente_email IS NULL OR m.cliente_email = '')
           AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
           AND (m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)
           AND (m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)
           AND NOT EXISTS (
               SELECT 1 FROM checklist_origen co
                WHERE co.moto_id = m.id AND co.completado = 1
           )
         ORDER BY m.modelo, m.color, m.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "Candidates (motos that would become available): " . count($candidates) . "\n\n";

    // Group by model+color
    $by = [];
    foreach ($candidates as $r) {
        $key = $r['modelo'] . ' / ' . $r['color'];
        $by[$key] = ($by[$key] ?? 0) + 1;
    }
    foreach ($by as $k => $n) {
        printf("  %-30s : %d\n", $k, $n);
    }

    if ($action === 'plan') {
        echo "\nTo execute (create + complete checklist_origen for each candidate):\n";
        echo "  ?action=execute&token=" . urlencode($expectedToken) . "&confirm=YES_ACTIVATE\n";
        exit;
    }

    // EXECUTE
    if (($_GET['confirm'] ?? '') !== 'YES_ACTIVATE') {
        echo "\nMissing &confirm=YES_ACTIVATE — aborted.\n";
        exit;
    }

    echo "\nExecuting...\n";

    // Inspect checklist_origen schema to know what columns to set.
    $cols = [];
    $rs = $pdo->query("SHOW COLUMNS FROM checklist_origen");
    while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $r['Field'];
    }
    $hasCompletado = in_array('completado', $cols, true);
    $hasFreg       = in_array('freg', $cols, true);
    $hasFmod       = in_array('fmod', $cols, true);
    $hasOperador   = in_array('operador', $cols, true);

    if (!$hasCompletado) {
        echo "ERROR: checklist_origen table missing 'completado' column. Aborting.\n";
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $created = 0; $updated = 0; $errors = 0;

    foreach ($candidates as $r) {
        try {
            // Look for existing row first
            $stmtChk = $pdo->prepare("SELECT id, completado FROM checklist_origen WHERE moto_id = ? LIMIT 1");
            $stmtChk->execute([$r['id']]);
            $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ((int)$existing['completado'] !== 1) {
                    $sql = "UPDATE checklist_origen SET completado = 1";
                    if ($hasFmod) $sql .= ", fmod = ?";
                    $sql .= " WHERE id = ?";
                    $params = $hasFmod ? [$now, (int)$existing['id']] : [(int)$existing['id']];
                    $pdo->prepare($sql)->execute($params);
                    $updated++;
                }
            } else {
                // Build minimal insert
                $insertCols = ['moto_id', 'completado'];
                $insertVals = [$r['id'], 1];
                if ($hasFreg) { $insertCols[] = 'freg'; $insertVals[] = $now; }
                if ($hasFmod) { $insertCols[] = 'fmod'; $insertVals[] = $now; }
                if ($hasOperador) { $insertCols[] = 'operador'; $insertVals[] = 'launch_bulk_activate'; }

                $sql = "INSERT INTO checklist_origen (" . implode(',', $insertCols) . ")
                        VALUES (" . implode(',', array_fill(0, count($insertCols), '?')) . ")";
                $pdo->prepare($sql)->execute($insertVals);
                $created++;
            }
        } catch (Throwable $e) {
            $errors++;
            echo "  ERROR moto_id=" . $r['id'] . " vin=" . $r['vin'] . " : " . $e->getMessage() . "\n";
        }
    }

    echo "\nResults:\n";
    echo "  checklist_origen rows created : $created\n";
    echo "  rows updated to completed     : $updated\n";
    echo "  errors                        : $errors\n";

    // Re-count availability
    $finalAvail = (int)$pdo->query("
        SELECT COUNT(*) FROM inventario_motos m
        WHERE m.estado NOT IN ('entregada','retenida')
          AND (m.pedido_num IS NULL OR m.pedido_num = '')
          AND (m.cliente_email IS NULL OR m.cliente_email = '')
          AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
          AND (m.bloqueado_venta = 0 OR m.bloqueado_venta IS NULL)
          AND (m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)
          AND EXISTS (SELECT 1 FROM checklist_origen co WHERE co.moto_id = m.id AND co.completado = 1)
    ")->fetchColumn();

    echo "\nNew availability (configurator-visible): $finalAvail motos\n";
    echo "\nVerify in browser:\n";
    echo "  https://www.voltika.mx/configurador/php/check-inventory.php\n";
    echo "\nSECURITY: delete this file (activate-inventory.php) via FileZilla.\n";
    exit;
}

echo "Unknown action. Valid: diag, plan, execute\n";
