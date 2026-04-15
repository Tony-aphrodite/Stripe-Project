<?php
/**
 * POST — Manually update a subscripciones_credito row.
 *
 * Used by the "Editar VK-SC" modal for orphan rows that are missing
 * modelo/color/precio because they were created before Plan G (when
 * create-setup-intent.php didn't persist product context).
 *
 * Only fields whitelisted below can be updated. Empty string values are
 * ignored via COALESCE(NULLIF(...)) so the admin can update one field
 * at a time without accidentally blanking others.
 *
 * Body (JSON): {
 *   id:             int    (required — subscripciones_credito.id)
 *   modelo:         string
 *   color:          string
 *   precio_contado: number
 *   plazo_meses:    int
 *   monto_semanal:  number
 *   nombre:         string
 *   email:          string
 *   telefono:       string
 * }
 *
 * Response: { ok, updated_fields, row }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$body = adminJsonIn();
$id   = (int)($body['id'] ?? 0);

if ($id <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'id requerido'], 400);
}

$pdo = getDB();

// Verify row exists
$chk = $pdo->prepare("SELECT id FROM subscripciones_credito WHERE id = ?");
$chk->execute([$id]);
if (!$chk->fetchColumn()) {
    adminJsonOut(['ok' => false, 'error' => 'subscripciones_credito #' . $id . ' no existe'], 404);
}

// Whitelist + type coercion
$updates = [];
$params  = [':id' => $id];

$stringFields = ['modelo', 'color', 'nombre', 'email', 'telefono'];
foreach ($stringFields as $f) {
    if (isset($body[$f]) && is_string($body[$f]) && trim($body[$f]) !== '') {
        $updates[] = "`{$f}` = :{$f}";
        $params[":{$f}"] = trim($body[$f]);
    }
}

$numberFields = [
    'precio_contado' => 'float',
    'plazo_meses'    => 'int',
    'monto_semanal'  => 'float',
];
foreach ($numberFields as $f => $type) {
    if (isset($body[$f]) && is_numeric($body[$f]) && (float)$body[$f] > 0) {
        $updates[] = "`{$f}` = :{$f}";
        $params[":{$f}"] = $type === 'int' ? (int)$body[$f] : (float)$body[$f];
    }
}

if (!$updates) {
    adminJsonOut(['ok' => false, 'error' => 'Ningún campo válido para actualizar'], 400);
}

$sql = "UPDATE subscripciones_credito SET " . implode(', ', $updates) . " WHERE id = :id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $affected = $stmt->rowCount();
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'UPDATE falló: ' . $e->getMessage()], 500);
}

// Return the updated row for UI refresh
$row = $pdo->prepare("
    SELECT id, nombre, email, telefono, modelo, color,
           precio_contado, plazo_meses, monto_semanal, status
    FROM subscripciones_credito
    WHERE id = ?
");
$row->execute([$id]);
$updated = $row->fetch(PDO::FETCH_ASSOC);

adminLog('actualizar_vksc', [
    'id'       => $id,
    'fields'   => array_keys(array_filter($body, fn($v, $k) => $k !== 'id', ARRAY_FILTER_USE_BOTH)),
    'affected' => $affected,
]);

adminJsonOut([
    'ok'             => true,
    'updated_fields' => count($updates),
    'rows_affected'  => $affected,
    'row'            => $updated,
]);
