<?php
require_once __DIR__ . '/../bootstrap.php';
if (empty($_SESSION['punto_user_id'])) puntoJsonOut(['usuario' => null]);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id,nombre,email,punto_nombre,punto_id FROM dealer_usuarios WHERE id=?");
$stmt->execute([$_SESSION['punto_user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

$p = null;
if ($u && $u['punto_id']) {
    $pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=?");
    $pStmt->execute([$u['punto_id']]);
    $p = $pStmt->fetch(PDO::FETCH_ASSOC);
}
puntoJsonOut(['usuario' => $u, 'punto' => $p]);
