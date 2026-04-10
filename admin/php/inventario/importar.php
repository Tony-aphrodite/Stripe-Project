<?php
/**
 * POST (multipart) — Import bikes from CSV/Excel file
 * Expects file upload field: "archivo"
 * Accepts .csv and .xlsx (CSV parsed natively, XLSX requires first sheet CSV export)
 *
 * Expected columns (first row = header):
 *   VIN, Modelo, Color, Año, Hecho_en, Notas
 *   (order detected by header names, case-insensitive)
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

if (empty($_FILES['archivo'])) {
    adminJsonOut(['error' => 'No se recibió ningún archivo'], 400);
}

$file = $_FILES['archivo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    adminJsonOut(['error' => 'Error al subir archivo (code: ' . $file['error'] . ')'], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt', 'xlsx'])) {
    adminJsonOut(['error' => 'Formato no soportado. Use CSV o XLSX.'], 400);
}

// ── Parse CSV ────────────────────────────────────────────────────────────
$rows = [];

if ($ext === 'csv' || $ext === 'txt') {
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        adminJsonOut(['error' => 'No se pudo leer el archivo'], 500);
    }
    // Detect BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    while (($line = fgetcsv($handle, 0, ',')) !== false) {
        // Also try semicolon if only 1 column
        if (count($line) === 1 && strpos($line[0], ';') !== false) {
            $line = str_getcsv($line[0], ';');
        }
        $rows[] = $line;
    }
    fclose($handle);
} elseif ($ext === 'xlsx') {
    // Minimal XLSX reader: extract sheet1.xml from zip
    $rows = parseXlsxSimple($file['tmp_name']);
    if ($rows === false) {
        adminJsonOut(['error' => 'No se pudo leer el archivo XLSX'], 400);
    }
}

if (count($rows) < 2) {
    adminJsonOut(['error' => 'El archivo está vacío o no tiene datos después del encabezado'], 400);
}

// ── Map headers ──────────────────────────────────────────────────────────
$headerRow = array_map(function($h) {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_áéíóúñü]/u', '', $h)));
}, $rows[0]);

$colMap = [
    'vin'       => findCol($headerRow, ['vin', 'numeroserie', 'serie', 'noserie']),
    'modelo'    => findCol($headerRow, ['modelo', 'model', 'mod']),
    'color'     => findCol($headerRow, ['color', 'colour']),
    'anio'      => findCol($headerRow, ['año', 'anio', 'ao', 'year', 'añomodelo', 'aniomodelo']),
    'hecho_en'  => findCol($headerRow, ['hechoen', 'madein', 'origen', 'pais', 'país']),
    'notas'     => findCol($headerRow, ['notas', 'notes', 'observaciones', 'nota']),
];

if ($colMap['vin'] === null) {
    adminJsonOut(['error' => 'No se encontró la columna VIN en el encabezado. Columnas detectadas: ' . implode(', ', $rows[0])], 400);
}

// ── Insert ───────────────────────────────────────────────────────────────
$pdo = getDB();
$stmtChk = $pdo->prepare("SELECT id FROM inventario_motos WHERE vin = ? LIMIT 1");
$stmtIns = $pdo->prepare("INSERT INTO inventario_motos
    (vin, vin_display, modelo, color, estado, anio_modelo, hecho_en, notas, log_estados)
    VALUES (?, ?, ?, ?, 'por_llegar', ?, ?, ?, ?)");

$created = 0;
$duplicados = 0;
$errores = 0;
$detalle = [];

for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $vin = trim($row[$colMap['vin']] ?? '');
    if ($vin === '') { $errores++; continue; }

    // Check duplicate
    $stmtChk->execute([$vin]);
    if ($stmtChk->fetch()) {
        $duplicados++;
        $detalle[] = "Fila " . ($i + 1) . ": VIN $vin ya existe";
        continue;
    }

    $modelo  = trim($row[$colMap['modelo']] ?? '');
    $color   = trim($row[$colMap['color']] ?? '');
    $anio    = trim($row[$colMap['anio'] ?? -1] ?? '') ?: date('Y');
    $hecho   = trim($row[$colMap['hecho_en'] ?? -1] ?? '');
    $notas   = trim($row[$colMap['notas'] ?? -1] ?? '');
    $vinDisp = strtoupper($vin);
    $log     = json_encode([['estado' => 'por_llegar', 'fecha' => date('Y-m-d H:i:s'), 'usuario' => $uid]]);

    try {
        $stmtIns->execute([$vin, $vinDisp, $modelo, $color, $anio, $hecho, $notas, $log]);
        $created++;
    } catch (Throwable $e) {
        $errores++;
        $detalle[] = "Fila " . ($i + 1) . ": " . $e->getMessage();
    }
}

adminLog('inventario_importar', [
    'archivo'    => $file['name'],
    'creados'    => $created,
    'duplicados' => $duplicados,
    'errores'    => $errores,
]);

adminJsonOut([
    'ok'         => true,
    'creados'    => $created,
    'duplicados' => $duplicados,
    'errores'    => $errores,
    'total_filas' => count($rows) - 1,
    'detalle'    => array_slice($detalle, 0, 20),
]);


// ── Helper: find column index by possible names ─────────────────────────
function findCol(array $headers, array $names): ?int {
    foreach ($names as $name) {
        $idx = array_search($name, $headers);
        if ($idx !== false) return $idx;
    }
    return null;
}

// ── Helper: minimal XLSX parser (no library needed) ─────────────────────
function parseXlsxSimple(string $path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    // Read shared strings
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            $strings[] = (string)$si->t ?: (string)$si;
        }
    }

    // Read sheet1
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $zip->close();
        return false;
    }

    $sheet = new SimpleXMLElement($sheetXml);
    $rows = [];

    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $val = (string)$c->v;
            // Type "s" = shared string index
            if ((string)$c['t'] === 's' && isset($strings[(int)$val])) {
                $val = $strings[(int)$val];
            }
            $cells[] = $val;
        }
        $rows[] = $cells;
    }

    $zip->close();
    return $rows;
}
