<?php
/**
 * Voltika Admin — Dump ALL customer data from all known tables.
 *
 * Customer brief 2026-05-28: stop forcing admin to type values. Find where
 * the customer's data actually lives in the DB (Truora INE OCR captures
 * CURP/birth date; credit application captures address; etc.) so the PAGARÉ
 * can be auto-populated from real sources.
 *
 * This shows every table that might contain CURP/address/RFC/DOB for a
 * given moto, so we know which fields are actually populated.
 *
 * Auth: admin only.
 * Usage: ?moto_id=142
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$motoId = (int)($_GET['moto_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Cliente data dump</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:20px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:22px 0 6px;text-transform:uppercase;letter-spacing:.4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;font-size:11.5px;}
th{background:#f1f5f9;text-align:left;padding:5px 8px;font-size:10.5px;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;font-family:ui-monospace,monospace;word-break:break-all;}
.has{background:#dcfce7;}
.empty{color:#94a3b8;font-style:italic;}
.lbl{font-weight:600;color:#0c2340;width:200px;font-family:system-ui,sans-serif;background:#f8fafc;}
form{margin-bottom:14px;}
input[type=number]{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;width:140px;}
button{padding:6px 14px;background:#039fe1;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;}
.banner{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px;}
</style></head><body>';
echo '<h1>🗂️ Cliente data dump</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Muestra TODOS los datos del cliente en cada tabla conocida — para saber dónde está la información real.</p>';

echo '<form method="get"><label>moto_id:</label> '
   . '<input type="number" name="moto_id" value="' . htmlspecialchars((string)$motoId) . '" required> '
   . '<button>Buscar</button></form>';

if (!$motoId) {
    echo '<div class="banner">Ingresa un moto_id para ver sus datos.</div></body></html>';
    exit;
}

function _row(string $label, $value): string {
    $v = is_array($value) ? json_encode($value) : (string)$value;
    $has = $v !== '' && $v !== null && $v !== '—';
    $cls = $has ? 'has' : 'empty';
    return '<tr><td class="lbl">' . htmlspecialchars($label) . '</td>'
         . '<td class="' . $cls . '">' . htmlspecialchars($v !== '' ? $v : '(vacío)') . '</td></tr>';
}

// ── 1. inventario_motos ────────────────────────────────────────────────
$moto = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$moto->execute([$motoId]);
$moto = $moto->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    echo '<div class="banner" style="background:#fee2e2;border-color:#fca5a5;">Moto no encontrada: id=' . $motoId . '</div></body></html>';
    exit;
}

$email   = (string)($moto['cliente_email'] ?? '');
$tel     = (string)($moto['cliente_telefono'] ?? '');
$cliId   = (int)($moto['cliente_id'] ?? 0);
$transId = (int)($moto['transaccion_id'] ?? 0);

echo '<h2>1. inventario_motos (id=' . $motoId . ')</h2><div class="card"><table>';
echo _row('cliente_id', $cliId ?: '(null)');
echo _row('cliente_nombre', $moto['cliente_nombre'] ?? '');
echo _row('cliente_email', $email);
echo _row('cliente_telefono', $tel);
echo _row('modelo', $moto['modelo'] ?? '');
echo _row('vin / vin_display', ($moto['vin_display'] ?? '') . ' / ' . ($moto['vin'] ?? ''));
echo _row('transaccion_id', $transId);
echo _row('estado', $moto['estado'] ?? '');
echo '</table></div>';

// ── 2. clientes (by cliente_id, email, telefono) ───────────────────────
echo '<h2>2. clientes (todas las filas posibles)</h2><div class="card">';
$clientes = [];
if ($cliId) {
    $q = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $q->execute([$cliId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $clientes[$r['id']] = $r;
}
if ($email) {
    $q = $pdo->prepare("SELECT * FROM clientes WHERE email = ?");
    $q->execute([$email]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $clientes[$r['id']] = $r;
}
if ($tel) {
    $q = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ?");
    $q->execute([$tel]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $clientes[$r['id']] = $r;
}
if (!$clientes) {
    echo '<div class="empty">(ningún registro en clientes)</div>';
} else {
    foreach ($clientes as $c) {
        echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$c['id'] . '</h3><table>';
        foreach ($c as $k => $v) echo _row($k, $v);
        echo '</table>';
    }
}
echo '</div>';

// ── 3. verificaciones_identidad (Truora) ───────────────────────────────
echo '<h2>3. verificaciones_identidad (Truora INE OCR)</h2><div class="card">';
$vis = [];
if ($email || $tel) {
    $q = $pdo->prepare("SELECT * FROM verificaciones_identidad
        WHERE (LENGTH(?) > 0 AND email = ?) OR (LENGTH(?) > 0 AND telefono = ?)
        ORDER BY id DESC");
    $q->execute([$email, $email, $tel, $tel]);
    $vis = $q->fetchAll(PDO::FETCH_ASSOC);
}
if (!$vis) {
    echo '<div class="empty">(ninguna verificación de identidad)</div>';
} else {
    foreach ($vis as $v) {
        echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$v['id'] . ' · status=' . htmlspecialchars((string)($v['status'] ?? '?')) . '</h3><table>';
        foreach ($v as $k => $vv) echo _row($k, $vv);
        echo '</table>';
    }
}
echo '</div>';

// ── 4. transacciones ───────────────────────────────────────────────────
echo '<h2>4. transacciones</h2><div class="card">';
$txs = [];
if ($transId) {
    $q = $pdo->prepare("SELECT * FROM transacciones WHERE id = ?");
    $q->execute([$transId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $txs[$r['id']] = $r;
}
if ($email) {
    $q = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 5");
    $q->execute([$email]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $txs[$r['id']] = $r;
}
if (!$txs) {
    echo '<div class="empty">(ninguna transacción)</div>';
} else {
    foreach ($txs as $t) {
        echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$t['id'] . ' · pedido=' . htmlspecialchars((string)($t['pedido'] ?? '?')) . '</h3><table>';
        foreach ($t as $k => $vv) echo _row($k, $vv);
        echo '</table>';
    }
}
echo '</div>';

// ── 5. subscripciones_credito ──────────────────────────────────────────
echo '<h2>5. subscripciones_credito</h2><div class="card">';
$subs = [];
if ($cliId) {
    $q = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE cliente_id = ? ORDER BY id DESC LIMIT 3");
    $q->execute([$cliId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $subs[$r['id']] = $r;
}
if (!$subs && $email) {
    $q = $pdo->prepare("SELECT sc.* FROM subscripciones_credito sc
        JOIN clientes c ON c.id = sc.cliente_id WHERE c.email = ? ORDER BY sc.id DESC LIMIT 3");
    $q->execute([$email]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $subs[$r['id']] = $r;
}
if (!$subs) {
    echo '<div class="empty">(ninguna subscripción)</div>';
} else {
    foreach ($subs as $s) {
        echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$s['id'] . '</h3><table>';
        foreach ($s as $k => $vv) echo _row($k, $vv);
        echo '</table>';
    }
}
echo '</div>';

// ── 6. firmas_contratos ────────────────────────────────────────────────
echo '<h2>6. firmas_contratos</h2><div class="card">';
$firmas = [];
if ($email) {
    $q = $pdo->prepare("SELECT id, tipo, nombre, email, telefono, modelo, pdf_file, sha256_hash, freg FROM firmas_contratos WHERE email = ? OR telefono = ? ORDER BY id DESC LIMIT 5");
    $q->execute([$email, $tel]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $firmas[$r['id']] = $r;
}
if (!$firmas) {
    echo '<div class="empty">(ninguna firma)</div>';
} else {
    foreach ($firmas as $f) {
        echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$f['id'] . ' · ' . htmlspecialchars((string)($f['tipo'] ?? '')) . '</h3><table>';
        foreach ($f as $k => $vv) echo _row($k, $vv);
        echo '</table>';
    }
}
echo '</div>';

// ── 7b. consultas_buro (CDC — Círculo de Crédito) ─────────────────────
echo '<h2>7b. consultas_buro (CDC — Círculo de Crédito)</h2><div class="card">';
$nombre = (string)($moto['cliente_nombre'] ?? '');
$firstName = trim(explode(' ', $nombre)[0] ?? '');
try {
    $params = [];
    $where  = [];
    if ($firstName !== '') {
        $where[] = "(nombre LIKE ? OR CONCAT(nombre,' ',apellido_paterno) LIKE ?)";
        $params[] = '%' . strtoupper($firstName) . '%';
        $params[] = '%' . strtoupper($nombre) . '%';
    }
    if ($where) {
        $q = $pdo->prepare("SELECT * FROM consultas_buro WHERE " . implode(' OR ', $where) . " ORDER BY id DESC LIMIT 5");
        $q->execute($params);
        $buros = $q->fetchAll(PDO::FETCH_ASSOC);
    } else { $buros = []; }
    if (!$buros) {
        echo '<div class="empty">(ninguna consulta CDC para este nombre)</div>';
    } else {
        foreach ($buros as $b) {
            echo '<h3 style="font-size:13px;margin:8px 0;">id=' . (int)$b['id'] . ' · ' . htmlspecialchars((string)($b['nombre'] ?? '')) . ' ' . htmlspecialchars((string)($b['apellido_paterno'] ?? '')) . '</h3><table>';
            foreach ($b as $k => $vv) {
                if ($k === 'raw_response') continue;
                echo _row($k, $vv);
            }
            echo '</table>';
        }
    }
} catch (Throwable $e) {
    echo '<div class="empty">(tabla no existe o error: ' . htmlspecialchars($e->getMessage()) . ')</div>';
}
echo '</div>';

// ── 7. truora_curp_audit ──────────────────────────────────────────────
echo '<h2>7. truora_curp_audit</h2><div class="card">';
try {
    $q = $pdo->prepare("SELECT * FROM truora_curp_audit
        WHERE process_id IN (SELECT process_id FROM verificaciones_identidad
                              WHERE email = ? OR telefono = ?)
        ORDER BY id DESC LIMIT 10");
    $q->execute([$email, $tel]);
    $auds = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$auds) {
        echo '<div class="empty">(ninguna auditoría CURP)</div>';
    } else {
        foreach ($auds as $a) {
            echo '<table>';
            foreach ($a as $k => $vv) echo _row($k, $vv);
            echo '</table><div style="height:6px;"></div>';
        }
    }
} catch (Throwable $e) {
    echo '<div class="empty">(tabla no existe: ' . htmlspecialchars($e->getMessage()) . ')</div>';
}
echo '</div>';

echo '</body></html>';
