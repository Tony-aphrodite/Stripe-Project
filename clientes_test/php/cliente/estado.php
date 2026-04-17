<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$pdo = getDB();

// ── Load cliente info ─────────────────────────────────────────────
$cliente = [];
try {
    $stmt = $pdo->prepare("SELECT nombre, apellido_paterno, email, telefono FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('cliente/estado clientes: ' . $e->getMessage()); }

// Auto-fill empty name from inventario_motos or transacciones
if (empty($cliente['nombre'])) {
    $tel = $cliente['telefono'] ?? null;
    $em  = $cliente['email'] ?? null;
    $foundName = null;
    if ($tel) {
        $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_telefono = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$tel]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName && $em) {
        $nStmt = $pdo->prepare("SELECT cliente_nombre FROM inventario_motos WHERE cliente_email = ? AND cliente_nombre IS NOT NULL AND cliente_nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$em]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName && ($tel || $em)) {
        $q = $tel ? "telefono = ?" : "email = ?";
        $nStmt = $pdo->prepare("SELECT nombre FROM transacciones WHERE $q AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$tel ?: $em]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName) {
        $nStmt = $pdo->prepare("SELECT nombre FROM subscripciones_credito WHERE cliente_id = ? AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$cid]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if (!$foundName && $em) {
        $nStmt = $pdo->prepare("SELECT nombre FROM subscripciones_credito WHERE email = ? AND nombre IS NOT NULL AND nombre != '' ORDER BY id DESC LIMIT 1");
        $nStmt->execute([$em]);
        $foundName = ($nStmt->fetchColumn()) ?: null;
    }
    if ($foundName) {
        $pdo->prepare("UPDATE clientes SET nombre = ? WHERE id = ? AND (nombre IS NULL OR nombre = '')")->execute([$foundName, $cid]);
        $cliente['nombre'] = $foundName;
    }
}

$nombre = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido_paterno'] ?? ''));

// ── Optional scope: a specific purchase selected in "Mis compras" ──
// When the client has multiple purchases, the UI sends compra_tipo + compra_id
// so this endpoint returns state for that exact purchase rather than the latest.
$reqTipo = isset($_GET['compra_tipo']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['compra_tipo'])) : '';
$reqId   = isset($_GET['compra_id']) ? (int)$_GET['compra_id'] : 0;

$sub = null; $info = null; $next = null;
$tipoPortal = 'credito';
$compra = null;
$entrega = null;

// Scoped credit: load the requested subscription directly (if it belongs to this client)
if ($reqTipo === 'credito' && $reqId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito
            WHERE id = ? AND (cliente_id = ? OR email = ? OR telefono = ?)
            LIMIT 1");
        $stmt->execute([$reqId, $cid, $cliente['email'] ?? '', $cliente['telefono'] ?? '']);
        $scopedSub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($scopedSub) {
            // Mirror portalComputeAccountState logic for this specific sub
            try { portalEnsureCiclos($scopedSub); } catch (Throwable $e) { error_log('ensureCiclos scoped: ' . $e->getMessage()); }
            $nextRow = null; $tot = 0; $pag = 0;
            try {
                $ns = $pdo->prepare("SELECT * FROM ciclos_pago
                    WHERE subscripcion_id = ? AND estado IN ('pending','overdue')
                    ORDER BY fecha_vencimiento ASC LIMIT 1");
                $ns->execute([$scopedSub['id']]);
                $nextRow = $ns->fetch(PDO::FETCH_ASSOC) ?: null;
                $tot = (int)$pdo->query("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = " . (int)$scopedSub['id'])->fetchColumn();
                $pag = (int)$pdo->query("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = " . (int)$scopedSub['id'] . " AND estado IN ('paid_manual','paid_auto')")->fetchColumn();
            } catch (Throwable $e) { error_log('scoped ciclos: ' . $e->getMessage()); }
            $progreso = $tot > 0 ? round(($pag / $tot) * 100, 1) : 0;
            $state = 'account_current';
            if ($nextRow) {
                $today = strtotime(date('Y-m-d'));
                $venc  = strtotime($nextRow['fecha_vencimiento']);
                $diff  = ($venc - $today) / 86400;
                if ($nextRow['estado'] === 'overdue' || $diff < 0) $state = 'payment_overdue';
                elseif ($diff == 0) $state = 'payment_due_today';
                elseif ($diff <= 2) $state = 'payment_due_soon';
                else $state = 'account_current';
            }
            $info = [
                'state' => $state,
                'subscripcion' => $scopedSub,
                'proximoCiclo' => $nextRow,
                'progreso' => $progreso,
                'total_ciclos' => $tot,
                'ciclos_pagados' => $pag,
            ];
            $sub = $scopedSub;
            $next = $nextRow;
            $tipoPortal = 'credito';
        }
    } catch (Throwable $e) { error_log('scoped credit load: ' . $e->getMessage()); }
}

// Scoped contado/msi: load the requested transaction directly
if (($reqTipo === 'contado' || $reqTipo === 'msi') && $reqId > 0 && !$sub) {
    try {
        $tel10full = preg_replace('/\D/', '', $cliente['telefono'] ?? '');
        if (strlen($tel10full) > 10) $tel10full = substr($tel10full, -10);
        $stmt = $pdo->prepare("SELECT * FROM transacciones
            WHERE id = ?
              AND (
                    email = ?
                 OR (? <> '' AND RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?)
              )
            LIMIT 1");
        $stmt->execute([$reqId, $cliente['email'] ?? '', $tel10full, $tel10full]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($compra) {
            $tipoPortal = $compra['tpago'] === 'msi' ? 'msi' : 'contado';
        }
    } catch (Throwable $e) { error_log('scoped contado load: ' . $e->getMessage()); }
}

// ── Default (no scope): most recent credit subscription, or fall back to latest contado ──
if (!$sub && !$compra) {
    try {
        $info = portalComputeAccountState($cid);
    } catch (Throwable $e) {
        error_log('cliente/estado computeAccountState: ' . $e->getMessage());
        $info = ['state'=>'no_subscription','subscripcion'=>null,'proximoCiclo'=>null,'progreso'=>0,'total_ciclos'=>0,'ciclos_pagados'=>0];
    }
    $sub  = $info['subscripcion'];
    $next = $info['proximoCiclo'];
}

if (!$info) {
    $info = ['state'=>'no_subscription','subscripcion'=>null,'proximoCiclo'=>null,'progreso'=>0,'total_ciclos'=>0,'ciclos_pagados'=>0];
}

// ── Check for contado/MSI purchase if no credit subscription (and not already loaded) ──
if (!$sub && !$compra) {
    $tel10 = preg_replace('/\D/', '', $cliente['telefono'] ?? '');
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    $em = $cliente['email'] ?? null;

    if ($tel10) {
        $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ? AND tpago IN ('contado','msi') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tel10]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$compra && $em) {
        $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? AND tpago IN ('contado','msi') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$em]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($compra) {
        $tipoPortal = $compra['tpago'] === 'msi' ? 'msi' : 'contado';
    }
}

// ── Compute entrega block for any loaded contado/msi compra ──────
if ($compra && ($tipoPortal === 'contado' || $tipoPortal === 'msi')) {
    $tel10 = preg_replace('/\D/', '', $cliente['telefono'] ?? '');
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    {
        // Find linked inventario_motos for delivery tracking
        $moto = null;
        if (!empty($compra['stripe_pi'])) {
            $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE stripe_pi = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$compra['stripe_pi']]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$moto && !empty($compra['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE transaccion_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$compra['id']]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$moto && $tel10) {
            $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE RIGHT(REPLACE(REPLACE(cliente_telefono,'+',''),' ',''),10) = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tel10]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Map inventario_motos.estado → 4-step delivery progress
        $estadoMoto = $moto['estado'] ?? 'por_llegar';
        $pasoEntrega = 1;
        $etiquetaEntrega = 'preparacion';
        switch ($estadoMoto) {
            case 'por_llegar':
            case 'recibida':
            case 'por_ensamblar':
            case 'en_ensamble':
                $pasoEntrega = 1;
                $etiquetaEntrega = 'preparacion';
                break;
            case 'lista_para_entrega':
                $pasoEntrega = 2;
                $etiquetaEntrega = 'asignacion';
                break;
            case 'por_validar_entrega':
                $pasoEntrega = 3;
                $etiquetaEntrega = 'en_transito';
                break;
            case 'entregada':
                $pasoEntrega = 4;
                $etiquetaEntrega = 'listo';
                break;
            default:
                $pasoEntrega = 1;
                $etiquetaEntrega = 'preparacion';
        }

        $entrega = [
            'paso'      => $pasoEntrega,
            'etiqueta'  => $etiquetaEntrega,
            'estado_db' => $estadoMoto,
            'modelo'    => $moto['modelo'] ?? $compra['modelo'] ?? null,
            'color'     => $moto['color'] ?? $compra['color'] ?? null,
            'vin'       => $moto['vin_display'] ?? null,
            'punto'     => [
                'nombre'    => $moto['punto_nombre'] ?? $compra['punto_nombre'] ?? null,
                'direccion' => null,
                'horario'   => null,
            ],
            // Pickup date the point sets when marking lista_para_entrega
            'fecha_recoleccion' => $moto['fecha_entrega_estimada'] ?? null,
            // Skydrop shipment tracking (populated below)
            'envio'     => null,
        ];

        // Pull the latest envio row for this moto — this is where Skydrop's
        // `fecha_estimada_llegada` lives. Clients want to see when the moto
        // will arrive at the punto even before it's been received.
        try {
            $eStmt = $pdo->prepare("SELECT fecha_envio, fecha_estimada_llegada, fecha_recepcion,
                    tracking_number, carrier, estado
                FROM envios WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
            $eStmt->execute([(int)$moto['id']]);
            $env = $eStmt->fetch(PDO::FETCH_ASSOC);
            if ($env) {
                $entrega['envio'] = [
                    'estado'                 => $env['estado'] ?? null,
                    'fecha_envio'            => $env['fecha_envio'] ?? null,
                    'fecha_estimada_llegada' => $env['fecha_estimada_llegada'] ?? null,
                    'fecha_recepcion'        => $env['fecha_recepcion'] ?? null,
                    'tracking_number'        => $env['tracking_number'] ?? null,
                    'carrier'                => $env['carrier'] ?? null,
                ];
            }
        } catch (Throwable $e) { error_log('estado envios: ' . $e->getMessage()); }

        // Try to get punto details from puntos_voltika
        if (!empty($moto['punto_voltika_id'])) {
            try {
                $pStmt = $pdo->prepare("SELECT nombre, direccion, horarios FROM puntos_voltika WHERE id = ?");
                $pStmt->execute([$moto['punto_voltika_id']]);
                $pv = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($pv) {
                    $entrega['punto']['nombre']    = $pv['nombre'] ?: $entrega['punto']['nombre'];
                    $entrega['punto']['direccion']  = $pv['direccion'] ?? null;
                    $entrega['punto']['horario']    = $pv['horarios'] ?? null;
                }
            } catch (Throwable $e) {}
        }
    }
}

if ($nombre === '' && ($sub || $compra)) $nombre = 'Cliente Voltika';

// ── Build response ────────────────────────────────────────────────
$response = [
    'cliente' => [
        'id' => $cid,
        'nombre' => $nombre,
        'nombrePila' => trim(explode(' ', $nombre ?: ($cliente['nombre'] ?? 'Cliente'))[0]) ?: 'Cliente',
        'email' => $cliente['email'] ?? null,
        'telefono' => $cliente['telefono'] ?? null,
    ],
    'tipo_portal' => $tipoPortal,
];

if ($tipoPortal === 'credito') {
    $response['state'] = $info['state'];
    $response['subscripcion'] = $sub ? [
        'id' => (int)$sub['id'],
        'modelo' => $sub['modelo'] ?? null,
        'color' => $sub['color'] ?? null,
        'serie' => $sub['serie'] ?? null,
        'monto_semanal' => (float)($sub['monto_semanal'] ?? 0),
        'plazo_meses' => (int)($sub['plazo_meses'] ?? 0),
        'fecha_entrega' => $sub['fecha_entrega'] ?? null,
    ] : null;
    $response['proximo_pago'] = $next ? [
        'semana_num' => (int)$next['semana_num'],
        'fecha_vencimiento' => $next['fecha_vencimiento'],
        'monto' => (float)$next['monto'],
        'estado' => $next['estado'],
    ] : null;
    $response['progreso'] = [
        'porcentaje' => $info['progreso'],
        'pagados' => $info['ciclos_pagados'],
        'total' => $info['total_ciclos'],
        'restantes' => max(0, $info['total_ciclos'] - $info['ciclos_pagados']),
    ];
} else {
    // Contado / MSI
    $response['state'] = 'compra_confirmada';
    $response['compra'] = [
        'pedido'    => $compra['pedido'] ?? null,
        'modelo'    => $compra['modelo'] ?? null,
        'color'     => $compra['color'] ?? null,
        'total'     => (float)($compra['total'] ?? 0),
        'tpago'     => $compra['tpago'] ?? 'contado',
        'msi_meses' => $compra['msi_meses'] ? (int)$compra['msi_meses'] : null,
        'fecha'     => $compra['freg'] ?? null,
    ];
    $response['entrega'] = $entrega;
}

// ── Load ALL purchases for this client (fix for multi-purchase display) ────
// Client portal was only showing the most recent purchase. This surfaces
// every credit subscription and every contado/msi transaction so the UI
// can offer a picker/list.
try {
    $allPurchases = [];
    $tel10 = preg_replace('/\D/', '', $cliente['telefono'] ?? '');
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    $em = $cliente['email'] ?? null;

    // Credit subscriptions linked by cliente_id
    $subsStmt = $pdo->prepare("SELECT id, modelo, color, monto_semanal, plazo_meses, plazo_semanas,
            fecha_inicio, fecha_entrega, freg, estado
        FROM subscripciones_credito
        WHERE cliente_id = ? ORDER BY id DESC");
    $subsStmt->execute([$cid]);
    foreach ($subsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $allPurchases[] = [
            'tipo'          => 'credito',
            'id'            => (int)$s['id'],
            'modelo'        => $s['modelo'],
            'color'         => $s['color'],
            'pago_semanal'  => (float)($s['monto_semanal'] ?? 0),
            'plazo_meses'   => (int)($s['plazo_meses'] ?? 0),
            'fecha_inicio'  => $s['fecha_inicio'],
            'fecha_entrega' => $s['fecha_entrega'] ?? null,
            'fecha_compra'  => $s['freg'],
            'estado'        => $s['estado'],
        ];
    }

    // Contado / MSI transactions matched by telefono or email
    if ($tel10 || $em) {
        $where = [];
        $params = [];
        if ($tel10) { $where[] = "RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?"; $params[] = $tel10; }
        if ($em)    { $where[] = "email = ?"; $params[] = $em; }
        $sql = "SELECT id, pedido, modelo, color, total, tpago, msi_meses, freg, pago_estado
                FROM transacciones
                WHERE (" . implode(' OR ', $where) . ")
                  AND tpago IN ('contado','msi','unico')
                ORDER BY id DESC";
        $tStmt = $pdo->prepare($sql);
        $tStmt->execute($params);
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $allPurchases[] = [
                'tipo'         => $t['tpago'] === 'msi' ? 'msi' : 'contado',
                'id'           => (int)$t['id'],
                'pedido'       => $t['pedido'],
                'modelo'       => $t['modelo'],
                'color'        => $t['color'],
                'total'        => (float)($t['total'] ?? 0),
                'msi_meses'    => $t['msi_meses'] ? (int)$t['msi_meses'] : null,
                'fecha_compra' => $t['freg'],
                'pago_estado'  => $t['pago_estado'],
            ];
        }
    }
    $response['compras'] = $allPurchases;
    $response['total_compras'] = count($allPurchases);
} catch (Throwable $e) {
    error_log('cliente/estado compras list: ' . $e->getMessage());
    $response['compras'] = [];
    $response['total_compras'] = 0;
}

portalJsonOut($response);
