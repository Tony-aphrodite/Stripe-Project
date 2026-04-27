<?php
/**
 * Voltika Admin — Export consultas_buro as Excel-compatible CSV
 * NIP-CIEC formato PF compliant
 *
 * GET params:
 *   desde  — fecha inicio (YYYY-MM-DD), optional
 *   hasta  — fecha fin   (YYYY-MM-DD), optional
 */
session_name('VOLTIKA_ADMIN');
session_start();
if (empty($_SESSION['admin_user_id'])) {
    http_response_code(401);
    exit('No autorizado');
}

require_once __DIR__ . '/../../configurador_prueba/php/config.php';
$pdo = getDB();

$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

// ── Query ────────────────────────────────────────────────────────────────
$sql = "SELECT * FROM consultas_buro WHERE 1=1";
$params = [];

if ($desde) {
    $sql .= " AND DATE(freg) >= ?";
    $params[] = $desde;
}
if ($hasta) {
    $sql .= " AND DATE(freg) <= ?";
    $params[] = $hasta;
}
$sql .= " ORDER BY freg ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Excel column mapping (NIP-CIEC formato PF) ──────────────────────────
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

// ── Map DB row → Excel row ───────────────────────────────────────────────
function mapRow(array $r): array {
    $fmtDate = function(?string $d): string {
        if (!$d) return '';
        $t = strtotime($d);
        return $t ? date('Y/m/d', $t) : $d;
    };
    $fmtTime = function(?string $t): string {
        if (!$t) return '';
        $ts = strtotime($t);
        return $ts ? date('H:i:s', $ts) : $t;
    };

    return [
        $r['folio_consulta'] ?? '',
        $fmtDate($r['fecha_aprobacion_consulta'] ?? ''),
        $fmtTime($r['hora_aprobacion_consulta'] ?? ''),
        strtoupper($r['apellido_paterno'] ?? ''),
        strtoupper($r['apellido_materno'] ?? ''),
        strtoupper($r['nombre'] ?? ''),
        $fmtDate($r['fecha_nacimiento'] ?? ''),
        strtoupper($r['rfc'] ?? ''),
        strtoupper($r['curp'] ?? ''),
        strtoupper($r['tipo_consulta'] ?? 'PF'),
        $r['usuario_api'] ?? '',
        $fmtDate($r['fecha_consulta'] ?? ($r['freg'] ?? '')),
        $fmtTime($r['hora_consulta'] ?? ''),
        strtoupper($r['ingreso_nip_ciec'] ?? 'SI'),
        strtoupper($r['respuesta_leyenda'] ?? 'SI'),
        strtoupper($r['aceptacion_tyc'] ?? 'SI'),
    ];
}

// ── Output CSV (Excel compatible with BOM) ───────────────────────────────
$filename = 'NIP-CIEC_PF_Voltika_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel to detect encoding
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $excelHeaders);

foreach ($rows as $r) {
    fputcsv($out, mapRow($r));
}

fclose($out);
exit;
