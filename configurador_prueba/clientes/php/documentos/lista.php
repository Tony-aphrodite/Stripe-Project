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
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago WHERE cliente_id = ? AND estado IN ('paid_manual','paid_auto')");
$stmt->execute([$cid]);
$nPagos = (int)$stmt->fetchColumn();
$docs[] = ['tipo' => 'comprobantes', 'titulo' => 'Comprobantes de pago',
           'subtitulo' => "Historial completo ($nPagos pagos)", 'disponible' => $nPagos > 0];

// Pagaré
$docs[] = ['tipo' => 'pagare', 'titulo' => 'Pagaré',
           'subtitulo' => 'Documento de tu operación', 'disponible' => true];

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
