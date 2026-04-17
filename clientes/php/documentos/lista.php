<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

// Optional scope: filter documents to a specific purchase
$reqTipo = isset($_GET['compra_tipo']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['compra_tipo'])) : '';
$reqId   = isset($_GET['compra_id']) ? (int)$_GET['compra_id'] : 0;

// For credit subscriptions we may want to scope payment counts/contract to a specific sub.
// For contado/msi purchases, the only docs are Carta factura + acta + comprobante_pago (stripe receipt).
$scopedSubId = ($reqTipo === 'credito' && $reqId > 0) ? $reqId : 0;
$scopedTxnId = (($reqTipo === 'contado' || $reqTipo === 'msi') && $reqId > 0) ? $reqId : 0;

$info = portalComputeAccountState($cid);
$alCorriente = in_array($info['state'], ['account_current','payment_due_soon','payment_due_today']);

$docs = [];

// Contrato — gated to credit purchases only; scoped by subscripcion_id if provided
try {
    if ($scopedSubId > 0) {
        $stmt = $pdo->prepare("SELECT id, freg FROM firmas_contratos WHERE cliente_id = ? AND subscripcion_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid, $scopedSubId]);
    } elseif (!$scopedTxnId) {
        $stmt = $pdo->prepare("SELECT id, freg FROM firmas_contratos WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
    } else {
        $stmt = null;
    }
    if ($stmt && $r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $docs[] = ['tipo' => 'contrato', 'titulo' => 'Contrato de compra con facilidades de pago',
                   'subtitulo' => 'Firmado digitalmente', 'disponible' => true, 'fecha' => $r['freg']];
    }
} catch (Throwable $e) {}

// Acta de entrega — scoped by moto if scope given
try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'actas_entrega'");
    $stmt->execute();
    if ($stmt->fetch()) {
        if ($scopedTxnId > 0) {
            $stmt = $pdo->prepare("SELECT a.id, a.freg FROM actas_entrega a
                JOIN inventario_motos m ON m.id = a.moto_id
                WHERE a.cliente_id = ? AND m.transaccion_id = ?
                ORDER BY a.id DESC LIMIT 1");
            $stmt->execute([$cid, $scopedTxnId]);
        } elseif ($scopedSubId > 0) {
            // Credit: acta for the moto tied to this sub's contact
            $stmt = $pdo->prepare("SELECT a.id, a.freg FROM actas_entrega a
                JOIN inventario_motos m ON m.id = a.moto_id
                WHERE a.cliente_id = ?
                ORDER BY a.id DESC LIMIT 1");
            $stmt->execute([$cid]);
        } else {
            $stmt = $pdo->prepare("SELECT id, freg FROM actas_entrega WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cid]);
        }
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $docs[] = ['tipo' => 'acta_entrega', 'titulo' => 'Acta de entrega',
                       'subtitulo' => 'Confirmada', 'disponible' => true, 'fecha' => $r['freg']];
        }
    }
} catch (Throwable $e) {}

// Comprobantes de pago — credit only
$nPagos = 0;
try {
    if ($scopedSubId > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = ? AND estado IN ('paid_manual','paid_auto')");
        $stmt->execute([$scopedSubId]);
        $nPagos = (int)$stmt->fetchColumn();
    } elseif (!$scopedTxnId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago WHERE cliente_id = ? AND estado IN ('paid_manual','paid_auto')");
        $stmt->execute([$cid]);
        $nPagos = (int)$stmt->fetchColumn();
    }
} catch (Throwable $e) { error_log('documentos/lista ciclos: ' . $e->getMessage()); }
if (!$scopedTxnId) {
    $docs[] = ['tipo' => 'comprobantes', 'titulo' => 'Comprobantes de pago',
               'subtitulo' => "Historial completo ($nPagos pagos)", 'disponible' => $nPagos > 0];
}

// Comprobante de pago Stripe (for contado/msi scoped purchase)
if ($scopedTxnId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, total, freg, stripe_pi FROM transacciones WHERE id = ? LIMIT 1");
        $stmt->execute([$scopedTxnId]);
        if ($tr = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $docs[] = [
                'tipo'       => 'comprobante_contado',
                'titulo'     => 'Comprobante de compra',
                'subtitulo'  => 'Recibo de pago Stripe',
                'disponible' => !empty($tr['stripe_pi']),
                'fecha'      => $tr['freg'],
            ];
        }
    } catch (Throwable $e) {}
}

// Pagaré — credit only
$pagareDisponible = false;
$pagareSubtitulo = 'Pendiente de generación';
try {
    if ($scopedSubId > 0) {
        $stmt = $pdo->prepare("SELECT ce.pagare_pdf_path, ce.firma_pagare_timestamp, ce.firma_pagare_cincel_id
            FROM checklist_entrega_v2 ce
            JOIN inventario_motos m ON m.id = ce.moto_id
            JOIN subscripciones_credito s ON (s.telefono = m.cliente_telefono OR s.email = m.cliente_email)
            WHERE m.cliente_id = ? AND s.id = ? AND ce.pagare_pdf_path IS NOT NULL
            ORDER BY ce.freg DESC LIMIT 1");
        $stmt->execute([$cid, $scopedSubId]);
    } elseif (!$scopedTxnId) {
        $stmt = $pdo->prepare("SELECT ce.pagare_pdf_path, ce.firma_pagare_timestamp, ce.firma_pagare_cincel_id
            FROM checklist_entrega_v2 ce
            JOIN inventario_motos m ON m.id = ce.moto_id
            WHERE m.cliente_id = ? AND ce.pagare_pdf_path IS NOT NULL
            ORDER BY ce.freg DESC LIMIT 1");
        $stmt->execute([$cid]);
    } else {
        $stmt = null;
    }
    if ($stmt && $pr = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pagareDisponible = true;
        $pagareSubtitulo = 'Documento firmado digitalmente';
        if ($pr['firma_pagare_cincel_id']) $pagareSubtitulo .= ' — NOM-151';
    }
} catch (Throwable $e) {}
if (!$scopedTxnId) {
    $docs[] = ['tipo' => 'pagare', 'titulo' => 'Pagaré',
               'subtitulo' => $pagareSubtitulo, 'disponible' => $pagareDisponible];
}

// Carta factura — always available once the purchase is al corriente / liquidada
$cartaDisponible = $scopedTxnId > 0 ? true : $alCorriente;
$docs[] = [
    'tipo' => 'carta_factura',
    'titulo' => 'Carta factura',
    'subtitulo' => 'Para emplacar tu Voltika',
    'disponible' => $cartaDisponible,
    'tags' => ['Oficial','Válida para emplacar'],
    'destacado' => true,
];

portalJsonOut(['documentos' => $docs, 'al_corriente' => $alCorriente, 'estado_cuenta' => $info['state']]);
