<?php
/**
 * Voltika — Diagnostic: was the inventory replacement actually applied?
 *
 * Customer asked 2026-05-04: "the DB already had 122 rows and the file
 * has 122 — how do I tell whether the replace actually happened?"
 *
 * The reemplazar-completo.php import stamps every newly-inserted row's
 * `log_estados` with action="reemplazo_completo" + the import filename.
 * That marker is a reliable fingerprint: if N rows carry it, N rows came
 * from the new file, and (total - N) are leftovers from before.
 *
 * GET /admin/php/inventario/verificar-reemplazo.php
 *
 * Response (JSON):
 *   total                    — total rows in inventario_motos
 *   con_marca_reemplazo      — rows whose log_estados has reemplazo_completo
 *   sin_marca                — rows that DON'T have that marker
 *   recientes_1h             — rows created in last hour
 *   ultima_importacion       — timestamp of the most recent reemplazo
 *   archivos_importados      — list of distinct filenames found in logs
 *   sample_marcadas          — first 5 VINs that DO have the marker
 *   sample_sin_marca         — first 5 VINs that DON'T have it
 *   diagnostico              — plain-text summary
 */

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

$pdo = getDB();

$total      = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos")->fetchColumn();
$conMarca   = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos WHERE log_estados LIKE '%reemplazo_completo%'")->fetchColumn();
$sinMarca   = $total - $conMarca;
$recientes  = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos WHERE freg >= (NOW() - INTERVAL 1 HOUR)")->fetchColumn();

$ultimaImp = $pdo->query("
    SELECT MAX(freg) FROM inventario_motos WHERE log_estados LIKE '%reemplazo_completo%'
")->fetchColumn();

// Distinct filenames that appear in any reemplazo_completo log entry —
// helps confirm which xlsx was actually applied.
$archivos = [];
$stmt = $pdo->query("SELECT DISTINCT log_estados FROM inventario_motos WHERE log_estados LIKE '%reemplazo_completo%' LIMIT 50");
foreach ($stmt as $r) {
    if (preg_match('/"archivo":"([^"]+)"/u', $r['log_estados'] ?? '', $m)) {
        $archivos[$m[1]] = true;
    }
}
$archivos = array_keys($archivos);

$sampleConMarca = $pdo->query("SELECT vin, modelo, color FROM inventario_motos WHERE log_estados LIKE '%reemplazo_completo%' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$sampleSinMarca = $pdo->query("SELECT vin, modelo, color FROM inventario_motos WHERE log_estados NOT LIKE '%reemplazo_completo%' OR log_estados IS NULL LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$diag = '';
if ($conMarca === $total && $total > 0) {
    $diag = "✅ TODO el inventario actual fue importado por reemplazo_completo. Reemplazo aplicado correctamente.";
} elseif ($conMarca > 0 && $sinMarca > 0) {
    $diag = "⚠ MIXTO — {$conMarca} filas son del reemplazo, {$sinMarca} son anteriores. El reemplazo aplicó parcialmente.";
} elseif ($conMarca === 0) {
    $diag = "❌ NINGUNA fila tiene marca de reemplazo_completo. El reemplazo NO se aplicó.";
} else {
    $diag = "Estado inesperado: total={$total}, con marca={$conMarca}, sin marca={$sinMarca}";
}

adminJsonOut([
    'ok'                  => true,
    'total'               => $total,
    'con_marca_reemplazo' => $conMarca,
    'sin_marca'           => $sinMarca,
    'recientes_1h'        => $recientes,
    'ultima_importacion'  => $ultimaImp ?: null,
    'archivos_importados' => $archivos,
    'sample_marcadas'     => $sampleConMarca,
    'sample_sin_marca'    => $sampleSinMarca,
    'diagnostico'         => $diag,
]);
