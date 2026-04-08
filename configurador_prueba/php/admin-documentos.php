<?php
/**
 * Voltika Admin - Document Hub per VIN/Moto
 * GET ?moto_id=N → { ok, moto, documentos[] }
 *
 * Returns all linked documents for a motorcycle:
 * - Checklist de Origen (completado?)
 * - Checklist de Ensamble (completado?)
 * - Checklist de Entrega v2 (completado?)
 * - Acta de entrega PDF
 * - Contrato de crédito (if exists)
 * - Verificación de identidad (Truora)
 * - Cincel NOM-151 certificate (if exists)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';
requireDealerAuth(true);

$motoId = intval($_GET['moto_id'] ?? 0);
if (!$motoId) {
    echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
    exit;
}

$pdo = getDB();

// 1) Moto data
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']);
    exit;
}

$docs = [];

// 2) Checklist de Origen
try {
    $s = $pdo->prepare("SELECT id, completado, bloqueado, hash_registro, freg FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$motoId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $docs[] = [
        'tipo'       => 'checklist_origen',
        'titulo'     => 'Checklist de Origen',
        'icon'       => '📦',
        'existe'     => (bool)$r,
        'completado' => $r ? (bool)$r['completado'] : false,
        'hash'       => $r['hash_registro'] ?? null,
        'fecha'      => $r['freg'] ?? null,
        'url'        => 'checklist.html?moto_id=' . $motoId . '&type=origen',
    ];
} catch (PDOException $e) {}

// 3) Checklist de Ensamble
try {
    $s = $pdo->prepare("SELECT id, completado, bloqueado, fase_actual, freg FROM checklist_ensamble WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$motoId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $docs[] = [
        'tipo'       => 'checklist_ensamble',
        'titulo'     => 'Checklist de Ensamble',
        'icon'       => '⚙️',
        'existe'     => (bool)$r,
        'completado' => $r ? (bool)$r['completado'] : false,
        'fase'       => $r['fase_actual'] ?? null,
        'fecha'      => $r['freg'] ?? null,
        'url'        => 'checklist.html?moto_id=' . $motoId . '&type=ensamble',
    ];
} catch (PDOException $e) {}

// 4) Checklist de Entrega v2
try {
    $s = $pdo->prepare("SELECT id, completado, bloqueado, fase_actual, freg, pdf_acta_url FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$motoId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $docs[] = [
        'tipo'       => 'checklist_entrega',
        'titulo'     => 'Checklist de Entrega',
        'icon'       => '🏍️',
        'existe'     => (bool)$r,
        'completado' => $r ? (bool)$r['completado'] : false,
        'fase'       => $r['fase_actual'] ?? null,
        'fecha'      => $r['freg'] ?? null,
        'url'        => 'checklist.html?moto_id=' . $motoId . '&type=entrega',
    ];

    // Acta de entrega PDF
    if ($r && $r['completado']) {
        $docs[] = [
            'tipo'       => 'acta_entrega',
            'titulo'     => 'Acta de Entrega (PDF)',
            'icon'       => '📜',
            'existe'     => true,
            'completado' => true,
            'fecha'      => $r['freg'] ?? null,
            'url'        => 'php/admin-generar-acta-pdf.php?moto_id=' . $motoId . '&key=voltika_acta_2026',
            'target'     => '_blank',
        ];
    }
} catch (PDOException $e) {}

// 5) Verificación de identidad (Truora)
try {
    $conditions = [];
    $params = [];
    if (!empty($moto['cliente_email'])) { $conditions[] = "email = ?"; $params[] = $moto['cliente_email']; }
    if (!empty($moto['cliente_telefono'])) { $conditions[] = "telefono = ?"; $params[] = $moto['cliente_telefono']; }

    if (!empty($conditions)) {
        $s = $pdo->prepare("SELECT id, approved, truora_score, identity_status, files_saved, freg FROM verificaciones_identidad WHERE (" . implode(' OR ', $conditions) . ") ORDER BY freg DESC LIMIT 1");
        $s->execute($params);
        $r = $s->fetch(PDO::FETCH_ASSOC);

        if ($r) {
            $files = json_decode($r['files_saved'], true) ?: [];
            $selfie = null;
            $ine = null;
            foreach ($files as $f) {
                if (strpos($f, '_selfie') !== false) $selfie = 'php/uploads/' . $f;
                if (strpos($f, '_ine_frente') !== false) $ine = 'php/uploads/' . $f;
            }

            $docs[] = [
                'tipo'       => 'verificacion_identidad',
                'titulo'     => 'Verificación de Identidad (Truora)',
                'icon'       => '📸',
                'existe'     => true,
                'completado' => (bool)$r['approved'],
                'score'      => $r['truora_score'],
                'status'     => $r['identity_status'],
                'fecha'      => $r['freg'],
                'selfie'     => $selfie,
                'ine'        => $ine,
            ];
        }
    }
} catch (PDOException $e) {}

// 6) Contrato de crédito (buscar en transacciones por pedido_num)
try {
    if (!empty($moto['pedido_num'])) {
        $pedidoClean = preg_replace('/^VK-/', '', $moto['pedido_num']);
        $s = $pdo->prepare("SELECT id, tpago, total, freg, stripe_pi FROM transacciones WHERE pedido = ? OR pedido = ? LIMIT 1");
        $s->execute([$pedidoClean, $moto['pedido_num']]);
        $r = $s->fetch(PDO::FETCH_ASSOC);

        if ($r) {
            $docs[] = [
                'tipo'       => 'transaccion',
                'titulo'     => 'Transacción / Pago',
                'icon'       => '💳',
                'existe'     => true,
                'completado' => true,
                'metodo'     => $r['tpago'],
                'total'      => $r['total'],
                'stripe_pi'  => $r['stripe_pi'],
                'fecha'      => $r['freg'],
            ];
        }
    }
} catch (PDOException $e) {}

echo json_encode([
    'ok'   => true,
    'moto' => [
        'id'              => $moto['id'],
        'vin'             => $moto['vin'],
        'vin_display'     => $moto['vin_display'],
        'modelo'          => $moto['modelo'],
        'color'           => $moto['color'],
        'estado'          => $moto['estado'],
        'cliente_nombre'  => $moto['cliente_nombre'],
        'cliente_email'   => $moto['cliente_email'],
        'pedido_num'      => $moto['pedido_num'],
        'punto_nombre'    => $moto['punto_nombre'],
    ],
    'documentos' => $docs,
]);
