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

// Contrato — credit only. Always present in the list so the user sees the
// 6-doc layout consistently; disponible=true once a firmas_contratos row
// exists (signed digitally).
$contratoDisponible = false;
$contratoSub = 'Pendiente de firma';
$contratoFecha = null;
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
        $contratoDisponible = true;
        $contratoSub        = 'Firmado digitalmente';
        $contratoFecha      = $r['freg'];
    }
} catch (Throwable $e) {}
if (!$scopedTxnId) {
    $docs[] = ['tipo' => 'contrato',
               'titulo' => 'Contrato de compra con facilidades de pago',
               'subtitulo' => $contratoSub,
               'disponible' => $contratoDisponible,
               'fecha' => $contratoFecha];
}

// Acta de entrega — always shown; flips to disponible=true once an actas_entrega
// row (or a completed checklist_entrega_v2) exists for this client.
$actaDisponible = false;
$actaSub = 'Pendiente de entrega';
$actaFecha = null;
try {
    // Prefer actas_entrega table if present; fall back to checklist_entrega_v2.
    $hasActasTbl = (bool)$pdo->query("SHOW TABLES LIKE 'actas_entrega'")->fetch();
    if ($hasActasTbl) {
        if ($scopedTxnId > 0) {
            $stmt = $pdo->prepare("SELECT a.id, a.freg FROM actas_entrega a
                JOIN inventario_motos m ON m.id = a.moto_id
                WHERE a.cliente_id = ? AND m.transaccion_id = ?
                ORDER BY a.id DESC LIMIT 1");
            $stmt->execute([$cid, $scopedTxnId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, freg FROM actas_entrega WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cid]);
        }
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $actaDisponible = true; $actaSub = 'Confirmada'; $actaFecha = $r['freg'];
        }
    }
    if (!$actaDisponible) {
        // Fallback: completed delivery checklist
        $stmt = $pdo->prepare("SELECT ce.freg FROM checklist_entrega_v2 ce
            JOIN inventario_motos m ON m.id = ce.moto_id
            WHERE (m.cliente_id = ? OR m.cliente_telefono = ?) AND ce.completado = 1
            ORDER BY ce.freg DESC LIMIT 1");
        $tel = $cliente['telefono'] ?? '';
        // $cliente var defined in descargar.php only — fall back to a 2nd query
        if (!isset($cliente)) {
            $tel2 = $pdo->prepare("SELECT telefono FROM clientes WHERE id = ?");
            $tel2->execute([$cid]);
            $tel = (string)($tel2->fetchColumn() ?: '');
        }
        $stmt->execute([$cid, $tel]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $actaDisponible = true; $actaSub = 'Confirmada'; $actaFecha = $r['freg'];
        }
    }
} catch (Throwable $e) {}
if (!$scopedTxnId) {
    $docs[] = ['tipo' => 'acta_entrega', 'titulo' => 'Acta de entrega',
               'subtitulo' => $actaSub, 'disponible' => $actaDisponible, 'fecha' => $actaFecha];
}

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

// Pagaré — REMOVED from customer-facing list per brief 2026-04-19. The signed
// pagaré PDF still lives in checklist_entrega_v2.pagare_pdf_path for legal
// audit; descargar.php?tipo=pagare can still serve it if needed internally.

// Manual de usuario — always available once a moto is linked (modelo known).
$modeloForManual = '';
try {
    if ($scopedTxnId > 0) {
        $stmt = $pdo->prepare("SELECT modelo FROM transacciones WHERE id = ?");
        $stmt->execute([$scopedTxnId]);
        $modeloForManual = (string)($stmt->fetchColumn() ?: '');
    } elseif ($scopedSubId > 0) {
        $stmt = $pdo->prepare("SELECT modelo FROM subscripciones_credito WHERE id = ?");
        $stmt->execute([$scopedSubId]);
        $modeloForManual = (string)($stmt->fetchColumn() ?: '');
    } else {
        $stmt = $pdo->prepare("SELECT modelo FROM inventario_motos WHERE cliente_id = ? AND activo = 1 ORDER BY fmod DESC LIMIT 1");
        $stmt->execute([$cid]);
        $modeloForManual = (string)($stmt->fetchColumn() ?: '');
    }
} catch (Throwable $e) {}
$docs[] = [
    'tipo'       => 'manual',
    'titulo'     => 'Manual del usuario' . ($modeloForManual ? ' — ' . $modeloForManual : ''),
    'subtitulo'  => 'Guía digital de tu Voltika',
    'disponible' => $modeloForManual !== '',
];

// Seguros — cotización + póliza uploaded by admin via cotizacion endpoint.
// The file lives at transacciones.seguro_cotizacion_archivo (relative path).
$seguroDisponible = false;
$seguroSub = 'Cotización y póliza';
try {
    $needCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'transacciones'
                                 AND COLUMN_NAME = 'seguro_cotizacion_archivo'");
    $needCol->execute();
    if ((int)$needCol->fetchColumn() > 0) {
        if ($scopedTxnId > 0) {
            $stmt = $pdo->prepare("SELECT seguro_cotizacion_archivo, seguro_cotizacion_subido FROM transacciones
                                    WHERE id = ? AND seguro_cotizacion_archivo IS NOT NULL LIMIT 1");
            $stmt->execute([$scopedTxnId]);
        } else {
            // Find any tx for this client that has a seguro file
            $stmt = $pdo->prepare("SELECT t.seguro_cotizacion_archivo, t.seguro_cotizacion_subido
                                     FROM transacciones t
                                LEFT JOIN clientes c ON c.id = ?
                                    WHERE (t.email = c.email OR t.telefono = c.telefono)
                                      AND t.seguro_cotizacion_archivo IS NOT NULL
                                 ORDER BY t.id DESC LIMIT 1");
            $stmt->execute([$cid]);
        }
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $seguroDisponible = true;
            $seguroSub = 'Subido el ' . substr($r['seguro_cotizacion_subido'] ?? '', 0, 10);
        }
    }
} catch (Throwable $e) { error_log('documentos/lista seguro: ' . $e->getMessage()); }
$docs[] = [
    'tipo'       => 'seguro',
    'titulo'     => 'Seguros',
    'subtitulo'  => $seguroDisponible ? $seguroSub : 'Pendiente de cotización',
    'disponible' => $seguroDisponible,
];

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
