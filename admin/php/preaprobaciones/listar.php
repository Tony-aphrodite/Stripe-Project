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

// ── Ensure consultas_buro extended columns exist (customer brief 2026-05-04)
// The dashboard manual-review screen pulls aprobado_total / vencido_total /
// consultas_6m / etc. from the JOIN below. consultar-buro.php adds these
// columns on next CDC query, but listar.php may run BEFORE that — and the
// SELECT would fail with "Unknown column". Add them here too, idempotent
// per ALTER. Safe: NULL-able new columns can't break existing rows.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200), apellido_paterno VARCHAR(100), apellido_materno VARCHAR(100),
        fecha_nacimiento VARCHAR(20), cp VARCHAR(10),
        score INT, pago_mensual DECIMAL(12,2), dpd90_flag TINYINT(1),
        dpd_max INT, num_cuentas INT, folio_consulta VARCHAR(100),
        freg DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    foreach ([
        'aprobado_total'             => 'DECIMAL(14,2) NULL',
        'vencido_total'              => 'DECIMAL(14,2) NULL',
        'cuentas_activas'            => 'INT NULL',
        'cuentas_dpd90_historico'    => 'INT NULL',
        'consultas_6m'               => 'INT NULL',
        'credito_mas_antiguo_meses'  => 'INT NULL',
        'pld_match'                  => 'TINYINT(1) NULL',
        'score_reasons'              => 'VARCHAR(80) NULL',
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN `$col` $def"); }
        catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

// ── Filters ───────────────────────────────────────────────────────────────
$status      = trim($_GET['status']      ?? '');
$seguimiento = trim($_GET['seguimiento'] ?? '');
$source      = trim($_GET['source']      ?? '');
$search      = trim($_GET['search']      ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = min(200, max(20, (int)($_GET['limit'] ?? 50)));
$offset      = ($page - 1) * $limit;

// By default exclude archived rows. Pass ?seguimiento=archivado to view them.
// Customer brief 2026-05-02: prefix every column with `p.` so the WHERE
// clause stays unambiguous after the LEFT JOIN to verificaciones_identidad
// — without prefixes MySQL throws "ambiguous column" on shared names.
$where  = ['1=1'];
$params = [];
if ($seguimiento === '') { $where[] = "(p.seguimiento IS NULL OR p.seguimiento != 'archivado')"; }
if ($status      !== '') { $where[] = 'p.status = ?';         $params[] = $status; }
if ($seguimiento !== '') { $where[] = 'p.seguimiento = ?';    $params[] = $seguimiento; }
if ($source      !== '') { $where[] = 'p.circulo_source = ?'; $params[] = $source; }
if ($search      !== '') {
    $where[] = '(LOWER(COALESCE(p.email,\'\')) LIKE ? OR LOWER(COALESCE(p.nombre,\'\')) LIKE ? OR LOWER(COALESCE(p.apellido_paterno,\'\')) LIKE ? OR COALESCE(p.telefono,\'\') LIKE ?)';
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

    // The WHERE clause now uses `p.` prefixes (post-JOIN), so the count
    // query needs the same alias to satisfy MySQL.
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM preaprobaciones p WHERE $whereSql");
    $cntStmt->execute($params);
    $totalFiltrado = (int)$cntStmt->fetchColumn();

    // Customer brief 2026-05-02: surface DETAILED Truora status (not just
    // OK / not OK). The truora_ok flag is binary and can't tell the admin
    // apart these very different cases:
    //   - applicant never reached Truora (CDC-only path)
    //   - Truora attempted, identity check failed (declined_reason populated)
    //   - Truora succeeded but CURP mismatch detected by our cross-check
    //   - Truora pending (in progress, customer hasn't completed selfie yet)
    //   - Manual review queued (failure with non-recoverable reason)
    //
    // We LEFT JOIN verificaciones_identidad on telefono OR email so the
    // dashboard can render a rich badge with the actual Truora outcome
    // and the declined reason if one exists. NULL means no Truora attempt
    // was ever made for this applicant.
    // Customer brief 2026-05-04: surface detailed buró data for the
    // manual-review screen redesign. JOIN consultas_buro on the same
    // (telefono OR email) heuristic we already use for verificaciones_
    // identidad, plus a fallback match on (nombre + cp) so legacy CDC
    // queries (which were stored before we captured telefono/email
    // consistently) also surface their pago_mensual / dpd / num_cuentas
    // values. Most-recent row wins (ORDER BY id DESC LIMIT 1).
    $stmt = $pdo->prepare("
        SELECT p.id, p.nombre, p.apellido_paterno, p.apellido_materno, p.email, p.telefono,
               p.fecha_nacimiento, p.cp, p.ciudad, p.estado,
               p.modelo, p.precio_contado, p.ingreso_mensual,
               p.pago_semanal, p.pago_mensual, p.pago_mensual_buro, p.pti_total,
               p.score, p.synth_score, p.circulo_source,
               p.enganche_pct, p.plazo_meses, p.status,
               p.enganche_requerido, p.plazo_max,
               p.dpd90_flag, p.dpd_max,
               p.truora_ok, p.seguimiento, p.notas_admin, p.freg,
               vi.id                AS verif_id,
               vi.truora_process_id,
               vi.truora_status,
               vi.truora_failure_status,
               vi.truora_declined_reason,
               vi.curp_match,
               vi.name_match,
               vi.approved          AS truora_approved,
               vi.manual_review_required,
               vi.manual_review_reason,
               vi.truora_updated_at,
               -- Round 20 (2026-05-14, Óscar): expose the captured INE +
               -- selfie filenames so the admin Documentos modal can show
               -- the actual photos. files_saved is a JSON array of
               -- filenames (relative to configurador/php/uploads/). The
               -- JS in admin-ventas.js parses it to derive
               -- ine_front_url / ine_back_url / selfie_url.
               vi.files_saved       AS truora_files_saved,
               cb.score             AS buro_score,
               cb.pago_mensual      AS buro_pago_mensual,
               cb.dpd90_flag        AS buro_dpd90_flag,
               cb.dpd_max           AS buro_dpd_max,
               cb.num_cuentas       AS buro_num_cuentas,
               cb.folio_consulta    AS buro_folio,
               cb.freg              AS buro_freg,
               cb.aprobado_total            AS buro_aprobado_total,
               cb.vencido_total             AS buro_vencido_total,
               cb.cuentas_activas           AS buro_cuentas_activas,
               cb.cuentas_dpd90_historico   AS buro_cuentas_dpd90_hist,
               cb.consultas_6m              AS buro_consultas_6m,
               cb.credito_mas_antiguo_meses AS buro_credito_mas_antiguo_meses,
               cb.pld_match                 AS buro_pld_match,
               cb.score_reasons             AS buro_score_reasons
        FROM preaprobaciones p
        LEFT JOIN verificaciones_identidad vi
               ON vi.id = (
                   SELECT vi2.id FROM verificaciones_identidad vi2
                    WHERE (vi2.telefono <> '' AND vi2.telefono = p.telefono)
                       OR (vi2.email    <> '' AND vi2.email    = p.email)
                    ORDER BY vi2.id DESC LIMIT 1
               )
        LEFT JOIN consultas_buro cb
               ON cb.id = (
                   SELECT cb2.id FROM consultas_buro cb2
                    WHERE (cb2.nombre = p.nombre
                           AND cb2.apellido_paterno = p.apellido_paterno
                           AND COALESCE(cb2.cp,'') = COALESCE(p.cp,''))
                    ORDER BY cb2.id DESC LIMIT 1
               )
        WHERE $whereSql
        ORDER BY p.freg DESC
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
