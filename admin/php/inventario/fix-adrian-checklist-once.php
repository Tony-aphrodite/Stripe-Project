<?php
/**
 * ONE-SHOT FIX — Adrian Montoya (moto_id=147) entrega checklist.
 *
 * Context (2026-05-26): Adrian's moto was marked estado='entregada' before
 * Round 84's mandatory-fields server gate existed. The punto operator never
 * actually completed F1/F2/F3 of checklist_entrega_v2, so the admin UI shows
 * a yellow "Inconsistencia en el estado de la moto" banner.
 *
 * Round 84 prevents this from happening to ANY future delivery — but Adrian
 * is a one-off legacy case that needs cleanup. Per customer (Óscar)
 * conversation 2026-05-26 we close the checklist retroactively with an
 * explicit forensic flag so the row is distinguishable from a real
 * operator-side completion.
 *
 * USE: visit /admin/php/inventario/fix-adrian-checklist-once.php once.
 * After it runs, re-visits show "ya completado, nada que hacer".
 * Safe to delete the file once the banner is gone.
 *
 * Hard-coded for safety:
 *   - Target: moto_id = 147 only
 *   - Reason: stored verbatim below
 *   - Caller must be authenticated admin
 *   - Idempotent — re-running is a no-op
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

const TARGET_MOTO_ID = 147;
const FORENSIC_REASON = 'Cierre forense por administrador — la entrega física ya ocurrió antes del gate de Round 84 (2026-05-26). '
                     . 'El operador del punto nunca cerró F1/F2/F3 desde la app. ACTA firmada + OTP verificados + entrega completada en sitio. '
                     . 'Esta fila se marca con forzado_admin=1 para distinguirla forensicamente de un cierre real desde el punto.';

header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();

// ── Step 1. Add forensic columns to checklist_entrega_v2 (idempotent) ─────
$forensicCols = [
    'forzado_admin'         => "TINYINT(1) NULL DEFAULT 0",
    'forzado_admin_motivo'  => "TEXT NULL",
    'forzado_admin_user_id' => "INT NULL",
    'forzado_admin_fecha'   => "DATETIME NULL",
];
try {
    $existing = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($forensicCols as $col => $def) {
        if (!in_array($col, $existing, true)) {
            try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("fix-adrian add $col: " . $e->getMessage()); }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Error preparing schema: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

// ── Step 2. Verify the target moto exists and is in the expected state ────
$st = $pdo->prepare("SELECT id, estado, cliente_nombre, cliente_acta_firmada
                       FROM inventario_motos WHERE id = ? LIMIT 1");
$st->execute([TARGET_MOTO_ID]);
$moto = $st->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$moto) {
    echo '<h1>❌ Moto ' . TARGET_MOTO_ID . ' no existe</h1>';
    echo '<p>El script aborta. Probablemente la moto fue borrada o el ID cambió.</p>';
    exit;
}
if ($moto['estado'] !== 'entregada') {
    echo '<h1>⚠ Moto ' . TARGET_MOTO_ID . ' no está en estado "entregada"</h1>';
    echo '<p>Estado actual: <code>' . htmlspecialchars($moto['estado']) . '</code>. Este script solo procesa casos donde '
       . 'estado=\'entregada\' pero el checklist está incompleto. Aborta.</p>';
    exit;
}

// ── Step 3. Check if the checklist is already complete (idempotency) ──────
$cl = $pdo->prepare("SELECT id, completado, fase1_completada, fase2_completada, fase3_completada,
                            forzado_admin, forzado_admin_fecha
                       FROM checklist_entrega_v2
                      WHERE moto_id = ?
                      ORDER BY id DESC LIMIT 1");
$cl->execute([TARGET_MOTO_ID]);
$row = $cl->fetch(PDO::FETCH_ASSOC) ?: null;

$alreadyComplete = $row
    && ((int)($row['completado'] ?? 0) === 1
     || ((int)($row['fase1_completada'] ?? 0) === 1
      && (int)($row['fase2_completada'] ?? 0) === 1
      && (int)($row['fase3_completada'] ?? 0) === 1));

if ($alreadyComplete) {
    echo '<h1>✅ Ya completado — nada que hacer</h1>';
    echo '<p>El checklist de la moto ' . TARGET_MOTO_ID . ' (' . htmlspecialchars((string)$moto['cliente_nombre']) . ') ya está marcado completado.</p>';
    if (!empty($row['forzado_admin'])) {
        echo '<p><strong>Cerrado por admin (forense):</strong> ' . htmlspecialchars((string)$row['forzado_admin_fecha']) . '</p>';
    }
    echo '<p>El banner amarillo debería haber desaparecido. Si todavía aparece, refresca el navegador con Ctrl+F5.</p>';
    echo '<p>Este script ya cumplió su función — puedes borrar el archivo.</p>';
    exit;
}

// ── Step 4. Force-close the checklist with forensic markers ───────────────
$adminUser = $_SESSION['admin_user_id'] ?? ($_SESSION['user_id'] ?? null);
$adminName = $_SESSION['admin_user_nombre'] ?? ($_SESSION['admin_email'] ?? 'admin');

$fields = [
    // F1 — Identidad (7)
    'ine_presentada' => 1, 'nombre_coincide' => 1, 'foto_coincide' => 1, 'datos_confirmados' => 1,
    'ultimos4_telefono' => 1, 'modelo_confirmado' => 1, 'forma_pago_confirmada' => 1,
    // F2 — Pago (4)
    'pago_confirmado' => 1, 'enganche_validado' => 1, 'metodo_pago_registrado' => 1, 'domiciliacion_confirmada' => 1,
    // F3 — Unidad (5)
    'vin_coincide' => 1, 'estado_fisico_ok' => 1, 'sin_danos' => 1, 'unidad_completa' => 1, 'unidad_ensamblada' => 1,
    // Fase completion flags
    'fase1_completada' => 1, 'fase2_completada' => 1, 'fase3_completada' => 1,
    'fase1_fecha' => date('Y-m-d H:i:s'),
    'fase2_fecha' => date('Y-m-d H:i:s'),
    'fase3_fecha' => date('Y-m-d H:i:s'),
    'completado'  => 1,
    // Forensic markers — distinguishes this row from a real operator completion
    'forzado_admin'         => 1,
    'forzado_admin_motivo'  => FORENSIC_REASON,
    'forzado_admin_user_id' => $adminUser ? (int)$adminUser : null,
    'forzado_admin_fecha'   => date('Y-m-d H:i:s'),
    'notas'                 => '[FORENSIC] Cierre administrativo retroactivo por ' . $adminName . ' — ' . date('Y-m-d H:i:s'),
];

try {
    if ($row) {
        // UPDATE only the columns that actually exist
        $existingCols = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2")->fetchAll(PDO::FETCH_COLUMN);
        $sets = []; $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $existingCols, true)) continue;
            $sets[] = "$k = ?";
            $params[] = $v;
        }
        $params[] = $row['id'];
        $pdo->prepare("UPDATE checklist_entrega_v2 SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);
    } else {
        // INSERT (no prior row — extremely unlikely for an entregada moto, but handled)
        $existingCols = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2")->fetchAll(PDO::FETCH_COLUMN);
        $cols = ['moto_id']; $vals = [TARGET_MOTO_ID];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $existingCols, true)) continue;
            $cols[] = $k; $vals[] = $v;
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO checklist_entrega_v2 (" . implode(',', $cols) . ") VALUES ($ph)")
            ->execute($vals);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>❌ Error al actualizar el checklist</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

// ── Step 5. Audit log ─────────────────────────────────────────────────────
if (function_exists('adminLog')) {
    adminLog('checklist_entrega_forzado_admin', [
        'moto_id'        => TARGET_MOTO_ID,
        'cliente_nombre' => $moto['cliente_nombre'] ?? '',
        'motivo'         => FORENSIC_REASON,
        'admin_user'     => $adminName,
    ]);
}

// ── Step 6. Done ──────────────────────────────────────────────────────────
echo '<h1>✅ Checklist forzosamente cerrado</h1>';
echo '<ul>';
echo '<li>moto_id: <strong>' . TARGET_MOTO_ID . '</strong></li>';
echo '<li>cliente: <strong>' . htmlspecialchars((string)$moto['cliente_nombre']) . '</strong></li>';
echo '<li>fase1/fase2/fase3_completada = 1</li>';
echo '<li>completado = 1</li>';
echo '<li>forzado_admin = 1 (marcador forense — para auditoría)</li>';
echo '<li>fecha = ' . date('Y-m-d H:i:s') . '</li>';
echo '<li>admin: ' . htmlspecialchars((string)$adminName) . '</li>';
echo '</ul>';
echo '<p>Abre el detalle de la moto en el admin (módulo Inventario → Ver). El banner amarillo "Inconsistencia en el estado de la moto" debe haber desaparecido.</p>';
echo '<p>Este script ya cumplió su función — puedes borrar /admin/php/inventario/fix-adrian-checklist-once.php.</p>';
