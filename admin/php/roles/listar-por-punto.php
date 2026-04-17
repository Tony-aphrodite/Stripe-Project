<?php
/**
 * GET ?punto_id=N — List dealer_usuarios assigned to a specific punto.
 * Response: { ok, users: [{ id, nombre, email, rol, activo, ultimo_login, freg }] }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$puntoId = (int)($_GET['punto_id'] ?? 0);
if (!$puntoId) adminJsonOut(['error' => 'punto_id requerido'], 400);

$pdo = getDB();

try {
    // Detect column names (schema may vary across installs)
    $cols = $pdo->query("SHOW COLUMNS FROM dealer_usuarios")->fetchAll(PDO::FETCH_COLUMN);
    $hasUltimoLogin = in_array('ultimo_login', $cols, true);
    $hasFreg        = in_array('freg', $cols, true);

    $select = "id, nombre, email, rol, activo";
    if ($hasUltimoLogin) $select .= ", ultimo_login";
    if ($hasFreg)        $select .= ", freg";

    $stmt = $pdo->prepare("SELECT $select FROM dealer_usuarios
        WHERE punto_id = ? ORDER BY activo DESC, id DESC");
    $stmt->execute([$puntoId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut(['ok' => true, 'users' => $users]);
} catch (Throwable $e) {
    error_log('listar-por-punto: ' . $e->getMessage());
    adminJsonOut(['error' => 'Error al listar usuarios: ' . $e->getMessage()], 500);
}
