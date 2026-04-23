<?php
/**
 * ACTA fix verification (STAGING mirror).
 * See production copy at ../../../clientes/php/diag/verificar-fix.php
 */

$secret = $_GET['key'] ?? '';
$expected = getenv('VOLTIKA_DIAG_KEY') ?: 'voltika_acta_2026';
if ($secret !== $expected) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$helperLoaded = function_exists('portalFindOwnedMoto');

$tel   = trim((string)($_GET['tel']   ?? ''));
$email = trim((string)($_GET['email'] ?? ''));
$vin   = trim((string)($_GET['vin']   ?? ''));

$pdo = getDB();

foreach ([
    "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT(1) DEFAULT 0",
    "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_fecha DATETIME NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* already exists */ }
}

$cliente = null;
$motos   = [];
$dbError = null;

if ($tel !== '') {
    $tel10 = preg_replace('/\D/', '', $tel);
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    $stmt = $pdo->prepare("SELECT * FROM clientes
        WHERE RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''), 10) = ?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tel10]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($email !== '') {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$aliases = ['tels' => [], 'emails' => []];
$motoByVin = null;
$subs = [];

if ($cliente) {
    if ($helperLoaded) {
        try { $aliases = portalCollectContactAliases((int)$cliente['id']); } catch (Throwable $e) {}
    }

    try {
        $where = ["cliente_id = ?"]; $params = [(int)$cliente['id']];
        foreach ($aliases['tels'] as $t) {
            $where[] = "RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''), 10) = ?";
            $params[] = $t;
        }
        foreach ($aliases['emails'] as $em) {
            $where[] = "LOWER(email) = ?";
            $params[] = $em;
        }
        $s = $pdo->prepare("SELECT id, cliente_id, telefono, email, modelo, estado
            FROM subscripciones_credito WHERE " . implode(' OR ', $where) . " ORDER BY id DESC LIMIT 10");
        $s->execute($params);
        $subs = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    try {
        $where = ["cliente_id = ?"]; $params = [(int)$cliente['id']];
        foreach ($aliases['tels'] as $t) {
            $where[] = "(cliente_telefono IS NOT NULL AND cliente_telefono <> '' AND
                         RIGHT(REPLACE(REPLACE(cliente_telefono,'+',''),' ',''), 10) = ?)";
            $params[] = $t;
        }
        foreach ($aliases['emails'] as $em) {
            $where[] = "(cliente_email IS NOT NULL AND cliente_email <> '' AND LOWER(cliente_email) = ?)";
            $params[] = $em;
        }
        $stmt = $pdo->prepare("SELECT id, modelo, color, vin, estado, cliente_id, cliente_telefono,
                cliente_email, cliente_acta_firmada, cliente_acta_fecha
            FROM inventario_motos WHERE " . implode(' OR ', $where) . "
            ORDER BY id DESC LIMIT 20");
        $stmt->execute($params);
        $motos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if ($vin !== '') {
    try {
        $s = $pdo->prepare("SELECT id, modelo, color, vin, estado, cliente_id, cliente_telefono,
                cliente_email, cliente_acta_firmada, cliente_acta_fecha
            FROM inventario_motos WHERE vin = ? OR vin LIKE ? LIMIT 1");
        $s->execute([$vin, '%' . $vin . '%']);
        $motoByVin = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function diagMask(string $v, int $keep = 2): string {
    $l = strlen($v);
    if ($l === 0) return '(vacío)';
    if ($l <= $keep * 2) return str_repeat('*', $l);
    return substr($v, 0, $keep) . str_repeat('*', $l - $keep * 2) . substr($v, -$keep);
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>ACTA Fix Verification (STAGING)</title>
<style>
body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; padding:24px; }
.container { max-width: 1100px; margin: 0 auto; }
h1 { color:#fbbf24; font-size:24px; margin:0 0 16px; }
h2 { color:#60a5fa; font-size:18px; margin:24px 0 12px; border-bottom:1px solid #334155; padding-bottom:6px; }
.card { background:#1e293b; border:1px solid #334155; border-radius:8px; padding:16px; margin:12px 0; }
.ok { color:#10b981; font-weight:700; }
.bad { color:#ef4444; font-weight:700; }
.warn { color:#f59e0b; font-weight:700; }
.kv { display:grid; grid-template-columns: 200px 1fr; gap:6px 16px; font-family: Consolas, monospace; font-size:13px; }
.kv > div:nth-child(odd) { color:#94a3b8; }
table { width:100%; border-collapse: collapse; font-size:13px; }
th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #334155; }
th { color:#94a3b8; font-weight:600; }
.badge { padding:2px 8px; border-radius:999px; font-size:11px; }
.b-ok { background:#065f46; color:#d1fae5; }
.b-bad { background:#7f1d1d; color:#fee2e2; }
.b-warn { background:#78350f; color:#fef3c7; }
form { display:flex; gap:8px; margin:12px 0; }
input { background:#0b1220; border:1px solid #334155; color:#e2e8f0; padding:8px 12px; border-radius:6px; font-family: Consolas, monospace; }
button { background:#3b82f6; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:600; }
</style>
</head>
<body>
<div class="container">
<h1>🧪 ACTA Fix Verification <span style="color:#f59e0b;font-size:14px;">(STAGING)</span></h1>

<h2>1. Despliegue</h2>
<div class="card">
<div class="kv">
    <div>portalFindOwnedMoto()</div>
    <div><?= $helperLoaded
        ? '<span class="ok">✅ cargado</span>'
        : '<span class="bad">❌ NO cargado — bootstrap.php no se actualizó</span>' ?></div>
    <div>bootstrap path</div>
    <div><?= htmlspecialchars(realpath(__DIR__ . '/../bootstrap.php') ?: '(not resolved)') ?></div>
</div>
</div>

<h2>2. Buscar cliente / moto</h2>
<div class="card">
<form method="GET">
    <input type="hidden" name="key" value="<?= htmlspecialchars($secret) ?>">
    <input type="text" name="tel" placeholder="Teléfono" value="<?= htmlspecialchars($tel) ?>">
    <span style="align-self:center;color:#94a3b8">o</span>
    <input type="email" name="email" placeholder="Email" value="<?= htmlspecialchars($email) ?>" style="flex:1">
    <span style="align-self:center;color:#94a3b8">o</span>
    <input type="text" name="vin" placeholder="VIN" value="<?= htmlspecialchars($vin) ?>" style="font-family:Consolas,monospace">
    <button type="submit">Verificar</button>
</form>
</div>

<?php if ($vin !== ''): ?>
<h2>Moto por VIN</h2>
<?php if (!$motoByVin): ?>
<div class="card" style="border-color:#f59e0b;"><span class="warn">⚠ No se encontró moto con VIN "<?= htmlspecialchars($vin) ?>".</span></div>
<?php else: ?>
<div class="card">
<div class="kv">
    <div>moto id</div><div><strong><?= (int)$motoByVin['id'] ?></strong></div>
    <div>modelo / color</div><div><?= htmlspecialchars($motoByVin['modelo'] ?? '') ?> · <?= htmlspecialchars($motoByVin['color'] ?? '') ?></div>
    <div>VIN completo</div><div style="font-family:Consolas,monospace"><?= htmlspecialchars($motoByVin['vin'] ?? '') ?></div>
    <div>estado</div><div><?= htmlspecialchars($motoByVin['estado'] ?? '') ?></div>
    <div>cliente_id</div><div><?= (int)($motoByVin['cliente_id'] ?? 0) ?: '<span class="warn">NULL</span>' ?></div>
    <div>cliente_telefono</div><div><?= htmlspecialchars((string)($motoByVin['cliente_telefono'] ?? '')) ?: '<span class="warn">(vacío)</span>' ?></div>
    <div>cliente_email</div><div><?= htmlspecialchars((string)($motoByVin['cliente_email'] ?? '')) ?: '<span class="warn">(vacío)</span>' ?></div>
    <div>ACTA firmada</div><div><?= !empty($motoByVin['cliente_acta_firmada']) ? '<span class="ok">sí</span>' : '<span class="warn">pendiente</span>' ?></div>
</div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tel !== '' || $email !== ''): ?>

<h2>3. Cliente</h2>
<?php if (!$cliente): ?>
<div class="card" style="border-color:#f59e0b;"><span class="warn">⚠ Cliente no encontrado.</span></div>
<?php else: ?>
<div class="card">
<div class="kv">
    <div>cliente_id</div><div><strong><?= (int)$cliente['id'] ?></strong></div>
    <div>nombre</div><div><?= htmlspecialchars($cliente['nombre'] ?? '') ?></div>
    <div>telefono</div><div><?= htmlspecialchars((string)($cliente['telefono'] ?? '')) ?></div>
    <div>email</div><div><?= htmlspecialchars((string)($cliente['email'] ?? '')) ?></div>
</div>
</div>

<h2>3b. Contactos alias</h2>
<div class="card">
<div class="kv">
    <div>Teléfonos</div>
    <div><?= $aliases['tels'] ? '<code>' . htmlspecialchars(implode(', ', $aliases['tels'])) . '</code>' : '<span class="warn">(ninguno)</span>' ?></div>
    <div>Emails</div>
    <div><?= $aliases['emails'] ? '<code>' . htmlspecialchars(implode(', ', $aliases['emails'])) . '</code>' : '<span class="warn">(ninguno)</span>' ?></div>
</div>
</div>

<?php if ($subs): ?>
<h2>3c. Subscripciones (<?= count($subs) ?>)</h2>
<div class="card" style="padding:0;overflow:auto;">
<table>
<thead><tr><th>id</th><th>cliente_id</th><th>modelo</th><th>telefono</th><th>email</th><th>estado</th></tr></thead>
<tbody>
<?php foreach ($subs as $s): ?>
<tr>
    <td><strong><?= (int)$s['id'] ?></strong></td>
    <td><?= (int)($s['cliente_id'] ?? 0) ?: '<span class="warn">NULL</span>' ?></td>
    <td><?= htmlspecialchars($s['modelo'] ?? '') ?></td>
    <td style="font-family:Consolas,monospace"><?= htmlspecialchars((string)($s['telefono'] ?? '')) ?></td>
    <td><?= htmlspecialchars((string)($s['email'] ?? '')) ?></td>
    <td><?= htmlspecialchars((string)($s['estado'] ?? '')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<h2>4. Motos (<?= count($motos) ?>)</h2>
<?php if ($dbError): ?>
<div class="card" style="border-color:#ef4444;">
    <span class="bad">❌ Error consultando motos:</span><br>
    <code style="font-size:12px;color:#fca5a5;"><?= htmlspecialchars($dbError) ?></code>
</div>
<?php endif; ?>
<?php if (!$motos && !$dbError): ?>
<div class="card" style="border-color:#f59e0b;"><span class="warn">Sin motos asociadas.</span></div>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto;">
<table>
<thead><tr>
    <th>Moto #</th><th>Modelo</th><th>VIN</th><th>Estado</th>
    <th>cliente_id<br>en moto</th><th>Match path</th>
    <th>portalFindOwnedMoto()</th><th>ACTA</th>
</tr></thead>
<tbody>
<?php foreach ($motos as $m):
    $motoCid = (int)($m['cliente_id'] ?? 0);
    $clienteCid = (int)$cliente['id'];
    $tel10C = preg_replace('/\D/', '', (string)($cliente['telefono'] ?? ''));
    if (strlen($tel10C) > 10) $tel10C = substr($tel10C, -10);
    $tel10M = preg_replace('/\D/', '', (string)($m['cliente_telefono'] ?? ''));
    if (strlen($tel10M) > 10) $tel10M = substr($tel10M, -10);
    $emailC = strtolower((string)($cliente['email'] ?? ''));
    $emailM = strtolower((string)($m['cliente_email'] ?? ''));
    $paths = [];
    if ($motoCid && $motoCid === $clienteCid) $paths[] = 'cliente_id';
    if ($tel10C && strlen($tel10C) === 10 && $tel10C === $tel10M) $paths[] = 'telefono';
    if ($emailC !== '' && $emailM !== '' && $emailC === $emailM) $paths[] = 'email';
    $resolved = $helperLoaded ? portalFindOwnedMoto($clienteCid, (int)$m['id']) : null;
    $resolveOk = $resolved !== null;
?>
<tr>
    <td><strong><?= (int)$m['id'] ?></strong></td>
    <td><?= htmlspecialchars($m['modelo'] ?? '') ?></td>
    <td style="font-family:Consolas,monospace;font-size:11px"><?= htmlspecialchars(diagMask((string)($m['vin'] ?? ''), 4)) ?></td>
    <td><?= htmlspecialchars($m['estado'] ?? '') ?></td>
    <td>
        <?php if ($motoCid === $clienteCid && $motoCid > 0): ?>
            <span class="badge b-ok"><?= $motoCid ?> ✓</span>
        <?php elseif ($motoCid === 0): ?>
            <span class="badge b-warn">NULL</span>
        <?php else: ?>
            <span class="badge b-bad"><?= $motoCid ?> ≠ <?= $clienteCid ?></span>
        <?php endif; ?>
    </td>
    <td><?= $paths ? implode(', ', $paths) : '<span class="bad">ninguna</span>' ?></td>
    <td>
        <?php if (!$helperLoaded): ?>
            <span class="badge b-bad">helper no cargado</span>
        <?php elseif ($resolveOk): ?>
            <span class="badge b-ok">✅</span>
        <?php else: ?>
            <span class="badge b-bad">❌</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($m['cliente_acta_firmada'])): ?>
            <span class="badge b-ok">firmada</span>
        <?php else: ?>
            <span class="badge b-warn">pendiente</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<div style="text-align:center;color:#64748b;margin-top:40px;font-size:11px;">
    Staging · <?= date('Y-m-d H:i:s') ?>
</div>
</div>
</body>
</html>
