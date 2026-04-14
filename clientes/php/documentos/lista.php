<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

$info = portalComputeAccountState($cid);
$alCorriente = in_array($info['state'], ['account_current','payment_due_soon','payment_due_today']);

$docs = [];

// Contrato
try {
    $stmt = $pdo->prepare("SELECT id, freg FROM firmas_contratos WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $docs[] = ['tipo' => 'contrato', 'titulo' => 'Contrato de compra con facilidades de pago',
                   'subtitulo' => 'Firmado digitalmente', 'disponible' => true, 'fecha' => $r['freg']];
    }
} catch (Throwable $e) {}

// Acta de entrega
try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'actas_entrega'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("SELECT id, freg FROM actas_entrega WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $docs[] = ['tipo' => 'acta_entrega', 'titulo' => 'Acta de entrega',
                       'subtitulo' => 'Confirmada', 'disponible' => true, 'fecha' => $r['freg']];
        }
    }
} catch (Throwable $e) {}

// Comprobantes de pago
$nPagos = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago WHERE cliente_id = ? AND estado IN ('paid_manual','paid_auto')");
    $stmt->execute([$cid]);
    $nPagos = (int)$stmt->fetchColumn();
} catch (Throwable $e) { error_log('documentos/lista ciclos: ' . $e->getMessage()); }
$docs[] = ['tipo' => 'comprobantes', 'titulo' => 'Comprobantes de pago',
           'subtitulo' => "Historial completo ($nPagos pagos)", 'disponible' => $nPagos > 0];

// Pagaré — check if PDF actually exists
$pagareDisponible = false;
$pagareSubtitulo = 'Pendiente de generación';
try {
    $stmt = $pdo->prepare("SELECT ce.pagare_pdf_path, ce.firma_pagare_timestamp, ce.firma_pagare_cincel_id
        FROM checklist_entrega_v2 ce
        JOIN inventario_motos m ON m.id = ce.moto_id
        WHERE m.cliente_id = ? AND ce.pagare_pdf_path IS NOT NULL
        ORDER BY ce.freg DESC LIMIT 1");
    $stmt->execute([$cid]);
    if ($pr = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pagareDisponible = true;
        $pagareSubtitulo = 'Documento firmado digitalmente';
        if ($pr['firma_pagare_cincel_id']) $pagareSubtitulo .= ' — NOM-151';
    }
} catch (Throwable $e) {}
$docs[] = ['tipo' => 'pagare', 'titulo' => 'Pagaré',
           'subtitulo' => $pagareSubtitulo, 'disponible' => $pagareDisponible];

// Carta factura (gated)
$docs[] = [
    'tipo' => 'carta_factura',
    'titulo' => 'Carta factura',
    'subtitulo' => 'Para emplacar tu Voltika',
    'disponible' => $alCorriente,
    'tags' => ['Oficial','Válida para emplacar'],
    'destacado' => true,
];

portalJsonOut(['documentos' => $docs, 'al_corriente' => $alCorriente, 'estado_cuenta' => $info['state']]);
