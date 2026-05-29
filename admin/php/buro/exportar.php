<?php
/**
 * Voltika Admin — Export consultas_buro as Excel-compatible CSV
 * NIP-CIEC formato PF — EXACT layout per official CDC template
 * `configurador/add/Archivo_NIP -CIEC_formato PF.xlsx`.
 *
 * Customer brief 2026-05-13 (Óscar, 14th round — "the excel of CDC is
 * not the same structure I sent before, this is a very very important
 * issue"): the previous export had columns + order DIFFERENT from the
 * official CDC template. CDC compliance audit requires EXACT match.
 *
 * Official template order (16 columns, see sheet1.xml row 1):
 *   1.  FOLIO_CDC
 *   2.  FECHA_APROBACION_DE_CONSULTA
 *   3.  HORA_APROBACION_DE_CONSULTA
 *   4.  NOMBRE_CLIENTE            (format: "APELLIDO_PATERNO APELLIDO_MATERNO NOMBRES_DE_PILA")
 *   5.  RFC
 *   6.  CALLE_NUMERO              (dirección calle + número exterior)
 *   7.  COLONIA
 *   8.  CIUDAD
 *   9.  Estado                    (geographic state — note lowercase 'e')
 *   10. TIPO_CONSULTA             (always "PF" for personas físicas)
 *   11. USUARIO                   (CDC API user used for the query)
 *   12. FECHA_CONSULTA            (YYYY/MM/DD, must be >= FECHA_APROBACION)
 *   13. HORA_CONSULTA             (HH:MM:SS 24h)
 *   14. INGRESO_NIP_CIEC          (always "SI")
 *   15. RESPUESTA_LEYENDA_DE_AUTORIZACION (always "SI")
 *   16. ACEPTACION_TERMINOS_Y_CONDICIONES (always "SI")
 *
 * Auto-migrates missing consultas_buro columns the first time it runs
 * AND backfills address fields from preaprobaciones / transacciones
 * where the customer match is reliable (same nombre + apellido_paterno
 * + cp). Wrapped in try/catch + Throwable so OPcache or schema drift
 * never returns an empty 500.
 */

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
    require_once __DIR__ . '/../bootstrap.php';
    $adminId = adminRequireAuth(['admin', 'cedis', 'operador']);

    if (!function_exists('getDB')) {
        _exportFatal('No se pudo cargar la conexión a la base de datos.',
            'getDB() no está definida.', 500);
    }
    $pdo = getDB();
    if (!$pdo instanceof PDO) {
        _exportFatal('Conexión a la base de datos no disponible.', null, 500);
    }

    // Detect table presence
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

    // ── Idempotent schema migration — exact columns the NIP-CIEC PF
    //    template requires. We persist them on consultas_buro so the
    //    export query stays simple AND so future CDC queries can write
    //    directly into the audit row.
    $cols = $pdo->query("SHOW COLUMNS FROM consultas_buro")->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'rfc'                         => "VARCHAR(15)   NULL",
        'tipo_consulta'               => "VARCHAR(10)   NULL DEFAULT 'PF'",
        'usuario_api'                 => "VARCHAR(80)   NULL",
        'fecha_consulta'              => "DATETIME      NULL",
        'hora_consulta'               => "VARCHAR(10)   NULL",
        'fecha_aprobacion_consulta'   => "DATETIME      NULL",
        'hora_aprobacion_consulta'    => "VARCHAR(10)   NULL",
        'ingreso_nip_ciec'            => "VARCHAR(5)    NULL DEFAULT 'SI'",
        'respuesta_leyenda'           => "VARCHAR(5)    NULL DEFAULT 'SI'",
        'aceptacion_tyc'              => "VARCHAR(5)    NULL DEFAULT 'SI'",
        'calle_numero'                => "VARCHAR(200)  NULL",
        'colonia'                     => "VARCHAR(150)  NULL",
        'ciudad'                      => "VARCHAR(120)  NULL",
        'estado_geo'                  => "VARCHAR(80)   NULL COMMENT 'Estado geografico (no estado de la consulta)'",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("buro export ALTER $col: " . $e->getMessage()); }
        }
    }
    $cols = $pdo->query("SHOW COLUMNS FROM consultas_buro")->fetchAll(PDO::FETCH_COLUMN);

    // ── Backfill address fields from preaprobaciones where possible.
    // The CDC query collects nombre + cp at minimum; preaprobaciones
    // tends to have ciudad / estado too. We populate ONLY rows that
    // currently have NULL, never overwrite, so re-runs are safe.
    try {
        $pdo->exec("
            UPDATE consultas_buro cb
            JOIN preaprobaciones p
              ON p.nombre = cb.nombre
             AND p.apellido_paterno = cb.apellido_paterno
             AND COALESCE(p.cp,'') = COALESCE(cb.cp,'')
            SET cb.ciudad     = COALESCE(cb.ciudad,     p.ciudad),
                cb.estado_geo = COALESCE(cb.estado_geo, p.estado)
            WHERE cb.ciudad IS NULL OR cb.estado_geo IS NULL
        ");
    } catch (Throwable $e) { error_log('buro backfill from preaprobaciones: ' . $e->getMessage()); }

    // Backfill estado_geo from estado when estado_geo is empty. consultar-buro.php
    // writes the geographic state into `estado` (not `estado_geo`), but the CDC
    // template column "Estado" maps to estado_geo. This UPDATE makes them
    // consistent without losing data — only fills rows where estado_geo is empty.
    try {
        $pdo->exec("UPDATE consultas_buro
            SET estado_geo = estado
            WHERE (estado_geo IS NULL OR estado_geo = '')
              AND estado IS NOT NULL AND estado != ''");
    } catch (Throwable $e) { error_log('buro backfill estado_geo from estado: ' . $e->getMessage()); }

    // Also fall back to transacciones when no preaprobacion matches —
    // useful for contado/MSI customers that ran CDC outside the credit flow.
    try {
        $pdo->exec("
            UPDATE consultas_buro cb
            JOIN transacciones t
              ON t.nombre LIKE CONCAT('%', cb.nombre, '%')
             AND COALESCE(t.cp,'') = COALESCE(cb.cp,'')
            SET cb.ciudad     = COALESCE(cb.ciudad,     t.ciudad),
                cb.estado_geo = COALESCE(cb.estado_geo, t.estado)
            WHERE cb.ciudad IS NULL OR cb.estado_geo IS NULL
        ");
    } catch (Throwable $e) { error_log('buro backfill from transacciones: ' . $e->getMessage()); }

    // ── Query rows ────────────────────────────────────────────────────
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

    // ── Debug mode — render HTML preview instead of CSV ───────────────
    if (!empty($_GET['debug'])) {
        @ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Diagnóstico CDC export</title>';
        echo '<style>body{font-family:system-ui,sans-serif;padding:20px;}table{border-collapse:collapse;}td,th{border:1px solid #ccc;padding:4px 8px;font-size:11px;}</style>';
        echo '</head><body><h1>Diagnóstico CDC export (formato NIP-CIEC PF oficial)</h1>';
        echo '<p>Filas: <strong>' . count($rows) . '</strong></p>';
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
        }
        echo '</body></html>';
        exit;
    }

    // ── EXACT NIP-CIEC PF column order (per Archivo_NIP-CIEC template) ─
    $excelHeaders = [
        'FOLIO_CDC',
        'FECHA_APROBACION_DE_CONSULTA',
        'HORA_APROBACION_DE_CONSULTA',
        'NOMBRE_CLIENTE',
        'RFC',
        'CALLE_NUMERO',
        'COLONIA',
        'CIUDAD',
        'Estado',                      // lowercase 'e' — per template
        'TIPO_CONSULTA',
        'USUARIO',
        'FECHA_CONSULTA',
        'HORA_CONSULTA',
        'INGRESO_NIP_CIEC',
        'RESPUESTA_LEYENDA_DE_AUTORIZACION',
        'ACEPTACION_TERMINOS_Y_CONDICIONES',
    ];

    // Date format: YYYY/MM/DD (with slashes, NOT dashes)
    $fmtDate = static function (?string $d): string {
        if (!$d) return '';
        $t = strtotime($d);
        return $t ? date('Y/m/d', $t) : $d;
    };
    // Time format: HH:MM:SS 24h
    $fmtTime = static function (?string $t): string {
        if (!$t) return '';
        $ts = strtotime($t);
        return $ts ? date('H:i:s', $ts) : $t;
    };

    // Build NOMBRE_CLIENTE per template format:
    //   "APELLIDO_PATERNO APELLIDO_MATERNO NOMBRES_DE_PILA" (UPPERCASE)
    $fmtNombre = static function (array $r): string {
        $pat = strtoupper(trim((string)($r['apellido_paterno'] ?? '')));
        $mat = strtoupper(trim((string)($r['apellido_materno'] ?? '')));
        $nom = strtoupper(trim((string)($r['nombre']           ?? '')));
        $parts = array_filter([$pat, $mat, $nom], static function ($x) { return $x !== ''; });
        return implode(' ', $parts);
    };

    $mapRow = static function (array $r) use ($fmtDate, $fmtTime, $fmtNombre): array {
        // Fall back to freg when explicit timestamps are absent.
        $fechaAprob = $r['fecha_aprobacion_consulta'] ?? ($r['freg'] ?? '');
        $horaAprob  = $r['hora_aprobacion_consulta']  ?? ($r['freg'] ?? '');
        $fechaCons  = $r['fecha_consulta']            ?? ($r['freg'] ?? '');
        $horaCons   = $r['hora_consulta']             ?? ($r['freg'] ?? '');
        return [
            // 1. FOLIO_CDC
            (string)($r['folio_consulta'] ?? ''),
            // 2. FECHA_APROBACION_DE_CONSULTA
            $fmtDate($fechaAprob),
            // 3. HORA_APROBACION_DE_CONSULTA
            $fmtTime($horaAprob),
            // 4. NOMBRE_CLIENTE — APELLIDO_PATERNO APELLIDO_MATERNO PRIMER_NOMBRE
            $fmtNombre($r),
            // 5. RFC
            strtoupper((string)($r['rfc'] ?? '')),
            // 6. CALLE_NUMERO — combined calle + número exterior (preserves whitespace)
            strtoupper(trim((string)($r['calle_numero'] ?? ''))),
            // 7. COLONIA
            strtoupper((string)($r['colonia'] ?? '')),
            // 8. CIUDAD
            strtoupper((string)($r['ciudad'] ?? '')),
            // 9. Estado (geographic) — fall back to 'estado' column when
            // 'estado_geo' is empty. Newer rows from consultar-buro.php write
            // into 'estado'; only legacy rows had 'estado_geo' populated.
            strtoupper((string)(!empty($r['estado_geo']) ? $r['estado_geo'] : ($r['estado'] ?? ''))),
            // 10. TIPO_CONSULTA — always "PF" for persona física
            strtoupper((string)($r['tipo_consulta'] ?? 'PF')),
            // 11. USUARIO — CDC API user
            (string)($r['usuario_api'] ?? ''),
            // 12. FECHA_CONSULTA
            $fmtDate($fechaCons),
            // 13. HORA_CONSULTA
            $fmtTime($horaCons),
            // 14. INGRESO_NIP_CIEC — "SI"
            strtoupper((string)($r['ingreso_nip_ciec']  ?? 'SI')),
            // 15. RESPUESTA_LEYENDA_DE_AUTORIZACION — "SI"
            strtoupper((string)($r['respuesta_leyenda'] ?? 'SI')),
            // 16. ACEPTACION_TERMINOS_Y_CONDICIONES — "SI"
            strtoupper((string)($r['aceptacion_tyc']    ?? 'SI')),
        ];
    };

    // ── Output CSV (Excel-compatible with UTF-8 BOM) ──────────────────
    @ob_end_clean();
    $filename = 'NIP-CIEC_PF_Voltika_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");        // UTF-8 BOM
    fputcsv($out, $excelHeaders);
    foreach ($rows as $r) fputcsv($out, $mapRow($r));
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
