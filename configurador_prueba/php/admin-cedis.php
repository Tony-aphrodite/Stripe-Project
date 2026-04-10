<?php
/**
 * Voltika Admin — CEDIS (Central Inventory) operations
 * Requires admin or cedis role.
 *
 * POST { accion: 'mover_punto', moto_id, punto_id, punto_nombre }     → Move moto to Punto
 * POST { accion: 'asignar_pago', moto_id, transaccion_id, stripe_pi } → Link moto to payment
 * POST { accion: 'import_excel', motos[] }                            → Bulk import from parsed Excel
 * GET  ?vista=puntos                                                  → All puntos with inventory count
 * GET  ?vista=transacciones_pendientes                                → Payments without moto assigned
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/envia-api.php';

$dealer = requireDealerAuth();

// Only admin and cedis roles
if (!in_array($dealer['rol'], ['admin', 'cedis'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso solo para administradores CEDIS']);
    exit;
}

$pdo = getDB();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $vista = $_GET['vista'] ?? '';

    // Single punto inventory drill-down
    if ($vista === 'punto_inventario') {
        $dealerId = intval($_GET['dealer_id'] ?? 0);
        if (!$dealerId) { echo json_encode(['ok' => false, 'error' => 'dealer_id requerido']); exit; }

        // Punto info
        $puntoStmt = $pdo->prepare("SELECT nombre, punto_nombre, email FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $puntoStmt->execute([$dealerId]);
        $puntoInfo = $puntoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // All motos for this punto
        $motosStmt = $pdo->prepare("
            SELECT m.id, m.vin, m.modelo, m.color, m.estado, m.pago_estado,
                   m.cliente_nombre, m.cliente_email, m.cliente_telefono,
                   m.pedido_num, m.dias_en_paso, m.fecha_estado,
                   m.anio_modelo, m.potencia, m.precio_venta,
                   m.stripe_payment_status,
                   IFNULL(co.completado, 0) AS checklist_completado
            FROM inventario_motos m
            LEFT JOIN (
                SELECT moto_id, MAX(completado) AS completado
                FROM checklist_origen GROUP BY moto_id
            ) co ON co.moto_id = m.id
            WHERE m.dealer_id = ? AND m.activo = 1
            ORDER BY m.fecha_estado DESC
        ");
        $motosStmt->execute([$dealerId]);
        $motos = $motosStmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats by estado
        $stats = [];
        foreach ($motos as $m) {
            $e = $m['estado'];
            $stats[$e] = ($stats[$e] ?? 0) + 1;
        }

        echo json_encode(['ok' => true, 'punto' => $puntoInfo, 'motos' => $motos, 'stats' => $stats]);
        exit;
    }

    // Puntos overview
    if ($vista === 'puntos') {
        $stmt = $pdo->query("
            SELECT d.punto_id, d.punto_nombre, d.nombre AS dealer_nombre, d.id AS dealer_id,
                   COUNT(m.id) AS total_motos,
                   SUM(CASE WHEN m.estado NOT IN ('entregada') THEN 1 ELSE 0 END) AS activas,
                   SUM(CASE WHEN m.estado = 'entregada' THEN 1 ELSE 0 END) AS entregadas
            FROM dealer_usuarios d
            LEFT JOIN inventario_motos m ON m.dealer_id = d.id AND m.activo = 1
            WHERE d.activo = 1
            GROUP BY d.id
            ORDER BY d.punto_nombre
        ");
        echo json_encode(['ok' => true, 'puntos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Unassigned transactions (payments without moto)
    if ($vista === 'transacciones_pendientes') {
        try {
            $stmt = $pdo->query("
                SELECT t.id, t.nombre, t.email, t.telefono, t.modelo, t.color,
                       t.tpago, t.total, t.pedido, t.stripe_pi, t.freg
                FROM transacciones t
                LEFT JOIN inventario_motos m ON m.transaccion_id = t.id
                WHERE m.id IS NULL
                ORDER BY t.freg DESC
                LIMIT 100
            ");
            echo json_encode(['ok' => true, 'transacciones' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            // Fallback: simpler query without stripe_pi column
            try {
                $stmt = $pdo->query("
                    SELECT t.id, t.nombre, t.email, t.telefono, t.modelo, t.color,
                           t.tpago, t.total, t.pedido, t.freg
                    FROM transacciones t
                    LEFT JOIN inventario_motos m ON m.transaccion_id = t.id
                    WHERE m.id IS NULL
                    ORDER BY t.freg DESC
                    LIMIT 100
                ");
                echo json_encode(['ok' => true, 'transacciones' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e2) {
                echo json_encode(['ok' => true, 'transacciones' => [], 'note' => 'Table transacciones may not exist yet']);
            }
        }
        exit;
    }

    // Default: all motos with location info + checklist status
    try {
        $stmt = $pdo->query("
            SELECT m.id, m.vin, m.modelo, m.color, m.estado, m.punto_nombre,
                   m.cliente_nombre, m.cliente_email, m.cliente_telefono,
                   m.pedido_num, m.pago_estado, m.stripe_payment_status,
                   m.anio_modelo, m.potencia, m.precio_venta, m.fecha_llegada,
                   m.dealer_id, m.cedis_origen, m.transaccion_id, m.freg,
                   d.nombre AS dealer_nombre,
                   IFNULL(co.completado, 0) AS checklist_completado,
                   IFNULL(co.bloqueado, 0)  AS checklist_bloqueado
            FROM inventario_motos m
            LEFT JOIN dealer_usuarios d ON d.id = m.dealer_id
            LEFT JOIN (
                SELECT moto_id,
                       MAX(completado) AS completado,
                       MAX(bloqueado)  AS bloqueado
                FROM checklist_origen
                GROUP BY moto_id
            ) co ON co.moto_id = m.id
            WHERE m.activo = 1
            ORDER BY m.freg DESC
            LIMIT 500
        ");
        echo json_encode(['ok' => true, 'motos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        // Fallback without checklist join
        $stmt = $pdo->query("
            SELECT m.id, m.vin, m.modelo, m.color, m.estado, m.punto_nombre,
                   m.cliente_nombre, m.cliente_email, m.cliente_telefono,
                   m.pedido_num, m.pago_estado,
                   m.anio_modelo, m.potencia, m.precio_venta,
                   m.dealer_id, m.freg,
                   0 AS checklist_completado, 0 AS checklist_bloqueado
            FROM inventario_motos m
            WHERE m.activo = 1
            ORDER BY m.freg DESC
            LIMIT 500
        ");
        echo json_encode(['ok' => true, 'motos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $json['accion'] ?? '';

// ── MOVER A PUNTO ────────────────────────────────────────────────────────────
if ($accion === 'mover_punto') {
    $motoId        = intval($json['moto_id']  ?? 0);
    $dealerId      = intval($json['dealer_id'] ?? 0);
    $puntoNombre   = trim($json['punto_nombre'] ?? '');
    $puntoId       = trim($json['punto_id']     ?? '');
    $fechaLlegada  = trim($json['fecha_estimada_llegada']  ?? '');
    $fechaRecogida = trim($json['fecha_estimada_recogida'] ?? '');

    if (!$motoId || !$dealerId) {
        echo json_encode(['ok' => false, 'error' => 'moto_id y dealer_id requeridos']);
        exit;
    }

    // Verify checklist_origen is completed and locked before allowing move
    try {
        $clStmt = $pdo->prepare("SELECT completado, bloqueado FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $clStmt->execute([$motoId]);
        $clRow = $clStmt->fetch(PDO::FETCH_ASSOC);
        if (!$clRow || !$clRow['completado'] || !$clRow['bloqueado']) {
            echo json_encode(['ok' => false, 'error' => 'El Checklist de Origen debe completarse antes de transferir la moto.', 'checklist_requerido' => true]);
            exit;
        }
    } catch (PDOException $e) {
        // If checklist table doesn't exist yet, allow move (graceful degradation)
    }

    // Ensure date columns exist
    foreach ([
        "ALTER TABLE inventario_motos ADD COLUMN fecha_estimada_llegada  DATE NULL",
        "ALTER TABLE inventario_motos ADD COLUMN fecha_estimada_recogida DATE NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $ignored) {}
    }

    // Fetch moto for email
    $motoStmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
    $motoStmt->execute([$motoId]);
    $moto = $motoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $pdo->prepare("
        UPDATE inventario_motos
        SET dealer_id = ?, punto_nombre = ?, punto_id = ?,
            cedis_origen = IFNULL(cedis_origen, punto_nombre),
            fecha_estimada_llegada  = NULLIF(?, ''),
            fecha_estimada_recogida = NULLIF(?, '')
        WHERE id = ?
    ")->execute([$dealerId, $puntoNombre, $puntoId, $fechaLlegada, $fechaRecogida, $motoId]);

    // Log
    $pdo->prepare("
        UPDATE inventario_motos
        SET log_estados = JSON_ARRAY_APPEND(IFNULL(log_estados,'[]'), '$',
            JSON_OBJECT('estado', estado, 'accion', 'transferencia_cedis',
                        'dealer', ?, 'timestamp', NOW(),
                        'notas', CONCAT('Transferido a: ', ?)))
        WHERE id = ?
    ")->execute([$dealer['nombre'], $puntoNombre, $motoId]);

    // ── Send email to customer if they have email ─────────────────────────────
    if (!empty($moto['cliente_email'])) {
        $nombre  = $moto['cliente_nombre'] ?? 'Cliente';
        $fechaLL = $fechaLlegada  ? date('d/m/Y', strtotime($fechaLlegada))  : 'Por confirmar';
        $fechaRC = $fechaRecogida ? date('d/m/Y', strtotime($fechaRecogida)) : 'Por confirmar';

        $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
  <tr><td align="center" style="padding:24px;">
    <table width="620" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:8px;overflow:hidden;max-width:620px;width:100%;">
      <tr>
        <td style="background:linear-gradient(135deg,#1d4ed8,#039fe1);padding:24px 28px;color:#fff;">
          <h1 style="margin:0;font-size:22px;font-weight:800;">&#9889; voltika</h1>
          <p style="margin:8px 0 0;font-size:16px;">&#128666; Tu moto está en camino al punto de entrega</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px;">
          <p style="margin:0 0 16px;font-size:15px;color:#111;">
            Hola <strong>' . htmlspecialchars($nombre) . '</strong>,
          </p>
          <p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.6;">
            Tu motocicleta <strong>' . htmlspecialchars(($moto['modelo'] ?? '') . ' ' . ($moto['color'] ?? '')) . '</strong>
            ha sido asignada al punto <strong>' . htmlspecialchars($puntoNombre) . '</strong>.
          </p>
          <table width="100%" cellpadding="8" cellspacing="0"
                 style="border:1px solid #E5E7EB;border-radius:8px;font-size:14px;">
            <tr style="background:#F9FAFB;">
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Punto de entrega</td>
              <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($puntoNombre) . '</td>
            </tr>
            <tr>
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">&#128197; Llegada estimada al punto</td>
              <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;color:#1d4ed8;">' . $fechaLL . '</td>
            </tr>
            <tr style="background:#F9FAFB;">
              <td style="color:#6B7280;padding:10px 12px;">&#128274; Fecha estimada de recogida</td>
              <td style="font-weight:700;padding:10px 12px;color:#059669;">' . $fechaRC . '</td>
            </tr>
          </table>
          <p style="margin:20px 0 0;font-size:13px;color:#9CA3AF;">
            Te avisaremos cuando esté lista para recoger.
            ¿Dudas? <a href="mailto:ventas@voltika.com.mx" style="color:#039fe1;">ventas@voltika.com.mx</a>
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

        sendMail(
            $moto['cliente_email'],
            $nombre,
            '🏍️ Tu Voltika está en camino — ' . $puntoNombre,
            $emailHtml
        );
    }

    echo json_encode(['ok' => true, 'message' => 'Moto transferida a ' . $puntoNombre]);
    exit;
}

// ── ASIGNAR PAGO ─────────────────────────────────────────────────────────────
if ($accion === 'asignar_pago') {
    $motoId       = intval($json['moto_id'] ?? 0);
    $transaccionId = intval($json['transaccion_id'] ?? 0);
    $stripePi     = trim($json['stripe_pi'] ?? '');

    if (!$motoId) {
        echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
        exit;
    }

    $sets = [];
    $vals = [];

    if ($transaccionId) {
        $sets[] = "transaccion_id = ?"; $vals[] = $transaccionId;

        // Get transaction details
        $tx = $pdo->prepare("SELECT nombre, email, telefono, pedido, stripe_pi, modelo, color FROM transacciones WHERE id = ?");
        $tx->execute([$transaccionId]);
        $txData = $tx->fetch(PDO::FETCH_ASSOC);

        if ($txData) {
            if ($txData['stripe_pi']) { $sets[] = "stripe_pi = ?"; $vals[] = $txData['stripe_pi']; }
            if ($txData['nombre'])    { $sets[] = "cliente_nombre = ?"; $vals[] = $txData['nombre']; }
            if ($txData['email'])     { $sets[] = "cliente_email = ?"; $vals[] = $txData['email']; }
            if ($txData['telefono']) { $sets[] = "cliente_telefono = ?"; $vals[] = $txData['telefono']; }
            if ($txData['pedido'])   { $sets[] = "pedido_num = ?"; $vals[] = 'VK-' . $txData['pedido']; }
        }
    }

    if ($stripePi) {
        $sets[] = "stripe_pi = ?"; $vals[] = $stripePi;
    }

    if (empty($sets)) {
        echo json_encode(['ok' => false, 'error' => 'transaccion_id o stripe_pi requerido']);
        exit;
    }

    $vals[] = $motoId;
    $pdo->prepare("UPDATE inventario_motos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

    echo json_encode(['ok' => true, 'message' => 'Pago vinculado a la moto']);
    exit;
}

// ── IMPORT EXCEL (bulk) ──────────────────────────────────────────────────────
if ($accion === 'import_excel') {
    $motos = $json['motos'] ?? [];
    if (empty($motos)) {
        echo json_encode(['ok' => false, 'error' => 'No hay motos para importar']);
        exit;
    }

    $imported = 0;
    $errors = [];

    foreach ($motos as $i => $m) {
        $vin   = strtoupper(trim($m['vin'] ?? ''));
        $modelo = trim($m['modelo'] ?? '');
        $color  = trim($m['color'] ?? '');

        if (!$vin || !$modelo || !$color) {
            $errors[] = "Fila " . ($i+1) . ": VIN, modelo y color son requeridos";
            continue;
        }

        try {
            $vinDisplay = strtoupper($vin);
            $log = json_encode([[
                'estado' => 'por_llegar', 'accion' => 'import_excel',
                'dealer' => $dealer['nombre'], 'timestamp' => date('Y-m-d H:i:s'),
                'notas' => 'Importado desde Excel',
            ]], JSON_UNESCAPED_UNICODE);

            $pdo->prepare("
                INSERT INTO inventario_motos
                    (vin, vin_display, modelo, color, tipo_asignacion, estado,
                     dealer_id, punto_nombre, fecha_estado, log_estados,
                     anio_modelo, potencia, descripcion, accesorios,
                     num_pedimento, fecha_ingreso_pais, aduana, hecho_en, notas)
                VALUES (?,?,?,?,?,'por_llegar',?,?,NOW(),?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $vin, $vinDisplay, $modelo, $color,
                $m['tipo_asignacion'] ?? 'voltika_entrega',
                $dealer['id'], $dealer['punto_nombre'] ?? 'CEDIS',
                $log,
                $m['anio_modelo'] ?? '', $m['potencia'] ?? '',
                $m['descripcion'] ?? '', $m['accesorios'] ?? '',
                $m['num_pedimento'] ?? '', $m['fecha_ingreso_pais'] ?? null,
                $m['aduana'] ?? '', $m['hecho_en'] ?? 'China',
                $m['notas'] ?? 'Importado desde Excel',
            ]);
            $imported++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errors[] = "Fila " . ($i+1) . ": VIN duplicado ($vin)";
            } else {
                $errors[] = "Fila " . ($i+1) . ": " . $e->getMessage();
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'imported' => $imported,
        'errors' => $errors,
        'message' => "$imported motos importadas" . (count($errors) ? ". " . count($errors) . " errores." : ""),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
