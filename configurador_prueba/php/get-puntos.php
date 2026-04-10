<?php
/**
 * GET — Public endpoint: returns active Puntos Voltika for configurador
 * Used by paso3-delivery.js to show delivery point options
 * Replaces the hardcoded centros-entrega3.js data
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300'); // 5 min cache

require_once __DIR__ . '/config.php';
$pdo = getDB();

try {
    $rows = $pdo->query("
        SELECT id, slug, nombre, tipo, direccion, colonia, ciudad, estado, cp,
               telefono, email, lat, lng, horarios, servicios, tags, zonas,
               descripcion, autorizado
        FROM puntos_voltika
        WHERE activo = 1
        ORDER BY FIELD(tipo, 'center', 'certificado', 'entrega'), nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $puntos = [];
    foreach ($rows as $r) {
        $puntos[] = [
            'id'          => $r['slug'] ?: ('punto-' . $r['id']),
            'db_id'       => (int)$r['id'],
            'nombre'      => $r['nombre'],
            'tipo'        => $r['tipo'] ?: 'entrega',
            'direccion'   => $r['direccion'] ?: '',
            'colonia'     => $r['colonia'] ?: '',
            'ubicacion'   => trim(($r['ciudad'] ?: '') . ' – ' . ($r['estado'] ?: ''), ' –'),
            'ciudad'      => $r['ciudad'] ?: '',
            'estado'      => $r['estado'] ?: '',
            'cp'          => $r['cp'] ?: '',
            'telefono'    => $r['telefono'] ?: '',
            'email'       => $r['email'] ?: '',
            'lat'         => $r['lat'] ? (float)$r['lat'] : null,
            'lng'         => $r['lng'] ? (float)$r['lng'] : null,
            'horarios'    => $r['horarios'] ?: '',
            'servicios'   => json_decode($r['servicios'] ?: '[]', true) ?: [],
            'tags'        => json_decode($r['tags'] ?: '[]', true) ?: [],
            'zonas'       => json_decode($r['zonas'] ?: '[]', true) ?: [],
            'descripcion' => $r['descripcion'] ?: '',
            'autorizado'  => (bool)$r['autorizado'],
        ];
    }

    echo json_encode(['ok' => true, 'puntos' => $puntos], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('get-puntos error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al cargar puntos']);
}
