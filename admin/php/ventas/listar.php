<?php
/**
 * GET — List purchases with bike assignment status
 * Returns orders from transacciones + subscripciones_credito
 * with info about whether a bike is assigned or not.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

$rows = [];

// ── Orders from transacciones ───────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre, t.ciudad, t.estado, t.cp, t.folio_contrato,
               t.fecha_estimada_entrega,
               t.pago_estado AS tx_pago_estado,
               m.id AS moto_id, m.vin_display AS moto_vin, m.estado AS moto_estado,
               m.pago_estado
        FROM transacciones t
        LEFT JOIN inventario_motos m
               ON m.pedido_num = CONCAT('VK-', t.pedido)
              AND m.activo = 1
              AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
        ORDER BY t.freg DESC
        LIMIT 100
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => $r['pedido'],
            'nombre'      => $r['nombre'],
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => $r['tpago'],
            'monto'       => (float)$r['total'],
            'stripe_pi'   => $r['stripe_pi'],
            'fecha'       => $r['freg'],
            'moto_id'     => $r['moto_id'] ? (int)$r['moto_id'] : null,
            'moto_vin'    => $r['moto_vin'],
            'moto_estado' => $r['moto_estado'],
            'pago_estado' => $r['pago_estado']
                ?: ($r['tx_pago_estado'] ?? '')
                ?: (
                    !empty($r['stripe_pi'])
                        ? (in_array(strtolower(trim($r['tpago'] ?? '')), ['credito', 'enganche', 'parcial', 'spei', 'oxxo'], true)
                            ? (in_array(strtolower(trim($r['tpago'] ?? '')), ['spei', 'oxxo'], true) ? 'pendiente' : 'parcial')
                            : 'pagada')
                        : 'pendiente'
                ),
            'punto_id'    => $r['punto_id'] ?? null,
            'punto_nombre'=> $r['punto_nombre'] ?? null,
            'ciudad'      => $r['ciudad'] ?? null,
            'estado'      => $r['estado'] ?? null,
            'cp'          => $r['cp'] ?? null,
            'folio_contrato' => $r['folio_contrato'] ?? null,
            'fecha_estimada_entrega' => $r['fecha_estimada_entrega'] ?? null,
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/listar transacciones: ' . $e->getMessage());
}

// ── Enrich enganche/credito rows with subscripciones_credito data ───────
try {
    foreach ($rows as &$row) {
        if (!in_array($row['tipo'], ['enganche', 'credito'], true)) continue;
        $tel = $row['telefono'] ?? '';
        $em  = $row['email'] ?? '';
        $ped = $row['pedido'] ?? '';

        $where = [];
        $params = [];
        if ($tel) { $where[] = 's.telefono = ?'; $params[] = $tel; }
        if ($em)  { $where[] = 's.email = ?';    $params[] = $em; }
        if ($ped) { $where[] = 's.id = ?';       $params[] = preg_replace('/^SC-/', '', $ped); }

        if (!$where) continue;

        $sql = "SELECT s.monto_semanal, s.plazo_semanas, s.plazo_meses,
                       s.precio_contado, s.nombre, s.email, s.telefono,
                       s.modelo AS sc_modelo, s.color AS sc_color
                FROM subscripciones_credito s
                WHERE (" . implode(' OR ', $where) . ")
                ORDER BY s.id DESC LIMIT 1";
        $sc = $pdo->prepare($sql);
        $sc->execute($params);
        $cr = $sc->fetch(PDO::FETCH_ASSOC);
        if ($cr) {
            $precioContado = (float)($cr['precio_contado'] ?? 0);
            $enganche      = $row['monto']; // transacciones.total = enganche paid
            $financiado    = $precioContado > 0 ? $precioContado - $enganche : 0;

            $row['credito'] = [
                'enganche'         => $enganche,
                'monto_semanal'    => (float)($cr['monto_semanal'] ?? 0),
                'plazo_semanas'    => (int)($cr['plazo_semanas'] ?? 0),
                'plazo_meses'      => (int)($cr['plazo_meses'] ?? 0),
                'precio_contado'   => $precioContado,
                'monto_financiado' => $financiado,
            ];
            // Backfill empty client info from subscripciones_credito
            if (empty($row['nombre']) && !empty($cr['nombre']))       $row['nombre']   = $cr['nombre'];
            if (empty($row['email']) && !empty($cr['email']))         $row['email']    = $cr['email'];
            if (empty($row['telefono']) && !empty($cr['telefono']))   $row['telefono'] = $cr['telefono'];
            if (empty($row['modelo']) && !empty($cr['sc_modelo']))    $row['modelo']   = $cr['sc_modelo'];
            if (empty($row['color']) && !empty($cr['sc_color']))      $row['color']    = $cr['sc_color'];
        }
    }
    unset($row);
} catch (Throwable $e) {
    error_log('ventas/listar credito enrich: ' . $e->getMessage());
}

// ── Credit subscriptions that have NO matching transacciones row ────────
// These are orphans: customer reached the autopago step (SetupIntent saved to
// subscripciones_credito) but either (a) the transacciones INSERT in
// confirmar-orden.php silently failed, or (b) they never paid the enganche
// through Stripe PaymentIntent. Either way the admin must see them so no
// sale falls through the cracks. Match by telefono (most reliable — both
// tables have it, and enganche+contract+autopago share the phone).
try {
    // Orphan detection: a subscripciones_credito row is an orphan when NO
    // transacciones row exists for the same customer. We match by telefono
    // OR email (whichever is available) to tolerate NULL/empty fields.
    // The previous version required modelo to also match, but modelo is
    // often NULL/"" on legacy rows, and SQL's `NULL = NULL` is false, so
    // recovered rows were still appearing as orphans.
    $stmt = $pdo->query("
        SELECT s.id, s.cliente_id, s.telefono, s.email, s.modelo, s.color,
               s.precio_contado, s.monto_semanal, s.plazo_semanas,
               s.stripe_customer_id, s.freg, s.estado,
               c.nombre AS cliente_nombre
        FROM subscripciones_credito s
        LEFT JOIN clientes c ON c.id = s.cliente_id
        WHERE NOT EXISTS (
            SELECT 1 FROM transacciones t
            WHERE (t.telefono <> '' AND t.telefono = s.telefono)
               OR (t.email    <> '' AND t.email    = s.email)
               OR (t.pedido   =  CONCAT('SC-', s.id))
        )
        ORDER BY s.freg DESC
        LIMIT 100
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => 'SC-' . $r['id'],
            'nombre'      => $r['cliente_nombre'] ?: ('Cliente #' . ($r['cliente_id'] ?: 's/n')),
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => 'credito-orfano',
            'monto'       => (float)($r['precio_contado'] ?? 0),
            'stripe_pi'   => $r['stripe_customer_id'] ?? '',
            'fecha'       => $r['freg'],
            'moto_id'     => null,
            'moto_vin'    => null,
            'moto_estado' => null,
            'pago_estado' => 'orfano',
            'punto_id'    => null,
            'punto_nombre'=> null,
            'source'      => 'subscripciones_credito',
            'alerta'      => 'Suscripción de crédito sin transacción de enganche — revisar',
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/listar subscripciones_credito: ' . $e->getMessage());
}

// ── Orders captured in transacciones_errores (Plan B recovery table) ────
// confirmar-orden.php writes here when the main INSERT into transacciones
// fails. The admin needs to see these so the sale can be recovered manually.
try {
    // Only show errors that HAVE NOT been recovered yet. After recuperar-lote
    // or recuperar-orden promotes an error into transacciones, recuperado_tx_id
    // is set (to the new tx id, or -1 if skipped as duplicate). Both cases
    // should disappear from the dashboard.
    $stmt = $pdo->query("
        SELECT id, nombre, email, telefono, modelo, color, total,
               stripe_pi, error_msg, freg
        FROM transacciones_errores
        WHERE recuperado_tx_id IS NULL
        ORDER BY freg DESC
        LIMIT 100
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => 'ERR-' . $r['id'],
            'nombre'      => $r['nombre'],
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => 'error-captura',
            'monto'       => (float)$r['total'],
            'stripe_pi'   => $r['stripe_pi'],
            'fecha'       => $r['freg'],
            'moto_id'     => null,
            'moto_vin'    => null,
            'moto_estado' => null,
            'pago_estado' => 'error',
            'punto_id'    => null,
            'punto_nombre'=> null,
            'source'      => 'transacciones_errores',
            'alerta'      => 'Error al guardar la orden: ' . ($r['error_msg'] ?? 'desconocido'),
        ];
    }
} catch (Throwable $e) {
    // Table may not exist yet — fine, Plan B creates it on first error.
}

// ── Backfill empty fields from Stripe PaymentIntent metadata ────────────
// Skip by default for faster loading. Only run when explicitly requested
// via ?backfill=1 or when there are error/orphan rows that need enrichment.
$doBackfill = !empty($_GET['backfill']);
try {
    $needsBackfill = $doBackfill ? array_filter($rows, function($r) {
        return !empty($r['stripe_pi'])
            && str_starts_with($r['stripe_pi'], 'pi_')
            && (empty($r['nombre']) || empty($r['telefono']) || empty($r['modelo']));
    }) : [];
    if ($needsBackfill) {
        $stripePath = __DIR__ . '/../../../configurador_prueba/php/vendor/autoload.php';
        if (file_exists($stripePath)) {
            require_once $stripePath;
            require_once __DIR__ . '/../../../configurador_prueba/php/config.php';
            if (defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
                \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                foreach ($rows as &$row) {
                    if (empty($row['stripe_pi']) || !str_starts_with($row['stripe_pi'], 'pi_')) continue;
                    if (!empty($row['nombre']) && !empty($row['telefono']) && !empty($row['modelo'])) continue;
                    try {
                        $pi = \Stripe\PaymentIntent::retrieve($row['stripe_pi']);
                        $meta = $pi->metadata ? $pi->metadata->toArray() : [];
                        $custName = '';
                        $custPhone = '';
                        $custEmail = '';
                        if ($pi->customer) {
                            try {
                                $cust = \Stripe\Customer::retrieve($pi->customer);
                                $custName = $cust->name ?? '';
                                $custPhone = $cust->phone ?? '';
                                $custEmail = $cust->email ?? '';
                            } catch (Throwable $e2) {}
                        }
                        if (empty($row['nombre']) && ($meta['nombre'] ?? $custName))
                            $row['nombre'] = $meta['nombre'] ?: $custName;
                        if (empty($row['email']) && $custEmail)
                            $row['email'] = $custEmail;
                        if (empty($row['telefono']) && ($meta['telefono'] ?? $custPhone))
                            $row['telefono'] = $meta['telefono'] ?: $custPhone;
                        if (empty($row['modelo']) && !empty($meta['modelo']))
                            $row['modelo'] = $meta['modelo'];
                        if (empty($row['color']) && !empty($meta['color']))
                            $row['color'] = $meta['color'];
                        // Parse modelo from PI description (format: "Voltika - M05")
                        if (empty($row['modelo']) && !empty($pi->description)) {
                            $desc = $pi->description;
                            if (preg_match('/Voltika\s*-\s*(.+)/i', $desc, $dm)) {
                                $parsed = trim($dm[1]);
                                // Remove suffixes like "OXXO 1/2"
                                $parsed = preg_replace('/\s*(OXXO|SPEI)\s*\d*\/?\d*$/i', '', $parsed);
                                $parsed = trim($parsed);
                                if ($parsed) $row['modelo'] = $parsed;
                            }
                        }
                    } catch (Throwable $e) {
                        // Stripe retrieval failed — skip silently
                    }
                }
                unset($row);
            }
        }
    }
} catch (Throwable $e) {
    error_log('ventas/listar stripe backfill: ' . $e->getMessage());
}

// ── Second pass: backfill modelo/color from subscripciones_credito ───────
// After Stripe backfill, telefono/email are now available for matching.
try {
    foreach ($rows as &$row) {
        if (!in_array($row['tipo'] ?? '', ['enganche', 'credito'], true)) continue;
        if (!empty($row['modelo']) && !empty($row['color'])) continue;
        $tel = $row['telefono'] ?? '';
        $em  = $row['email'] ?? '';
        $where2 = [];
        $params2 = [];
        if ($tel) { $where2[] = 's.telefono = ?'; $params2[] = $tel; }
        if ($em)  { $where2[] = 's.email = ?';    $params2[] = $em; }
        if (!$where2) continue;
        $sc2 = $pdo->prepare("SELECT s.modelo, s.color FROM subscripciones_credito s WHERE (" . implode(' OR ', $where2) . ") AND (s.modelo IS NOT NULL AND s.modelo <> '') ORDER BY s.id DESC LIMIT 1");
        $sc2->execute($params2);
        $cr2 = $sc2->fetch(PDO::FETCH_ASSOC);
        if ($cr2) {
            if (empty($row['modelo']) && !empty($cr2['modelo'])) $row['modelo'] = $cr2['modelo'];
            if (empty($row['color']) && !empty($cr2['color']))   $row['color']  = $cr2['color'];
        }
    }
    unset($row);
} catch (Throwable $e) {
    error_log('ventas/listar sc backfill pass2: ' . $e->getMessage());
}

// Sort combined rows by fecha desc
usort($rows, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));

// ── Inventory availability per modelo+color ──────────────────────────────
// Used by the dashboard to show "X disponibles" or "Sin inventario — 2 meses"
$disponibles = [];
try {
    $inv = $pdo->query("
        SELECT modelo, color, COUNT(*) AS cnt
        FROM inventario_motos
        WHERE activo = 1
          AND (pedido_num IS NULL OR pedido_num = '')
          AND (cliente_email IS NULL OR cliente_email = '')
          AND vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
          AND estado IN ('recibida','lista_para_entrega')
          AND (punto_voltika_id IS NULL OR punto_voltika_id = 0)
        GROUP BY modelo, color
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inv as $i) {
        $key = strtolower(trim($i['modelo'])) . '|' . strtolower(trim($i['color']));
        $disponibles[$key] = (int)$i['cnt'];
    }
} catch (Throwable $e) {}

// In-transit count per modelo+color (por_llegar)
$enTransito = [];
try {
    $inv2 = $pdo->query("
        SELECT modelo, color, COUNT(*) AS cnt
        FROM inventario_motos
        WHERE activo = 1 AND estado = 'por_llegar'
        GROUP BY modelo, color
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inv2 as $i) {
        $key = strtolower(trim($i['modelo'])) . '|' . strtolower(trim($i['color']));
        $enTransito[$key] = (int)$i['cnt'];
    }
} catch (Throwable $e) {}

$twoMonths = date('Y-m-d', strtotime('+2 months'));
foreach ($rows as &$row) {
    $key = strtolower(trim($row['modelo'] ?? '')) . '|' . strtolower(trim($row['color'] ?? ''));
    $stock = $disponibles[$key] ?? 0;
    $row['inventario_disponible'] = $stock;
    $row['inventario_en_transito'] = $enTransito[$key] ?? 0;
    if (!$row['moto_id'] && $stock === 0) {
        $row['fecha_estimada_entrega'] = $row['fecha_estimada_entrega'] ?? $twoMonths;
    }
}
unset($row);

// ── Counts ───────────────────────────────────────────────────────────────
$total      = count($rows);
$asignadas  = count(array_filter($rows, fn($r) => $r['moto_id'] !== null));
$sinAsignar = $total - $asignadas;
$orfanos    = count(array_filter($rows, fn($r) => ($r['source'] ?? '') !== ''));
$conPago    = count(array_filter($rows, fn($r) => ($r['pago_estado'] ?? '') === 'pagada'));
$sinPago    = count(array_filter($rows, fn($r) => in_array($r['pago_estado'] ?? '', ['pendiente', 'parcial', 'error', 'orfano'], true)));

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'total' => $total,
    'asignadas'   => $asignadas,
    'sin_asignar' => $sinAsignar,
    'orfanos'     => $orfanos,
    'con_pago'    => $conPago,
    'sin_pago'    => $sinPago,
    'generated_at'=> date('c'),
]);
