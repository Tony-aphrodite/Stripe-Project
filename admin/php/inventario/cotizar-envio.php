<?php
/**
 * POST — Get Skydropx shipping quote for CEDIS → Punto
 * Body: { "punto_id": 5 }
 * Returns estimated delivery days + date + carrier info
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../skydropx.php';
adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$puntoId = (int)($d['punto_id'] ?? 0);
if (!$puntoId) adminJsonOut(['error' => 'punto_id requerido'], 400);

$pdo = getDB();

// Get punto zip code
$stmt = $pdo->prepare("SELECT cp, nombre, ciudad FROM puntos_voltika WHERE id=? AND activo=1");
$stmt->execute([$puntoId]);
$punto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$punto) adminJsonOut(['error' => 'Punto no encontrado'], 404);
if (empty($punto['cp'])) adminJsonOut(['error' => 'El punto no tiene código postal configurado'], 400);

// CEDIS origin zip code
$cpOrigen = defined('CEDIS_CP') ? CEDIS_CP : '';
if (!$cpOrigen) adminJsonOut(['error' => 'CEDIS_CP no configurado en el servidor'], 400);

$result = skydropxCotizar($cpOrigen, $punto['cp']);

if (!$result['ok']) {
    adminJsonOut(['error' => $result['error'] ?? 'Error al cotizar'], 500);
}

adminJsonOut([
    'ok'             => true,
    'punto_nombre'   => $punto['nombre'],
    'punto_ciudad'   => $punto['ciudad'],
    'dias'           => $result['dias'],
    'fecha_estimada' => $result['fecha_estimada'],
    'carrier'        => $result['carrier'],
    'servicio'       => $result['servicio'],
    'precio'         => $result['precio'],
]);
