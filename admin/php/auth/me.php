<?php
require_once __DIR__ . '/../bootstrap.php';
if (empty($_SESSION['admin_user_id'])) adminJsonOut(['usuario' => null]);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id,nombre,email,rol,punto_nombre,punto_id FROM dealer_usuarios WHERE id=?");
$stmt->execute([$_SESSION['admin_user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
adminJsonOut(['usuario' => $u]);
