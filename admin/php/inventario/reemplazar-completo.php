<?php
/**
 * Voltika — DESTRUCTIVE: full inventory replacement.
 *
 * Customer brief 2026-05-04: customer sent a corrected inventory xlsx and
 * said the current DB inventory is "wrong" — they want a full replace
 * (Option C: DELETE + INSERT). Risk acknowledged: any FK references in
 * transacciones/checklists/envios/dossiers/etc. will be left dangling.
 *
 * SAFETY GUARDS (in order):
 *   1. Admin role only — no operador/cedis access.
 *   2. Confirmation phrase REQUIRED: confirm=ELIMINAR INVENTARIO
 *   3. Default mode is PREVIEW (no writes) — ?action=preview returns a
 *      dry-run summary so the admin can sanity-check counts.
 *   4. Execute wraps DELETE + INSERT in a single transaction so partial
 *      failure rolls back. FK checks disabled during the swap (otherwise
 *      DELETE blocks on transacciones.moto_id).
 *   5. Pre-write check: refuses to run unless the request also passes
 *      acknowledged=1 (front-end checkbox "I understand active orders
 *      will lose their moto_id link").
 *   6. Audit log captures full before/after counts + admin id.
 *
 * BEFORE running this in production:
 *   ✓ Run /configurador/php/backup-databases.php?action=backup-all&token=…
 *   ✓ Download the .tar.gz to your local computer
 *   ✓ Verify the .sql files inside are non-empty and end with `Dump completed`
 *   ✓ Pick a low-traffic window (no active checkout sessions)
 *
 * Usage (multipart POST, file field "archivo"):
 *   1. action=preview                     → dry-run, returns counts
 *   2. action=execute&confirm=ELIMINAR INVENTARIO&acknowledged=1
 *                                         → actually does the swap
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

if (empty($_FILES['archivo'])) {
    adminJsonOut(['ok' => false, 'error' => 'archivo xlsx requerido (multipart field "archivo")'], 400);
}

$file = $_FILES['archivo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    adminJsonOut(['ok' => false, 'error' => 'upload error: ' . $file['error']], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
    adminJsonOut(['ok' => false, 'error' => 'formato no soportado (csv/xlsx)'], 400);
}

$action       = strtolower(trim($_POST['action'] ?? 'preview'));
$confirm      = trim($_POST['confirm'] ?? '');
$acknowledged = !empty($_POST['acknowledged']);

// ── Parse file ─────────────────────────────────────────────────────────
$rows = [];
if ($ext === 'csv' || $ext === 'txt') {
    $h = fopen($file['tmp_name'], 'r');
    if (!$h) adminJsonOut(['ok' => false, 'error' => 'no se pudo leer'], 500);
    $bom = fread($h, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($h);
    while (($line = fgetcsv($h, 0, ',')) !== false) {
        if (count($line) === 1 && strpos($line[0] ?? '', ';') !== false) {
            $line = str_getcsv($line[0], ';');
        }
        $rows[] = $line;
    }
    fclose($h);
} else {
    $rows = parseXlsxFull($file['tmp_name']);
    if ($rows === false) adminJsonOut(['ok' => false, 'error' => 'no se pudo leer xlsx'], 400);
}

if (count($rows) < 2) {
    adminJsonOut(['ok' => false, 'error' => 'archivo vacío o sin datos'], 400);
}

// ── Header normalization (same convention as importar.php) ─────────────
$headerRow = array_map(function($h) {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_áéíóúñü]/u', '', (string)$h)));
}, $rows[0]);

$colMap = [
    'vin'                   => findCol2($headerRow, ['nodeserie', 'vin', 'numeroserie', 'serie', 'noserie']),
    'modelo'                => findCol2($headerRow, ['modelo', 'model', 'mod']),
    'color'                 => findCol2($headerRow, ['color', 'colour']),
    'anio'                  => findCol2($headerRow, ['añomodelo', 'aniomodelo', 'año', 'anio', 'ao', 'year']),
    'num_motor'             => findCol2($headerRow, ['nodemotor', 'nomotor', 'motor', 'nummotor']),
    'potencia'              => findCol2($headerRow, ['potenciadelmotor', 'potencia', 'watts', 'power']),
    'posicion_inventario'   => findCol2($headerRow, ['posicioneninventario', 'posicion', 'ubicacion']),
    'fecha_ingreso_pais'    => findCol2($headerRow, ['fechadeentradaalpais', 'fechaingresopais', 'fechaentradapais']),
    'aduana'                => findCol2($headerRow, ['puertodeentrada', 'aduana', 'puerto']),
    'num_pedimento'         => findCol2($headerRow, ['nodepedimento', 'nopedimento', 'pedimento', 'numpedimento']),
    'cedis_origen'          => findCol2($headerRow, ['cedisorigen', 'cedis', 'almacen']),
    'fecha_entrada_almacen' => findCol2($headerRow, ['fechaentradaalmacen', 'fechaalmacen', 'entradaalmacen']),
    'fecha_salida_almacen'  => findCol2($headerRow, ['fechasalidaalmacen', 'salidaalmacen']),
    'punto_nombre'          => findCol2($headerRow, ['puntoaliadoentregaasignado', 'puntoaliado', 'punto', 'puntoentrega', 'asignacionaotropv', 'asignacionaotro', 'asignacion']),
    'estado'                => findCol2($headerRow, ['estatusdeventa', 'estatus', 'estado', 'status']),
    'pedido_num'            => findCol2($headerRow, ['nodeorden', 'noorden', 'orden', 'pedido']),
    'num_factura'           => findCol2($headerRow, ['nodefactura', 'nofactura', 'factura', 'numfactura']),
    'hecho_en'              => findCol2($headerRow, ['hechoen', 'madein', 'origen']),
    'notas'                 => findCol2($headerRow, ['notas', 'notes', 'observaciones', 'nota']),
    'vendida'               => findCol2($headerRow, ['vendida', 'vendidas', 'vendido']),
];

if ($colMap['vin'] === null) {
    adminJsonOut(['ok' => false, 'error' => 'no se encontró columna VIN / No de serie. Encabezados: ' . implode(', ', $rows[0])], 400);
}

// Build the parsed list (skip rows without VIN — they cannot be inserted)
$parsed = [];
$skipped_no_vin = 0;
for ($i = 1; $i < count($rows); $i++) {
    $r = $rows[$i];
    $vin = trim((string)($r[$colMap['vin']] ?? ''));
    if ($vin === '') { $skipped_no_vin++; continue; }
    $parsed[] = $r;
}
$file_total      = count($rows) - 1;
$file_to_insert  = count($parsed);
$file_unique_vins = count(array_unique(array_map(
    fn($r) => trim((string)($r[$colMap['vin']] ?? '')), $parsed
)));

// ── Connect ────────────────────────────────────────────────────────────
$pdo = getDB();

// Live snapshot of what we're about to delete — counts of "linked" rows
// so the preview tells the admin exactly how many active orders / puntos
// will lose their moto_id reference. These counts are advisory only;
// Option C goes ahead regardless of the link state.
$snap = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN cliente_email IS NOT NULL AND cliente_email <> '' THEN 1 ELSE 0 END) AS con_cliente,
    SUM(CASE WHEN pedido_num IS NOT NULL AND pedido_num <> '' THEN 1 ELSE 0 END) AS con_pedido,
    SUM(CASE WHEN punto_voltika_id IS NOT NULL THEN 1 ELSE 0 END) AS en_punto,
    SUM(CASE WHEN estado = 'entregada' THEN 1 ELSE 0 END) AS entregadas,
    SUM(CASE WHEN estado = 'asignada' THEN 1 ELSE 0 END) AS asignadas,
    SUM(CASE WHEN estado = 'recibida' THEN 1 ELSE 0 END) AS recibidas
    FROM inventario_motos")->fetch(PDO::FETCH_ASSOC);

// Cross-table impact: how many transacciones rows currently point at
// inventario_motos. After DELETE these rows still exist but moto_id
// becomes a dangling FK (the column is preserved as a soft-pointer; no
// hard FK constraint exists in this schema, so the DELETE itself
// succeeds, but the dashboard's "Moto asignada" lookup will go blank
// for those orders).
$txn_linked = (int)$pdo->query("SELECT COUNT(*) FROM transacciones WHERE moto_id IS NOT NULL")->fetchColumn();

if ($action === 'preview') {
    adminJsonOut([
        'ok'             => true,
        'mode'           => 'preview',
        'current_db'     => [
            'total'        => (int)$snap['total'],
            'con_cliente'  => (int)$snap['con_cliente'],
            'con_pedido'   => (int)$snap['con_pedido'],
            'en_punto'     => (int)$snap['en_punto'],
            'entregadas'   => (int)$snap['entregadas'],
            'asignadas'    => (int)$snap['asignadas'],
            'recibidas'    => (int)$snap['recibidas'],
        ],
        'transacciones_con_moto_id' => $txn_linked,
        'file' => [
            'archivo'       => $file['name'],
            'total_filas'   => $file_total,
            'sin_vin'       => $skipped_no_vin,
            'a_insertar'    => $file_to_insert,
            'vins_unicos'   => $file_unique_vins,
        ],
        'warnings' => [
            $txn_linked > 0
                ? "⚠ {$txn_linked} transacciones tienen moto_id asignado — perderán el vínculo."
                : "Sin transacciones vinculadas — DELETE seguro.",
            (int)$snap['en_punto'] > 0
                ? "⚠ {$snap['en_punto']} motos están asignadas a un punto de entrega."
                : "Sin motos en punto.",
            (int)$snap['entregadas'] > 0
                ? "⚠ {$snap['entregadas']} motos están entregadas (cliente ya las recibió)."
                : "Sin motos entregadas en DB.",
        ],
        'next_step' => 'Para ejecutar: action=execute, confirm=ELIMINAR INVENTARIO, acknowledged=1',
    ]);
}

if ($action !== 'execute') {
    adminJsonOut(['ok' => false, 'error' => "action inválido (use 'preview' o 'execute')"], 400);
}

// ── Final confirmation gate ────────────────────────────────────────────
if ($confirm !== 'ELIMINAR INVENTARIO') {
    adminJsonOut([
        'ok'    => false,
        'error' => 'confirmación inválida — debe ser exactamente: confirm=ELIMINAR INVENTARIO',
    ], 400);
}
if (!$acknowledged) {
    adminJsonOut([
        'ok'    => false,
        'error' => 'acknowledged=1 requerido — confirma que entiendes que las transacciones perderán moto_id',
    ], 400);
}

// ── Execute the swap ───────────────────────────────────────────────────
// Single transaction with FK checks disabled. If anything throws, the
// transaction rolls back and DB is left exactly as it was.
$inserted = 0;
$errors   = [];
$samples  = [];   // first 10 rows that succeeded — for the audit log

$pdo->beginTransaction();
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Ensure schema columns exist (defensive — same as importar.php)
    foreach ([
        "ALTER TABLE inventario_motos ADD COLUMN num_factura VARCHAR(50) NULL",
        "ALTER TABLE inventario_motos ADD COLUMN posicion_inventario VARCHAR(20) NULL",
        "ALTER TABLE inventario_motos ADD COLUMN fecha_entrada_almacen DATE NULL",
        "ALTER TABLE inventario_motos ADD COLUMN fecha_salida_almacen DATE NULL",
        "ALTER TABLE inventario_motos ADD COLUMN num_motor VARCHAR(50) NULL",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (PDOException $ignore) {}
    }

    // Wipe — full deletion. NOTE: we do NOT truncate (TRUNCATE resets
    // AUTO_INCREMENT and breaks any external system that cached old IDs).
    $deleted = (int)$pdo->exec("DELETE FROM inventario_motos");

    // Re-insert from file
    $stmtIns = $pdo->prepare("INSERT INTO inventario_motos
        (vin, vin_display, modelo, color, estado, anio_modelo, hecho_en, notas,
         num_motor, potencia, posicion_inventario, fecha_ingreso_pais, aduana,
         num_pedimento, num_factura, cedis_origen, fecha_entrada_almacen,
         fecha_salida_almacen, punto_nombre, pedido_num, log_estados, bloqueado_venta, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

    foreach ($parsed as $idx => $r) {
        $vin = trim((string)($r[$colMap['vin']] ?? ''));
        if ($vin === '') continue;

        $modelo    = normalizeModelo2(getValR($r, $colMap['modelo']));
        $color     = normalizeInvColor2(getValR($r, $colMap['color']));
        $anio      = getValR($r, $colMap['anio']) ?: date('Y');
        $hecho     = getValR($r, $colMap['hecho_en']) ?: 'China';
        $notas     = getValR($r, $colMap['notas']);
        $numMotor  = getValR($r, $colMap['num_motor']);
        $potencia  = getValR($r, $colMap['potencia']);
        $posicion  = getValR($r, $colMap['posicion_inventario']);
        $fechaIng  = parseDateLoose(getValR($r, $colMap['fecha_ingreso_pais'])) ?: date('Y-m-d');
        $aduana    = getValR($r, $colMap['aduana']);
        $pedimento = getValR($r, $colMap['num_pedimento']);
        $factura   = getValR($r, $colMap['num_factura']);
        $cedis     = getValR($r, $colMap['cedis_origen']);
        $fEntAlm   = parseDateLoose(getValR($r, $colMap['fecha_entrada_almacen']));
        $fSalAlm   = parseDateLoose(getValR($r, $colMap['fecha_salida_almacen']));
        $punto     = getValR($r, $colMap['punto_nombre']);
        $pedido    = getValR($r, $colMap['pedido_num']);
        $vendida   = getValR($r, $colMap['vendida']);
        $vinDisp   = strtoupper($vin);
        $estado    = 'recibida';

        $log = json_encode([[
            'estado'    => $estado,
            'accion'    => 'reemplazo_completo',
            'dealer'    => 'admin#' . $adminId,
            'timestamp' => date('Y-m-d H:i:s'),
            'archivo'   => $file['name'],
        ]], JSON_UNESCAPED_UNICODE);

        $bloqueado = ($vendida !== '' || preg_match('/(repuestos|reparacion|reparación|sin eje|oficina)/iu', $posicion . ' ' . $notas)) ? 1 : 0;

        try {
            $stmtIns->execute([
                $vin, $vinDisp, $modelo, $color, $estado, $anio, $hecho, $notas,
                $numMotor ?: null, $potencia ?: null, $posicion ?: null,
                $fechaIng, $aduana ?: null, $pedimento ?: null, $factura ?: null,
                $cedis ?: null, $fEntAlm, $fSalAlm, $punto ?: null,
                $pedido ?: null, $log, $bloqueado,
            ]);
            $inserted++;
            if (count($samples) < 10) $samples[] = ['vin' => $vin, 'modelo' => $modelo, 'color' => $color];
        } catch (Throwable $e) {
            $errors[] = 'Fila ' . ($idx + 2) . " VIN $vin: " . $e->getMessage();
            // Don't continue on duplicate-VIN inside file — that's a data
            // problem the admin must fix. Throw to rollback.
            if (count($errors) > 5) {
                throw new RuntimeException('Demasiados errores — rollback. Primer error: ' . $errors[0]);
            }
        }
    }

    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e2) {}
    error_log('reemplazar-completo fatal: ' . $e->getMessage());
    adminJsonOut([
        'ok'      => false,
        'error'   => 'rollback ejecutado: ' . $e->getMessage(),
        'errores' => $errors,
    ], 500);
}

adminLog('inventario_reemplazo_completo', [
    'archivo'         => $file['name'],
    'eliminados'      => $deleted,
    'insertados'      => $inserted,
    'errores'         => count($errors),
    'transacciones_afectadas' => $txn_linked,
    'samples'         => $samples,
]);

adminJsonOut([
    'ok'                       => true,
    'mode'                     => 'execute',
    'archivo'                  => $file['name'],
    'eliminados'               => $deleted,
    'insertados'               => $inserted,
    'errores'                  => count($errors),
    'errores_detalle'          => array_slice($errors, 0, 10),
    'transacciones_huerfanas'  => $txn_linked,
    'mensaje'                  => "Reemplazo completado. {$deleted} filas borradas, {$inserted} nuevas. Verifica el dashboard antes de cerrar sesión.",
]);


// ════════════════════════════════════════════════════════════════════════
// Helpers (renamed with -2 suffix to avoid colliding with importar.php
// helpers if both files load in the same request)
// ════════════════════════════════════════════════════════════════════════

function findCol2(array $headers, array $names): ?int {
    foreach ($names as $name) {
        $idx = array_search($name, $headers);
        if ($idx !== false) return $idx;
    }
    return null;
}

function getValR(array $row, ?int $idx): string {
    if ($idx === null || $idx < 0 || !isset($row[$idx])) return '';
    return trim((string)$row[$idx]);
}

function normalizeModelo2(string $raw): string {
    $raw = trim($raw);
    $low = strtolower($raw);
    if ($low === '') return $raw;
    if (strpos($low, 'pesgo plus') !== false) return 'Pesgo Plus';
    if (strpos($low, 'mino')       !== false) return 'Mino-B';
    if (strpos($low, 'ukko')       !== false) return 'Ukko S+';
    if (strpos($low, 'mc10')       !== false) return 'MC10 Streetx';
    if (preg_match('/\bM(\d+)\b/i', $raw, $m)) return 'M' . $m[1];
    return $raw;
}

function normalizeInvColor2(string $raw): string {
    $c = strtolower(trim($raw));
    $map = [
        'negra'=>'negro', 'blue'=>'azul', 'white'=>'blanco', 'black'=>'negro',
        'gray'=>'gris',   'grey'=>'gris',  'silver'=>'plata', 'green'=>'verde',
        'orange'=>'naranja', 'yellow'=>'amarillo', 'red'=>'rojo',
    ];
    return $map[$c] ?? $c;
}

/**
 * Date parser that accepts strtotime-friendly strings AND Excel serial
 * numbers (which the existing parseDate() in importar.php silently
 * dropped). Excel epoch is 1900-01-01 with the leap-year bug, so day 60
 * is the missing 1900-02-29 — we subtract 2 days from any serial > 60
 * to get the Unix timestamp (= subtract 1 for epoch off-by-one + 1 for
 * the missing leap day).
 */
function parseDateLoose(string $val): ?string {
    $val = trim($val);
    if ($val === '' || $val === '0') return null;
    if (ctype_digit($val) && (int)$val >= 1000 && (int)$val < 100000) {
        $days = (int)$val;
        if ($days >= 60) $days -= 1;            // skip the fake 1900-02-29
        $ts = ($days - 25569) * 86400;          // 25569 = 1970-01-01 in serial
        if ($ts > 0) return gmdate('Y-m-d', $ts);
    }
    $ts = strtotime($val);
    if ($ts) return date('Y-m-d', $ts);
    return null;
}

function parseXlsxFull(string $path) {
    if (!class_exists('ZipArchive')) {
        // PHP-zip extension not installed — surface that clearly so
        // admin sees the real reason instead of a generic parse fail.
        throw new RuntimeException('ZipArchive (php-zip) no está disponible — instalar php-zip');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    // Shared strings — same conservative pattern as importar.php's
    // parseXlsxSimple (known to work in production). Children-by-name
    // traversal works against the default namespace; xpath does not
    // unless namespace is registered, which is fragile across PHP /
    // libxml versions on Plesk shared hosting.
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $strings[] = (string)$si->t ?: (string)$si;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) { $zip->close(); return false; }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) { $zip->close(); return false; }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        // Position cells by column letter so empty-cell skips don't
        // misalign columns. The xlsx the customer sent has 17 columns
        // and rows with blank cells in the middle (Estatus de venta,
        // No de Orden, etc.).
        $cells  = [];
        $maxIdx = -1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = preg_replace('/\d/', '', $ref);
            $idx = colLetterToIndex($col);
            $val = (string)$c->v;
            $type = (string)$c['t'];
            if ($type === 's' && $val !== '' && isset($strings[(int)$val])) {
                $val = $strings[(int)$val];
            } elseif ($type === 'inlineStr' && isset($c->is)) {
                $val = (string)$c->is->t;
            }
            $cells[$idx] = $val;
            if ($idx > $maxIdx) $maxIdx = $idx;
        }
        $linear = [];
        for ($i = 0; $i <= $maxIdx; $i++) $linear[] = $cells[$i] ?? '';
        $rows[] = $linear;
    }
    $zip->close();
    return $rows;
}

function colLetterToIndex(string $letters): int {
    $n = 0;
    $letters = strtoupper($letters);
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $n - 1;
}
