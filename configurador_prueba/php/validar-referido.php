<?php
/**
 * GET — Public endpoint: validates a CODIGO REFERIDO entered in the configurador.
 * Accepts codes from two sources:
 *   1) `referidos.codigo_referido`       (influencer / individual referrals)
 *   2) `puntos_voltika.codigo_referido`  (point-owned referral codes — CASE 3/4)
 *
 * Query: ?codigo=ABC123
 * Response:
 *   { ok: true, tipo: 'referido'|'punto', id, nombre, punto_slug? }
 *   { ok: false, error: 'Código no válido' }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

$codigo = strtoupper(trim($_GET['codigo'] ?? ''));
if ($codigo === '' || strlen($codigo) > 40) {
    echo json_encode(['ok' => false, 'error' => 'Código vacío o demasiado largo']);
    exit;
}

try {
    $pdo = getDB();

    // 1) Referidos table (influencers / individuals)
    $stmt = $pdo->prepare("
        SELECT id, nombre, codigo_referido
        FROM referidos
        WHERE activo = 1 AND UPPER(codigo_referido) = ?
        LIMIT 1
    ");
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode([
            'ok'     => true,
            'tipo'   => 'referido',
            'id'     => (int)$row['id'],
            'nombre' => $row['nombre'],
            'codigo' => $row['codigo_referido'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) Puntos Voltika — only if the column exists on the table
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('codigo_referido', $cols, true)) {
            $stmt = $pdo->prepare("
                SELECT id, slug, nombre, tipo, codigo_referido
                FROM puntos_voltika
                WHERE activo = 1 AND UPPER(codigo_referido) = ?
                LIMIT 1
            ");
            $stmt->execute([$codigo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode([
                    'ok'          => true,
                    'tipo'        => 'punto',
                    'id'          => (int)$row['id'],
                    'nombre'      => $row['nombre'],
                    'punto_slug'  => $row['slug'] ?: ('punto-' . $row['id']),
                    'punto_tipo'  => $row['tipo'] ?: 'entrega',
                    'codigo'      => $row['codigo_referido'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } catch (Throwable $e) {
        error_log('validar-referido puntos lookup: ' . $e->getMessage());
    }

    echo json_encode(['ok' => false, 'error' => 'Código no válido']);

} catch (Throwable $e) {
    error_log('validar-referido error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al validar el código']);
}
