<?php
/**
 * DIAGNOSTIC — Why is "Mis compras" empty for a given cliente?
 *
 * Replicates the EXACT lookup logic of /clientes/php/cliente/compras.php and
 * shows the matched rows (or the lack thereof) for each step, so the admin
 * can pinpoint why a customer like Adrian sees "0 compras" on the portal.
 *
 * USE:
 *   /admin/php/inventario/diag-compras-cliente.php?cliente_id=147
 *   /admin/php/inventario/diag-compras-cliente.php?telefono=5215512345678
 *   /admin/php/inventario/diag-compras-cliente.php?email=adrian@example.com
 *
 * Auth: admin session required.
 *
 * READ-ONLY. Never UPDATEs anything.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

$cidArg = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
$telArg = trim((string)($_GET['telefono'] ?? ''));
$emArg  = trim((string)($_GET['email']    ?? ''));

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Diag · Mis compras</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12px;font-family:ui-monospace,Menlo,monospace;}
th{background:#f1f5f9;text-align:left;padding:6px 8px;font-size:11px;}
td{padding:6px 8px;border-top:1px solid #f1f5f9;vertical-align:top;word-break:break-all;}
.empty{color:#94a3b8;font-style:italic;font-size:13px;}
.ok{color:#15803d;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
form{margin:10px 0 20px;padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;}
form input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;margin-right:8px;}
form button{padding:6px 14px;background:#039fe1;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;}
</style></head><body>';
echo '<h1>🔍 Diagnóstico — "Mis compras" vacío</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Replica el lookup de <code>clientes/php/cliente/compras.php</code> y muestra dónde falla.</p>';

echo '<form method="get">';
echo '<input name="cliente_id" type="number" placeholder="cliente_id" value="' . htmlspecialchars((string)$cidArg) . '" />';
echo '<input name="telefono" placeholder="o teléfono (sin formato)" value="' . htmlspecialchars($telArg) . '" />';
echo '<input name="email" placeholder="o email" value="' . htmlspecialchars($emArg) . '" />';
echo '<button type="submit">Buscar</button>';
echo '</form>';

if (!$cidArg && $telArg === '' && $emArg === '') {
    echo '<p class="empty">Ingresa cliente_id, teléfono o email para diagnosticar.</p>';
    echo '</body></html>';
    exit;
}

function renderTable(array $rows, string $emptyMsg = '(sin filas)'): string {
    if (!$rows) return '<div class="empty">' . htmlspecialchars($emptyMsg) . '</div>';
    $cols = array_keys($rows[0]);
    $h = '<table><thead><tr>';
    foreach ($cols as $c) $h .= '<th>' . htmlspecialchars($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            if (is_string($v) && strlen($v) > 200) $v = substr($v, 0, 200) . '…';
            $h .= '<td>' . htmlspecialchars((string)$v) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}

// ── Step 1. Resolve cliente row ───────────────────────────────────────────
$cliente = null;
if ($cidArg > 0) {
    $st = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $st->execute([$cidArg]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($telArg !== '') {
    $tel10 = preg_replace('/\D/', '', $telArg);
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    $st = $pdo->prepare("SELECT * FROM clientes
        WHERE RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?
        ORDER BY id DESC LIMIT 1");
    $st->execute([$tel10]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($emArg !== '') {
    $st = $pdo->prepare("SELECT * FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$emArg]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

echo '<div class="sec"><h2>1. clientes (row del portal)</h2>';
if (!$cliente) {
    echo '<div class="err">✗ NO existe ninguna fila en <code>clientes</code> para los criterios dados. El cliente nunca completó el login OTP.</div>';
    echo '</div></body></html>';
    exit;
}
echo renderTable([$cliente]);
echo '</div>';

$cid   = (int)$cliente['id'];
$tel   = (string)($cliente['telefono'] ?? '');
$email = (string)($cliente['email']    ?? '');
$tel10 = preg_replace('/\D/', '', $tel);
if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);

echo '<div class="hint">Valores normalizados que <code>compras.php</code> usará para buscar:<br>'
   . '<code>cliente_id = ' . $cid . '</code> · '
   . '<code>tel10 = ' . htmlspecialchars($tel10 ?: '(vacío)') . '</code> · '
   . '<code>email = ' . htmlspecialchars($email ?: '(vacío)') . '</code></div>';

// ── Step 2. subscripciones_credito ────────────────────────────────────────
echo '<div class="sec"><h2>2. subscripciones_credito (réplica del lookup)</h2>';
try {
    $sql = "SELECT id, cliente_id, nombre, email, telefono, modelo, color, monto_semanal, estado, freg
            FROM subscripciones_credito
            WHERE cliente_id = ?";
    $params = [$cid];
    if ($tel10) { $sql .= " OR RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?"; $params[] = $tel10; }
    if ($email) { $sql .= " OR email = ?"; $params[] = $email; }
    $sql .= " ORDER BY id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs) {
        echo '<div class="err">✗ 0 filas con el lookup completo (cliente_id OR tel10 OR email).</div>';
        echo '<p style="font-size:12px;color:#64748b;">Si el cliente debería tener crédito → la fila existe pero no matchea por ninguno de los 3 criterios. Inspeccionar:</p>';
        $brute = $pdo->query("SELECT id, cliente_id, nombre, email, telefono, modelo, estado, freg FROM subscripciones_credito ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        echo '<p style="font-size:12px;color:#64748b;margin-top:14px;">Últimas 10 subscripciones (para buscar manualmente el match correcto):</p>';
        echo renderTable($brute);
    } else {
        echo '<div class="ok">✓ ' . count($subs) . ' subscripción(es) encontradas.</div>';
        echo renderTable($subs);
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// ── Step 3. transacciones ──────────────────────────────────────────────────
echo '<div class="sec"><h2>3. transacciones (contado / msi / spei / oxxo)</h2>';
try {
    $where = [];
    $params = [];
    if ($tel10) { $where[] = "RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?"; $params[] = $tel10; }
    if ($email) { $where[] = "email = ?"; $params[] = $email; }
    if (!$where) {
        echo '<div class="warn">⚠ cliente sin teléfono ni email → la búsqueda de transacciones devuelve 0 por construcción.</div>';
    } else {
        $sql = "SELECT id, pedido, nombre, email, telefono, modelo, color, total, tpago, msi_meses, pago_estado, freg
                FROM transacciones
                WHERE (" . implode(' OR ', $where) . ")
                  AND tpago IN ('contado','msi','spei','oxxo')
                ORDER BY id DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $txns = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$txns) {
            echo '<div class="warn">⚠ 0 transacciones con tpago IN (contado, msi, spei, oxxo).</div>';
        } else {
            echo '<div class="ok">✓ ' . count($txns) . ' transacción(es) encontradas.</div>';
            echo renderTable($txns);
        }

        // Also show transactions WITHOUT the tpago filter — to spot rows
        // with tpago='credito' or another value that compras.php skips.
        $sqlAll = "SELECT id, pedido, nombre, email, telefono, modelo, color, total, tpago, pago_estado, freg
                   FROM transacciones
                   WHERE (" . implode(' OR ', $where) . ")
                   ORDER BY id DESC";
        $st2 = $pdo->prepare($sqlAll);
        $st2->execute($params);
        $allTxns = $st2->fetchAll(PDO::FETCH_ASSOC);
        if (count($allTxns) > count($txns)) {
            echo '<div class="hint">⚠ Existen ' . (count($allTxns) - count($txns)) . ' transacción(es) más para este cliente que <code>compras.php</code> NO muestra porque su <code>tpago</code> no está en la lista permitida:</div>';
            // Diff by id (avoid array_combine + array_diff_key crash when ids are non-zero keys).
            $allowedIds = array_column($txns, 'id');
            $extras = array_values(array_filter($allTxns, function($r) use ($allowedIds) {
                return !in_array($r['id'], $allowedIds, true);
            }));
            echo renderTable($extras);
        }
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// ── Step 4. inventario_motos ──────────────────────────────────────────────
echo '<div class="sec"><h2>4. inventario_motos (motos asociadas)</h2>';
try {
    $sql = "SELECT id, vin_display, vin, estado, modelo, color, cliente_id, cliente_nombre, cliente_telefono, cliente_email, freg
            FROM inventario_motos
            WHERE cliente_id = ?";
    $params = [$cid];
    if ($tel10) {
        $sql .= " OR RIGHT(REPLACE(REPLACE(cliente_telefono,'+',''),' ',''),10) = ?";
        $params[] = $tel10;
    }
    if ($email) {
        $sql .= " OR cliente_email = ?";
        $params[] = $email;
    }
    $sql .= " ORDER BY freg DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $motos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$motos) {
        echo '<div class="err">✗ 0 motos. Si el cliente tiene una moto física entregada → problema de matching.</div>';
    } else {
        echo '<div class="ok">✓ ' . count($motos) . ' moto(s).</div>';
        echo renderTable($motos);
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// ── Step 5. Diagnostic summary ────────────────────────────────────────────
echo '<div class="sec" style="background:#eff6ff;border-color:#3b82f6;">';
echo '<h2 style="color:#1e40af;margin-top:0;">📋 Diagnóstico</h2>';
echo '<ul style="font-size:13px;line-height:1.7;margin:6px 0;padding-left:20px;">';
echo '<li><b>cliente_id</b>: ' . $cid . '</li>';
echo '<li><b>tel10 normalizado</b>: ' . htmlspecialchars($tel10 ?: '(vacío)') . '</li>';
echo '<li><b>email</b>: ' . htmlspecialchars($email ?: '(vacío)') . '</li>';
echo '<li>Si la sección 2 está vacía pero existen subs con teléfono parecido → ajustar el formato del teléfono en <code>clientes</code> o en <code>subscripciones_credito</code> para que coincidan (norma: últimos 10 dígitos).</li>';
echo '<li>Si la sección 3 muestra "Existen N transacciones más que compras.php NO muestra" → la transacción tiene un <code>tpago</code> que está fuera del filtro (probable: <code>credito</code> u otro). Decidir si agregar al filtro de compras.php o crear sub_credito correspondiente.</li>';
echo '<li>Si la sección 4 muestra la moto pero las secciones 2 y 3 están vacías → el cliente tiene moto física pero el origen del pedido (subscripción o transacción) está roto. Backfill manual de subscripciones_credito.cliente_id o de transacciones.cliente_id.</li>';
echo '</ul>';
echo '</div>';

echo '</body></html>';
