<?php
/**
 * Voltika Admin — Export consultas_buro as Excel-compatible CSV
 * NIP-CIEC formato PF compliant (CDC signature validation).
 *
 * Customer brief 2026-05-13 (Óscar, 12th round — URGENT "we can't
 * download the excel file"): the endpoint returned HTTP 500 because:
 *   1. The script crashed before headers when getDB() or the query
 *      hit an error (no try/catch wrapping the whole flow).
 *   2. The script SELECT *'d consultas_buro but mapRow() accessed
 *      columns (rfc, curp, tipo_consulta, fecha_aprobacion_consulta,
 *      hora_aprobacion_consulta, fecha_consulta, hora_consulta,
 *      ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc, usuario_api)
 *      that aren't in master-bootstrap.php's base schema, so they were
 *      undefined-index errors on every row.
 *
 * Rewrite:
 *   • Wrap everything in try/catch with a Throwable handler — never
 *     emit raw HTML 500.
 *   • Auto-ALTER TABLE to add missing NIP-CIEC PF columns the first
 *     time the export runs (idempotent, single migration).
 *   • Schema-aware mapRow that only references columns the DB has.
 *   • Friendly error page when no rows match (instead of empty CSV).
 *
 * GET params:
 *   desde  — fecha inicio (YYYY-MM-DD), optional
 *   hasta  — fecha fin   (YYYY-MM-DD), optional
 *   debug  — set to 1 to render an HTML diagnostic instead of CSV
 */

// Capture buffer so we never accidentally emit partial output before
// the proper headers. If anything fatal happens we flush a JSON/HTML
// error instead of partial CSV.
ob_start();

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function _exportFatal(string $msg, ?string $detail = null, int $code = 500): void {
    @ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    $det = $detail ? htmlspecialchars($detail) : '';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error al exportar</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;padding:28px;max-width:640px;margin:40px auto;background:#f8fafc;color:#0c2340;line-height:1.55;}';
    echo '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;}h1{font-size:20px;margin:0 0 14px;}';
    echo '.alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:8px;margin-bottom:14px;}';
    echo 'pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:11.5px;}</style></head><body>';
    echo '<div class="card"><h1>📊 Error al exportar Excel CDC</h1>';
    echo '<div class="alert"><strong>' . htmlspecialchars($msg) . '</strong></div>';
    if ($det) echo '<p style="font-size:12px;color:#64748b;">Detalle técnico (para soporte):</p><pre>' . $det . '</pre>';
    echo '<p style="font-size:12px;color:#64748b;margin-top:14px;">Avisa a soporte con este mensaje. <a href="javascript:history.back()" style="color:#039fe1;">Volver</a></p>';
    echo '</div></body></html>';
    exit;
}

try {
    // ── Bootstrap (auth + DB) ──────────────────────────────────────────
    // Customer brief 2026-05-13 (Óscar, 12th round — diagnostic shown:
    // "require_once .../configurador/php/config.php" failed). The
    // configurador/php/config.php file is not always present (or its
    // path differs between deploys). Other endpoints in this folder
    // (e.g. listar.php) use the standard admin/php/bootstrap.php which
    // resolves auth + DB + schema in one canonical place. Mirror that
    // pattern here.
    require_once __DIR__ . '/../bootstrap.php';

    // ── Session check (admin only) ─────────────────────────────────────
    // bootstrap.php already starts the VOLTIKA_ADMIN session and exposes
    // adminRequireAuth(). Use it instead of hand-rolled session check.
    if (function_exists('adminRequireAuth')) {
        // Same role-set as buro/listar.php so anyone who can SEE the
        // CDC data can also EXPORT it. CDC compliance audits often run
        // through cedis/operador, not just admin.
        $adminId = adminRequireAuth(['admin','cedis','operador']);
    } else if (empty($_SESSION['admin_user_id'])) {
        _exportFatal('No autorizado. Inicia sesión como admin para descargar.', null, 401);
    }

    // ── Resolve DB connection ──────────────────────────────────────────
    if (!function_exists('getDB')) {
        _exportFatal('No se pudo cargar la conexión a la base de datos.',
            'getDB() no está definida — verifica admin/php/bootstrap.php.', 500);
    }
    $pdo = getDB();
    if (!$pdo instanceof PDO) {
        _exportFatal('Conexión a la base de datos no disponible.', null, 500);
    }

    // ── Detect table presence ──────────────────────────────────────────
    $tableExists = false;
    try {
        $pdo->query("SELECT 1 FROM consultas_buro LIMIT 0");
        $tableExists = true;
    } catch (Throwable $e) { /* table missing */ }
    if (!$tableExists) {
        _exportFatal(
            'La tabla consultas_buro no existe en este servidor.',
            'Ejecuta voltikaEnsureSchema() (master-bootstrap.php) o crea la tabla manualmente.',
            500
        );
    }

    // ── Idempotent migration — add NIP-CIEC PF columns if missing ─────
    // The NIP-CIEC PF audit format requires the following extra fields.
    // We add them on-the-fly so the export always produces the correct
    // column set even on installs that pre-date the audit requirement.
    $cols = $pdo->query("SHOW COLUMNS FROM consultas_buro")->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'rfc'                         => "VARCHAR(15)  NULL",
        'curp'                        => "VARCHAR(20)  NULL",
        'tipo_consulta'               => "VARCHAR(10)  NULL DEFAULT 'PF'",
        'usuario_api'                 => "VARCHAR(80)  NULL",
        'fecha_consulta'              => "DATETIME     NULL",
        'hora_consulta'               => "VARCHAR(10)  NULL",
        'fecha_aprobacion_consulta'   => "DATETIME     NULL",
        'hora_aprobacion_consulta'    => "VARCHAR(10)  NULL",
        'ingreso_nip_ciec'            => "VARCHAR(5)   NULL DEFAULT 'SI'",
        'respuesta_leyenda'           => "VARCHAR(5)   NULL DEFAULT 'SI'",
        'aceptacion_tyc'              => "VARCHAR(5)   NULL DEFAULT 'SI'",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("buro export ALTER $col: " . $e->getMessage()); }
        }
    }
    // Refresh column list after migration.
    $cols = $pdo->query("SHOW COLUMNS FROM consultas_buro")->fetchAll(PDO::FETCH_COLUMN);

    // ── Query ──────────────────────────────────────────────────────────
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';

    $sql = "SELECT * FROM consultas_buro WHERE 1=1";
    $params = [];
    if ($desde) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            _exportFatal('Fecha "desde" inválida. Usa formato YYYY-MM-DD.', null, 400);
        }
        $sql .= " AND DATE(freg) >= ?";
        $params[] = $desde;
    }
    if ($hasta) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            _exportFatal('Fecha "hasta" inválida. Usa formato YYYY-MM-DD.', null, 400);
        }
        $sql .= " AND DATE(freg) <= ?";
        $params[] = $hasta;
    }
    $sql .= " ORDER BY freg ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Debug mode — render HTML instead of CSV ────────────────────────
    if (!empty($_GET['debug'])) {
        @ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Diagnóstico CDC export</title>';
        echo '<style>body{font-family:system-ui,sans-serif;padding:20px;}table{border-collapse:collapse;}td,th{border:1px solid #ccc;padding:4px 8px;font-size:12px;}</style>';
        echo '</head><body><h1>Diagnóstico CDC export</h1>';
        echo '<p>Filas encontradas: <strong>' . count($rows) . '</strong> entre ' . htmlspecialchars($desde ?: 'inicio') . ' y ' . htmlspecialchars($hasta ?: 'hoy') . '</p>';
        echo '<p>Columnas presentes: ' . count($cols) . ' (' . htmlspecialchars(implode(', ', $cols)) . ')</p>';
        if ($rows) {
            echo '<table><thead><tr>';
            foreach (array_keys($rows[0]) as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
            echo '</tr></thead><tbody>';
            foreach (array_slice($rows, 0, 5) as $r) {
                echo '<tr>';
                foreach ($r as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p>(Solo se muestran las primeras 5 filas en modo debug. Quita ?debug=1 para descargar CSV.)</p>';
        } else {
            echo '<p>Sin filas que coincidan con el rango de fechas.</p>';
        }
        echo '</body></html>';
        exit;
    }

    // ── Excel column mapping (NIP-CIEC formato PF) ─────────────────────
    $excelHeaders = [
        'FOLIO_CDC',
        'FECHA_APROBACION_DE_CONSULTA',
        'HORA_APROBACION_DE_CONSULTA',
        'APELLIDO_PATERNO',
        'APELLIDO_MATERNO',
        'PRIMER_NOMBRE',
        'FECHA_NACIMIENTO',
        'RFC',
        'CURP',
        'TIPO_CONSULTA',
        'USUARIO',
        'FECHA_CONSULTA',
        'HORA_CONSULTA',
        'INGRESO_NIP_CIEC',
        'RESPUESTA_LEYENDA_DE_AUTORIZACION',
        'ACEPTACION_TERMINOS_Y_CONDICIONES',
    ];

    $fmtDate = static function (?string $d): string {
        if (!$d) return '';
        $t = strtotime($d);
        return $t ? date('Y/m/d', $t) : $d;
    };
    $fmtTime = static function (?string $t): string {
        if (!$t) return '';
        $ts = strtotime($t);
        return $ts ? date('H:i:s', $ts) : $t;
    };
    $mapRow = static function (array $r) use ($fmtDate, $fmtTime): array {
        $fechaAprob = $r['fecha_aprobacion_consulta'] ?? ($r['freg'] ?? '');
        $horaAprob  = $r['hora_aprobacion_consulta']  ?? ($r['freg'] ?? '');
        $fechaCons  = $r['fecha_consulta']            ?? ($r['freg'] ?? '');
        $horaCons   = $r['hora_consulta']             ?? ($r['freg'] ?? '');
        return [
            (string)($r['folio_consulta'] ?? ''),
            $fmtDate($fechaAprob),
            $fmtTime($horaAprob),
            strtoupper((string)($r['apellido_paterno'] ?? '')),
            strtoupper((string)($r['apellido_materno'] ?? '')),
            strtoupper((string)($r['nombre']           ?? '')),
            $fmtDate($r['fecha_nacimiento'] ?? ''),
            strtoupper((string)($r['rfc']  ?? '')),
            strtoupper((string)($r['curp'] ?? '')),
            strtoupper((string)($r['tipo_consulta'] ?? 'PF')),
            (string)($r['usuario_api'] ?? ''),
            $fmtDate($fechaCons),
            $fmtTime($horaCons),
            strtoupper((string)($r['ingreso_nip_ciec']  ?? 'SI')),
            strtoupper((string)($r['respuesta_leyenda'] ?? 'SI')),
            strtoupper((string)($r['aceptacion_tyc']    ?? 'SI')),
        ];
    };

    // ── Output CSV (Excel compatible with BOM) ─────────────────────────
    @ob_end_clean();
    $filename = 'NIP-CIEC_PF_Voltika_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel to detect encoding
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $excelHeaders);

    foreach ($rows as $r) {
        fputcsv($out, $mapRow($r));
    }
    fclose($out);
    exit;

} catch (Throwable $e) {
    error_log('buro/exportar FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    _exportFatal(
        'Error inesperado al generar el archivo Excel.',
        $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        500
    );
}
