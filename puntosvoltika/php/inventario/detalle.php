<?php
/**
 * GET ?id= — Detail of a moto assigned to this punto
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$id = (int)($_GET['id'] ?? 0);
if (!$id) puntoJsonOut(['error' => 'ID requerido'], 400);

$pdo = getDB();
$pid = $ctx['punto_id'];

$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$id, $pid]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en tu inventario'], 404);

puntoJsonOut(['ok' => true, 'moto' => $moto]);
