<?php
/**
 * GET — List credit applications (preaprobaciones) with customer info
 * Filters: ?status= ?seguimiento= ?source= ?search= ?page= ?limit=
 *
 * Tolerant: auto-creates table + adds missing columns so this endpoint
 * works even before preaprobacion-v3.php has run on the new schema.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

// ── Ensure table + all columns exist (idempotent) ─────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS preaprobaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modelo VARCHAR(200),
        ingreso_mensual DECIMAL(12,2),
        pago_semanal DECIMAL(10,2),
        pago_mensual DECIMAL(10,2),
        pago_mensual_buro DECIMAL(12,2),
        pti_total DECIMAL(8,4),
        score INT,
        dpd90_flag TINYINT(1),
        dpd_max INT,
        circulo_source VARCHAR(20),
        enganche_pct DECIMAL(5,2),
        plazo_meses INT,
        status VARCHAR(40),
        enganche_requerido DECIMAL(5,2),
        plazo_max INT,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM preaprobaciones") as $c) {
        $existing[$c['Field']] = true;
    }
    $newCols = [
        'nombre'           => 'VARCHAR(200) NULL',
        'apellido_paterno' => 'VARCHAR(100) NULL',
        'apellido_materno' => 'VARCHAR(100) NULL',
        'email'            => 'VARCHAR(200) NULL',
        'telefono'         => 'VARCHAR(30) NULL',
        'fecha_nacimiento' => 'VARCHAR(20) NULL',
        'cp'               => 'VARCHAR(10) NULL',
        'ciudad'           => 'VARCHAR(100) NULL',
        'estado'           => 'VARCHAR(50) NULL',
        'precio_contado'   => 'DECIMAL(12,2) NULL',
        'synth_score'      => 'INT NULL',
        'truora_ok'        => 'TINYINT(1) NULL',
        'seguimiento'      => "VARCHAR(40) DEFAULT 'nuevo'",
        'notas_admin'      => 'TEXT NULL',
    ];
    foreach ($newCols as $col => $def) {
        if (!isset($existing[$col])) {
            try { $pdo->exec("ALTER TABLE preaprobaciones ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("preaprobaciones add column $col: " . $e->getMessage()); }
        }
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'init: ' . $e->getMessage()], 500);
}

// ── Filters ───────────────────────────────────────────────────────────────
$status      = trim($_GET['status']      ?? '');
$seguimiento = trim($_GET['seguimiento'] ?? '');
$source      = trim($_GET['source']      ?? '');
$search      = trim($_GET['search']      ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = min(200, max(20, (int)($_GET['limit'] ?? 50)));
$offset      = ($page - 1) * $limit;

// By default exclude archived rows. Pass ?seguimiento=archivado to view them.
$where  = ['1=1'];
$params = [];
if ($seguimiento === '') { $where[] = "(seguimiento IS NULL OR seguimiento != 'archivado')"; }
if ($status      !== '') { $where[] = 'status = ?';         $params[] = $status; }
if ($seguimiento !== '') { $where[] = 'seguimiento = ?';    $params[] = $seguimiento; }
if ($source      !== '') { $where[] = 'circulo_source = ?'; $params[] = $source; }
if ($search      !== '') {
    $where[] = '(LOWER(COALESCE(email,\'\')) LIKE ? OR LOWER(COALESCE(nombre,\'\')) LIKE ? OR LOWER(COALESCE(apellido_paterno,\'\')) LIKE ? OR COALESCE(telefono,\'\') LIKE ?)';
    $like = '%' . strtolower($search) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

// ── Query ─────────────────────────────────────────────────────────────────
try {
    $kpi = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(status = 'PREAPROBADO')      AS preaprobado,
        SUM(status = 'CONDICIONAL')      AS condicional,
        SUM(status = 'NO_VIABLE')        AS no_viable,
        SUM(circulo_source = 'real')     AS con_cdc,
        SUM(circulo_source = 'estimado') AS sin_cdc,
        SUM(seguimiento = 'nuevo')       AS pendiente_seguimiento
        FROM preaprobaciones")->fetch(PDO::FETCH_ASSOC) ?: [];

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM preaprobaciones WHERE $whereSql");
    $cntStmt->execute($params);
    $totalFiltrado = (int)$cntStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido_paterno, apellido_materno, email, telefono,
               fecha_nacimiento, cp, ciudad, estado,
               modelo, precio_contado, ingreso_mensual,
               pago_semanal, pago_mensual, pti_total,
               score, synth_score, circulo_source,
               enganche_pct, plazo_meses, status,
               enganche_requerido, plazo_max,
               truora_ok, seguimiento, notas_admin, freg
        FROM preaprobaciones
        WHERE $whereSql
        ORDER BY freg DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut([
        'ok'    => true,
        'kpi'   => $kpi,
        'total' => $totalFiltrado,
        'page'  => $page,
        'pages' => max(1, (int)ceil($totalFiltrado / $limit)),
        'rows'  => $rows,
    ]);
} catch (Throwable $e) {
    error_log('preaprobaciones/listar query: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
