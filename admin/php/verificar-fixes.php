<?php
/**
 * Voltika Admin — Verificación de fixes recientes.
 *
 * Customer brief 2026-05-13 (Óscar, 13th round): comprehensive
 * diagnostic + backfill tool for the latest round of fixes. Renders an
 * HTML dashboard the admin can visit directly via browser, plus a
 * one-click "backfill legacy data" action that retroactively populates
 * cliente_id / transaccion_id on motos assigned BEFORE today's
 * asignar-moto.php update.
 *
 * URLs:
 *   GET  /admin/php/verificar-fixes.php
 *        → HTML dashboard with current state + buttons
 *   POST /admin/php/verificar-fixes.php
 *        Body: { action: "backfill_cliente_id" } → fix legacy motos
 *
 * Checks performed:
 *   • Issue A — cliente_id / transaccion_id population on inventario_motos
 *   • Issue B — SMS provider configuration + recent OTP attempts
 *   • Contratos firmados endpoint sanity
 *   • Test data leftover scan
 *   • Schema migrations (consultas_buro extra columns, contrato fields)
 */
require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$pdo = getDB();

// ── Handle POST actions (backfill) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = adminJsonIn();
    $action = (string)($body['action'] ?? '');

    try {
        if ($action === 'backfill_cliente_id') {
            // Resolve missing cliente_id by joining clientes by email/telefono.
            $sql = "
                UPDATE inventario_motos m
                JOIN clientes c ON (
                       (c.email    <> '' AND LOWER(c.email) = LOWER(COALESCE(m.cliente_email,'')))
                    OR (c.telefono <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(c.telefono,''),'+',''),' ',''),'-',''),10)
                                          = RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(m.cliente_telefono,''),'+',''),' ',''),'-',''),10)
                       AND LENGTH(REPLACE(REPLACE(REPLACE(c.telefono,'+',''),' ',''),'-','')) >= 10
                       AND LENGTH(REPLACE(REPLACE(REPLACE(m.cliente_telefono,'+',''),' ',''),'-','')) >= 10)
                )
                SET m.cliente_id = c.id
                WHERE m.cliente_id IS NULL
                  AND m.activo = 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $updated = $stmt->rowCount();
            adminLog('verificar_fixes_backfill_cliente_id', ['rows' => $updated]);
            echo json_encode(['ok' => true, 'updated' => $updated]);
            exit;
        }

        if ($action === 'backfill_transaccion_id') {
            // Resolve missing transaccion_id by matching stripe_pi (most reliable
            // since it's unique) then by pedido_num → CONCAT('VK-', pedido).
            $sql1 = "
                UPDATE inventario_motos m
                JOIN transacciones t ON (t.stripe_pi <> '' AND t.stripe_pi = m.stripe_pi)
                SET m.transaccion_id = t.id
                WHERE m.transaccion_id IS NULL
                  AND m.stripe_pi <> ''
                  AND m.activo = 1
            ";
            $r1 = $pdo->prepare($sql1);
            $r1->execute();
            $byStripe = $r1->rowCount();

            $sql2 = "
                UPDATE inventario_motos m
                JOIN transacciones t ON (CONCAT('VK-', t.pedido) = m.pedido_num AND t.pedido <> '')
                SET m.transaccion_id = t.id
                WHERE m.transaccion_id IS NULL
                  AND m.activo = 1
            ";
            $r2 = $pdo->prepare($sql2);
            $r2->execute();
            $byPedido = $r2->rowCount();

            adminLog('verificar_fixes_backfill_transaccion_id', [
                'by_stripe' => $byStripe, 'by_pedido' => $byPedido,
            ]);
            echo json_encode([
                'ok' => true,
                'updated' => $byStripe + $byPedido,
                'by_stripe' => $byStripe,
                'by_pedido' => $byPedido,
            ]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'acción desconocida: ' . $action]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── Schema discovery ───────────────────────────────────────────────────
function _hasColumn(PDO $pdo, string $table, string $col): bool {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        return in_array($col, $cols, true);
    } catch (Throwable $e) { return false; }
}

$imHasClienteId      = _hasColumn($pdo, 'inventario_motos', 'cliente_id');
$imHasTransaccionId  = _hasColumn($pdo, 'inventario_motos', 'transaccion_id');
$txHasPedidoCorto    = _hasColumn($pdo, 'transacciones', 'pedido_corto');
$txHasPdfPath        = _hasColumn($pdo, 'transacciones', 'contrato_pdf_path');
$txHasAcceptedAt     = _hasColumn($pdo, 'transacciones', 'contrato_aceptado_at');
$buroHasRfc          = _hasColumn($pdo, 'consultas_buro', 'rfc');

// ── Issue A — cliente_id / transaccion_id population ───────────────────
$issueA = [
    'assigned_total'         => 0,
    'missing_cliente_id'     => 0,
    'missing_transaccion_id' => 0,
    'resolvable_cliente_id'  => 0,
    'sample_broken'          => [],
];

try {
    $issueA['assigned_total'] = (int)$pdo->query("
        SELECT COUNT(*) FROM inventario_motos
        WHERE activo = 1
          AND (cliente_nombre IS NOT NULL AND cliente_nombre <> '')
    ")->fetchColumn();

    if ($imHasClienteId) {
        $issueA['missing_cliente_id'] = (int)$pdo->query("
            SELECT COUNT(*) FROM inventario_motos
            WHERE activo = 1
              AND cliente_id IS NULL
              AND (cliente_email IS NOT NULL OR cliente_telefono IS NOT NULL)
        ")->fetchColumn();

        // How many of those CAN be resolved by joining clientes
        $issueA['resolvable_cliente_id'] = (int)$pdo->query("
            SELECT COUNT(*) FROM inventario_motos m
            WHERE m.activo = 1
              AND m.cliente_id IS NULL
              AND EXISTS (
                  SELECT 1 FROM clientes c
                  WHERE (c.email <> '' AND LOWER(c.email) = LOWER(COALESCE(m.cliente_email,'')))
                     OR (c.telefono <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(c.telefono,''),'+',''),' ',''),'-',''),10)
                                            = RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(m.cliente_telefono,''),'+',''),' ',''),'-',''),10)
                                          AND LENGTH(REPLACE(REPLACE(REPLACE(c.telefono,'+',''),' ',''),'-','')) >= 10
                                          AND LENGTH(REPLACE(REPLACE(REPLACE(m.cliente_telefono,'+',''),' ',''),'-','')) >= 10)
              )
        ")->fetchColumn();
    }
    if ($imHasTransaccionId) {
        $issueA['missing_transaccion_id'] = (int)$pdo->query("
            SELECT COUNT(*) FROM inventario_motos
            WHERE activo = 1
              AND transaccion_id IS NULL
              AND (stripe_pi <> '' OR pedido_num <> '')
        ")->fetchColumn();
    }

    // Sample 5 broken rows for inspection
    if ($imHasClienteId) {
        $stmt = $pdo->query("
            SELECT m.id, m.vin_display, m.modelo, m.color, m.cliente_nombre,
                   m.cliente_email, m.cliente_telefono, m.estado,
                   m.punto_voltika_id, m.pedido_num
            FROM inventario_motos m
            WHERE m.activo = 1
              AND m.cliente_id IS NULL
              AND (m.cliente_email <> '' OR m.cliente_telefono <> '')
            ORDER BY m.id DESC LIMIT 5
        ");
        $issueA['sample_broken'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $issueA['error'] = $e->getMessage();
}

// ── Issue B — SMS configuration + recent OTP attempts ──────────────────
$issueB = [
    'sms_api_key_set'   => false,
    'voltika_notify'    => false,
    'recent_otp_attempts' => [],
    'failures_24h'      => 0,
];

// Load config to detect SMS key
try {
    foreach ([__DIR__ . '/../../configurador/php/config.php',
              __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
        if (is_file($cfg)) { @require_once $cfg; break; }
    }
    $issueB['sms_api_key_set'] = defined('SMSMASIVOS_API_KEY') && !empty(SMSMASIVOS_API_KEY);

    foreach ([__DIR__ . '/../../configurador/php/voltika-notify.php',
              __DIR__ . '/../../configurador_prueba_test/php/voltika-notify.php'] as $p) {
        if (is_file($p)) { @require_once $p; break; }
    }
    $issueB['voltika_notify'] = function_exists('voltikaNotify');
} catch (Throwable $e) {
    $issueB['config_error'] = $e->getMessage();
}

// Recent OTP attempts from admin_log (punto sends are logged as
// "punto:entrega_otp_enviado")
try {
    $stmt = $pdo->query("
        SELECT id, usuario_id, accion, detalle, freg
        FROM admin_log
        WHERE accion LIKE '%entrega_otp_enviado%'
        ORDER BY id DESC LIMIT 10
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = json_decode((string)$row['detalle'], true) ?: [];
        $issueB['recent_otp_attempts'][] = [
            'id'       => $row['id'],
            'freg'     => $row['freg'],
            'moto_id'  => $d['moto_id']     ?? null,
            'sms_ok'   => !empty($d['sms_ok']),
            'notify_ok'=> !empty($d['notify_ok']),
            'sms_http' => $d['sms_http']    ?? null,
            'sms_error'=> $d['sms_error']   ?? null,
            'sms_skip' => $d['sms_skip']    ?? null,
            'tel_norm' => $d['tel_normalized'] ?? null,
            'any_ok'   => !empty($d['any_channel']),
        ];
    }
    // Last-24h failure count
    $issueB['failures_24h'] = (int)$pdo->query("
        SELECT COUNT(*) FROM admin_log
        WHERE accion LIKE '%entrega_otp_enviado%'
          AND freg > NOW() - INTERVAL 1 DAY
          AND (detalle LIKE '%\"any_channel\":false%' OR detalle LIKE '%\"any_channel\":0%')
    ")->fetchColumn();
} catch (Throwable $e) {
    $issueB['log_error'] = $e->getMessage();
}

// ── Contratos firmados endpoint sanity ─────────────────────────────────
$contratosFirmados = [
    'rows_found' => 0,
    'kpi'        => null,
];
try {
    $signedConds = [];
    if ($txHasPdfPath)    $signedConds[] = "(t.contrato_pdf_path IS NOT NULL AND t.contrato_pdf_path <> '')";
    if ($txHasAcceptedAt) $signedConds[] = "(t.contrato_aceptado_at IS NOT NULL)";
    $signedConds[] = "(LOWER(COALESCE(t.tpago,'')) IN ('contado','unico','msi','spei','oxxo','tarjeta')
                       AND LOWER(COALESCE(t.pago_estado,'')) IN ('pagada','aprobada','approved','paid')
                       AND t.stripe_pi REGEXP '^pi_3[A-Za-z0-9]{20,}$')";
    if ($signedConds) {
        $contratosFirmados['rows_found'] = (int)$pdo->query("
            SELECT COUNT(*) FROM transacciones t
            WHERE " . implode(' OR ', $signedConds)
        )->fetchColumn();
    }
} catch (Throwable $e) {
    $contratosFirmados['error'] = $e->getMessage();
}

// ── Test data leftover scan ────────────────────────────────────────────
$testData = ['transacciones' => 0];
try {
    $testData['transacciones'] = (int)$pdo->query("
        SELECT COUNT(*) FROM transacciones
        WHERE LOWER(email) LIKE '%test%'
           OR LOWER(email) LIKE '%prueba%'
           OR LOWER(email) LIKE '%@mrcdev%'
           OR LOWER(email) = 'oscarlimon@gmail.com'
           OR pedido REGEXP '^[0-9]{1,4}$'
    ")->fetchColumn();
} catch (Throwable $e) { $testData['error'] = $e->getMessage(); }

// ── Render HTML dashboard ──────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika — Verificación de fixes</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1100px;margin:0 auto;}
  h1{font-size:24px;margin:0 0 4px;}
  h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
  .sub{color:#64748b;font-size:13px;margin-bottom:18px;}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:14px;}
  .kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .kpi{flex:1;min-width:160px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;}
  .kpi-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
  .kpi-value{font-size:26px;font-weight:800;color:#0c2340;margin-top:4px;}
  .kpi.ok    .kpi-value{color:#16a34a;}
  .kpi.warn  .kpi-value{color:#d97706;}
  .kpi.error .kpi-value{color:#dc2626;}
  .status-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;}
  .status-pill.ok{background:#dcfce7;color:#166534;}
  .status-pill.warn{background:#fef3c7;color:#92400e;}
  .status-pill.error{background:#fee2e2;color:#991b1b;}
  table{width:100%;border-collapse:collapse;margin-top:8px;}
  th,td{padding:8px 10px;text-align:left;font-size:12.5px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
  th{color:#475569;font-weight:700;text-transform:uppercase;font-size:10.5px;letter-spacing:.4px;background:#f8fafc;}
  code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;}
  .btn{background:#039fe1;color:#fff;border:0;padding:9px 16px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block;}
  .btn:hover{background:#0286c2;}
  .btn-warn{background:#d97706;} .btn-warn:hover{background:#b45309;}
  .btn-danger{background:#dc2626;} .btn-danger:hover{background:#991b1b;}
  .btn:disabled{opacity:.55;cursor:not-allowed;}
  .stamp{font-size:11px;color:#64748b;margin-top:10px;text-align:right;}
  .alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
  .alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
  .alert-warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
</style>
</head>
<body>

<h1>🔧 Verificación de fixes recientes</h1>
<div class="sub">Diagnóstico automático + acciones de respaldo para los cambios del 2026-05-13.</div>

<!-- ═══════════ ISSUE A ═══════════ -->
<h2>📡 Issue A — Visibilidad en portal de clientes</h2>
<div class="card">
  <div style="margin-bottom:12px;">
    <strong>Problema original:</strong> el cliente no veía su moto asignada en <code>voltika.mx/clientes/</code>.
    Causa: <code>inventario_motos.cliente_id</code> quedaba NULL después de Asignar moto.
  </div>
  <div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Motos asignadas (activas)</div><div class="kpi-value"><?= (int)$issueA['assigned_total'] ?></div></div>
    <div class="kpi <?= $issueA['missing_cliente_id'] > 0 ? 'warn' : 'ok' ?>"><div class="kpi-label">Sin cliente_id</div><div class="kpi-value"><?= (int)$issueA['missing_cliente_id'] ?></div></div>
    <div class="kpi <?= $issueA['missing_transaccion_id'] > 0 ? 'warn' : 'ok' ?>"><div class="kpi-label">Sin transaccion_id</div><div class="kpi-value"><?= (int)$issueA['missing_transaccion_id'] ?></div></div>
    <div class="kpi ok"><div class="kpi-label">Reparables con backfill</div><div class="kpi-value"><?= (int)$issueA['resolvable_cliente_id'] ?></div></div>
  </div>

  <?php if ($issueA['missing_cliente_id'] > 0): ?>
    <div class="alert alert-warn">
      <strong>⚠ Datos legacy detectados:</strong> hay <?= (int)$issueA['missing_cliente_id'] ?> moto(s) que se asignaron antes del fix de hoy.
      El siguiente botón empareja cada una con su cliente correspondiente en la tabla <code>clientes</code> (por email o teléfono).
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button class="btn btn-warn" id="btnBackfillCliente">🔧 Reparar cliente_id (<?= (int)$issueA['resolvable_cliente_id'] ?> motos)</button>
      <?php if ($issueA['missing_transaccion_id'] > 0): ?>
        <button class="btn btn-warn" id="btnBackfillTx">🔧 Reparar transaccion_id (<?= (int)$issueA['missing_transaccion_id'] ?> motos)</button>
      <?php endif; ?>
      <span id="backfillResult" style="font-size:13px;color:#475569;"></span>
    </div>
  <?php else: ?>
    <div class="alert alert-ok">✅ Todas las motos asignadas tienen <code>cliente_id</code> poblado. Issue A resuelto.</div>
  <?php endif; ?>

  <?php if (!empty($issueA['sample_broken'])): ?>
    <details style="margin-top:14px;">
      <summary style="cursor:pointer;font-size:12.5px;color:#475569;">Ver muestra de motos sin cliente_id (top 5)</summary>
      <table>
        <thead><tr><th>ID</th><th>VIN</th><th>Modelo</th><th>Cliente</th><th>Email / Tel</th><th>Pedido</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($issueA['sample_broken'] as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><code><?= htmlspecialchars((string)$r['vin_display']) ?></code></td>
            <td><?= htmlspecialchars(($r['modelo'] ?? '') . ' ' . ($r['color'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)$r['cliente_nombre']) ?></td>
            <td style="font-size:11px;color:#64748b;"><?= htmlspecialchars((string)$r['cliente_email']) ?><br><?= htmlspecialchars((string)$r['cliente_telefono']) ?></td>
            <td><code><?= htmlspecialchars((string)$r['pedido_num']) ?></code></td>
            <td><?= htmlspecialchars((string)$r['estado']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  <?php endif; ?>
</div>

<!-- ═══════════ ISSUE B ═══════════ -->
<h2>📲 Issue B — Entrega de OTP por SMS</h2>
<div class="card">
  <div style="margin-bottom:12px;">
    <strong>Problema original:</strong> el OTP no llegaba al cliente al iniciar la entrega.
    Diagnóstico nuevo: ahora cada intento registra reason / HTTP / response body.
  </div>
  <div class="kpi-row">
    <div class="kpi <?= $issueB['sms_api_key_set'] ? 'ok' : 'error' ?>">
      <div class="kpi-label">SMSMASIVOS_API_KEY</div>
      <div class="kpi-value" style="font-size:16px;"><?= $issueB['sms_api_key_set'] ? '✓ Configurado' : '✗ FALTA' ?></div>
    </div>
    <div class="kpi <?= $issueB['voltika_notify'] ? 'ok' : 'warn' ?>">
      <div class="kpi-label">voltikaNotify() disponible</div>
      <div class="kpi-value" style="font-size:16px;"><?= $issueB['voltika_notify'] ? '✓ Sí' : '✗ No' ?></div>
    </div>
    <div class="kpi <?= $issueB['failures_24h'] > 0 ? 'warn' : 'ok' ?>">
      <div class="kpi-label">Fallos OTP últimas 24h</div>
      <div class="kpi-value"><?= (int)$issueB['failures_24h'] ?></div>
    </div>
  </div>

  <?php if (!$issueB['sms_api_key_set']): ?>
    <div class="alert alert-warn">
      <strong>⚠ Acción requerida:</strong> el archivo <code>configurador/php/config.php</code> no define
      <code>SMSMASIVOS_API_KEY</code>. Sin esta clave, los SMS nunca se envían. Pide al admin del servidor
      que agregue la línea <code>define('SMSMASIVOS_API_KEY', '…');</code> con la clave actual de la cuenta SMSmasivos.
    </div>
  <?php endif; ?>

  <strong style="font-size:13px;">Últimos 10 intentos de OTP:</strong>
  <?php if (empty($issueB['recent_otp_attempts'])): ?>
    <div style="font-size:12.5px;color:#64748b;padding:10px;background:#f8fafc;border-radius:6px;margin-top:6px;">
      Sin intentos registrados todavía. Una vez que un punto envíe OTP, aparecerá aquí con el diagnóstico de cada canal.
    </div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Moto</th><th>SMS</th><th>HTTP</th><th>Notify</th><th>Skip</th><th>Tel norm.</th><th>OK?</th></tr></thead>
      <tbody>
      <?php foreach ($issueB['recent_otp_attempts'] as $a): ?>
        <tr>
          <td><?= htmlspecialchars((string)$a['freg']) ?></td>
          <td><?= (int)$a['moto_id'] ?></td>
          <td><?= $a['sms_ok'] ? '<span class="status-pill ok">✓</span>' : '<span class="status-pill error">✗</span>' ?></td>
          <td><?= htmlspecialchars((string)($a['sms_http'] ?? '—')) ?></td>
          <td><?= $a['notify_ok'] ? '<span class="status-pill ok">✓</span>' : '<span class="status-pill warn">—</span>' ?></td>
          <td><?= htmlspecialchars((string)($a['sms_skip'] ?? '—')) ?></td>
          <td style="font-size:11px;"><code><?= htmlspecialchars((string)($a['tel_norm'] ?? '—')) ?></code></td>
          <td><?= $a['any_ok'] ? '<span class="status-pill ok">OK</span>' : '<span class="status-pill error">FALLO</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ═══════════ Otros checks ═══════════ -->
<h2>📋 Estado de otros fixes recientes</h2>
<div class="card">
  <div class="kpi-row">
    <div class="kpi <?= $contratosFirmados['rows_found'] > 0 ? 'ok' : 'warn' ?>">
      <div class="kpi-label">Contratos firmados detectados</div>
      <div class="kpi-value"><?= (int)$contratosFirmados['rows_found'] ?></div>
    </div>
    <div class="kpi <?= $testData['transacciones'] === 0 ? 'ok' : 'warn' ?>">
      <div class="kpi-label">Datos test residuales</div>
      <div class="kpi-value"><?= (int)$testData['transacciones'] ?></div>
    </div>
    <div class="kpi <?= $buroHasRfc ? 'ok' : 'warn' ?>">
      <div class="kpi-label">CDC Excel cols listas</div>
      <div class="kpi-value" style="font-size:16px;"><?= $buroHasRfc ? '✓ Sí' : '✗ Migrar' ?></div>
    </div>
  </div>

  <table style="margin-top:14px;">
    <thead><tr><th>Check</th><th>Resultado</th><th>Próximo paso</th></tr></thead>
    <tbody>
      <tr>
        <td>Columna <code>inventario_motos.cliente_id</code></td>
        <td><?= $imHasClienteId ? '<span class="status-pill ok">Existe</span>' : '<span class="status-pill error">Falta</span>' ?></td>
        <td><?= $imHasClienteId ? '—' : 'Migrar schema (master-bootstrap.php)' ?></td>
      </tr>
      <tr>
        <td>Columna <code>inventario_motos.transaccion_id</code></td>
        <td><?= $imHasTransaccionId ? '<span class="status-pill ok">Existe</span>' : '<span class="status-pill error">Falta</span>' ?></td>
        <td><?= $imHasTransaccionId ? '—' : 'Migrar schema' ?></td>
      </tr>
      <tr>
        <td>Columna <code>transacciones.pedido_corto</code></td>
        <td><?= $txHasPedidoCorto ? '<span class="status-pill ok">Existe</span>' : '<span class="status-pill error">Falta</span>' ?></td>
        <td><?= $txHasPedidoCorto ? '—' : 'Migrar schema' ?></td>
      </tr>
      <tr>
        <td>Columna <code>transacciones.contrato_pdf_path</code></td>
        <td><?= $txHasPdfPath ? '<span class="status-pill ok">Existe</span>' : '<span class="status-pill warn">Falta</span>' ?></td>
        <td><?= $txHasPdfPath ? '—' : 'Migrar schema' ?></td>
      </tr>
      <tr>
        <td>Columnas extra <code>consultas_buro</code> (NIP-CIEC PF)</td>
        <td><?= $buroHasRfc ? '<span class="status-pill ok">OK</span>' : '<span class="status-pill warn">Auto-migrar al usar</span>' ?></td>
        <td><?= $buroHasRfc ? '—' : 'Click en Exportar Excel — se migra automático' ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="stamp">
  Generado: <?= date('Y-m-d H:i:s') ?> · Admin#<?= (int)$adminId ?> · voltika.mx
</div>

<script>
function postAction(action, btn, resultEl) {
  if (!confirm('¿Aplicar ' + action + '? Esta acción modifica la base de datos.')) return;
  btn.disabled = true;
  resultEl.textContent = 'Procesando...';
  fetch('/admin/php/verificar-fixes.php', {
    method: 'POST',
    credentials: 'include',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action: action})
  }).then(function(r){ return r.json(); }).then(function(r){
    if (r && r.ok) {
      resultEl.innerHTML = '<span style="color:#16a34a;font-weight:700;">✓ ' + (r.updated || 0) + ' filas actualizadas</span>';
      setTimeout(function(){ location.reload(); }, 1500);
    } else {
      resultEl.innerHTML = '<span style="color:#dc2626;">✗ ' + ((r && r.error) || 'error desconocido') + '</span>';
      btn.disabled = false;
    }
  }).catch(function(e){
    resultEl.innerHTML = '<span style="color:#dc2626;">✗ Red: ' + e.message + '</span>';
    btn.disabled = false;
  });
}
var btnC = document.getElementById('btnBackfillCliente');
var btnT = document.getElementById('btnBackfillTx');
var resEl = document.getElementById('backfillResult');
if (btnC) btnC.addEventListener('click', function(){ postAction('backfill_cliente_id', btnC, resEl); });
if (btnT) btnT.addEventListener('click', function(){ postAction('backfill_transaccion_id', btnT, resEl); });
</script>

</body>
</html>
