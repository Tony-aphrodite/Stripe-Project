<?php
require_once __DIR__ . '/../bootstrap.php';
if (empty($_SESSION['admin_user_id'])) adminJsonOut(['usuario' => null]);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id,nombre,email,rol,punto_nombre,punto_id,permisos FROM dealer_usuarios WHERE id=?");
$stmt->execute([$_SESSION['admin_user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if ($u) {
    // Decode permisos so the frontend can filter the sidebar.
    // Customer brief 2026-05-04 round 7: per-user sidebar filtering.
    $rawPerm = $u['permisos'] ?? null;
    $u['permisos'] = [];
    if ($rawPerm) {
        $decoded = json_decode((string)$rawPerm, true);
        if (is_array($decoded)) {
            $u['permisos'] = array_values(array_filter(array_map('strval', $decoded)));
        }
    }
}
adminJsonOut(['usuario' => $u]);
