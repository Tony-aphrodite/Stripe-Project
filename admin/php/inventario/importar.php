<?php
/**
 * POST (multipart) — Import bikes from CSV/Excel file
 * Expects file upload field: "archivo"
 * Accepts .csv and .xlsx
 *
 * Expected columns (first row = header, order auto-detected):
 *   Conteo, Modelo, Año Modelo, Color, No de serie, No de Motor,
 *   Potencia del motor, Posicion en inventario, Fecha de entrada al pais,
 *   Puerto de entrada, No de pedimento, CEDIS ORIGEN,
 *   Fecha Entrada Almacen, Fecha Salida Almacen,
 *   Punto aliado/Entrega asignado, Estatus, No de Orden, No de factura
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
    'vin'                   => findCol($headerRow, ['nodeserie', 'vin', 'numeroserie', 'serie', 'noserie']),
    'modelo'                => findCol($headerRow, ['modelo', 'model', 'mod']),
    'color'                 => findCol($headerRow, ['color', 'colour']),
    'anio'                  => findCol($headerRow, ['añomodelo', 'aniomodelo', 'año', 'anio', 'ao', 'year']),
    'num_motor'             => findCol($headerRow, ['nodemotor', 'nomotor', 'motor', 'nummotor']),
    'potencia'              => findCol($headerRow, ['potenciadelmotor', 'potencia', 'watts', 'power']),
    'posicion_inventario'   => findCol($headerRow, ['posicioneninventario', 'posicion', 'ubicacion']),
    'fecha_ingreso_pais'    => findCol($headerRow, ['fechadeentradaalpais', 'fechaingresopais', 'fechaentradapais']),
    'aduana'                => findCol($headerRow, ['puertodeentrada', 'aduana', 'puerto']),
    'num_pedimento'         => findCol($headerRow, ['nodepedimento', 'nopedimento', 'pedimento', 'numpedimento']),
    'cedis_origen'          => findCol($headerRow, ['cedisorigen', 'cedis', 'almacen']),
    'fecha_entrada_almacen' => findCol($headerRow, ['fechaentradaalmacen', 'fechaalmacen', 'entradaalmacen']),
    'fecha_salida_almacen'  => findCol($headerRow, ['fechasalidaalmacen', 'salidaalmacen']),
    'punto_nombre'          => findCol($headerRow, ['puntoaliadoentregaasignado', 'puntoaliado', 'punto', 'puntoentrega']),
    'estado'                => findCol($headerRow, ['estatus', 'estado', 'status']),
    'pedido_num'            => findCol($headerRow, ['nodeorden', 'noorden', 'orden', 'pedido']),
    'num_factura'           => findCol($headerRow, ['nodefactura', 'nofactura', 'factura', 'numfactura']),
    'hecho_en'              => findCol($headerRow, ['hechoen', 'madein', 'origen', 'pais', 'país']),
    'notas'                 => findCol($headerRow, ['notas', 'notes', 'observaciones', 'nota']),
];

if ($colMap['vin'] === null) {
    adminJsonOut(['error' => 'No se encontró la columna VIN / No de serie en el encabezado. Columnas detectadas: ' . implode(', ', $rows[0])], 400);
}

// ── Modelo name normalization ───────────────────────────────────────────
function normalizeModelo(string $raw): string {
    $raw = trim($raw);
    // "Voltika Tromox M05" / "Volrika Tromox MC10" → extract last token as model
    if (preg_match('/\b(M\d+|MC\d+|Ukko\s*S\+?)\s*$/i', $raw, $m)) {
        return strtoupper(trim($m[1]));
    }
    return $raw;
}

// ── Date parsing helper ─────────────────────────────────────────────────
function parseDate(string $val): ?string {
    $val = trim($val);
    if ($val === '' || $val === '0') return null;
    // Try m/d/yy or m/d/yyyy
    $ts = strtotime($val);
    if ($ts) return date('Y-m-d', $ts);
    return null;
}

// ── Insert ───────────────────────────────────────────────────────────────
$pdo = getDB();

// Ensure new columns exist (safe migration)
foreach ([
    "ALTER TABLE inventario_motos ADD COLUMN num_factura VARCHAR(50) NULL",
    "ALTER TABLE inventario_motos ADD COLUMN posicion_inventario VARCHAR(20) NULL",
    "ALTER TABLE inventario_motos ADD COLUMN fecha_entrada_almacen DATE NULL",
    "ALTER TABLE inventario_motos ADD COLUMN fecha_salida_almacen DATE NULL",
    "ALTER TABLE inventario_motos ADD COLUMN num_motor VARCHAR(50) NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $ignored) {}
}

$stmtChk = $pdo->prepare("SELECT id FROM inventario_motos WHERE vin = ? LIMIT 1");
$stmtIns = $pdo->prepare("INSERT INTO inventario_motos
    (vin, vin_display, modelo, color, estado, anio_modelo, hecho_en, notas,
     num_motor, potencia, posicion_inventario, fecha_ingreso_pais, aduana,
     num_pedimento, num_factura, cedis_origen, fecha_entrada_almacen,
     fecha_salida_almacen, punto_nombre, pedido_num, log_estados)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$created = 0;
$duplicados = 0;
$errores = 0;
$detalle = [];

function getVal(array $row, ?int $idx): string {
    if ($idx === null || $idx < 0 || !isset($row[$idx])) return '';
    return trim($row[$idx]);
}

for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $vin = getVal($row, $colMap['vin']);
    if ($vin === '') { $errores++; continue; }

    // Check duplicate
    $stmtChk->execute([$vin]);
    if ($stmtChk->fetch()) {
        $duplicados++;
        $detalle[] = "Fila " . ($i + 1) . ": VIN $vin ya existe";
        continue;
    }

    $modelo    = normalizeModelo(getVal($row, $colMap['modelo']));
    $color     = strtolower(trim(getVal($row, $colMap['color'])));
    $anio      = getVal($row, $colMap['anio']) ?: date('Y');
    $hecho     = getVal($row, $colMap['hecho_en']) ?: 'China';
    $notas     = getVal($row, $colMap['notas']);
    $numMotor  = getVal($row, $colMap['num_motor']);
    $potencia  = getVal($row, $colMap['potencia']);
    $posicion  = getVal($row, $colMap['posicion_inventario']);
    $fechaIng  = parseDate(getVal($row, $colMap['fecha_ingreso_pais']));
    $aduana    = getVal($row, $colMap['aduana']);
    $pedimento = getVal($row, $colMap['num_pedimento']);
    $factura   = getVal($row, $colMap['num_factura']);
    $cedis     = getVal($row, $colMap['cedis_origen']);
    $fEntAlm   = parseDate(getVal($row, $colMap['fecha_entrada_almacen']));
    $fSalAlm   = parseDate(getVal($row, $colMap['fecha_salida_almacen']));
    $punto     = getVal($row, $colMap['punto_nombre']);
    $pedido    = getVal($row, $colMap['pedido_num']);
    $vinDisp   = strtoupper($vin);

    // Imported motos go directly to 'recibida' (in stock)
    $estado = 'recibida';

    $log = json_encode([[
        'estado'  => $estado,
        'accion'  => 'importacion_excel',
        'dealer'  => 'sistema',
        'timestamp' => date('Y-m-d H:i:s'),
        'notas'   => 'Importado desde archivo: ' . $file['name'],
    ]], JSON_UNESCAPED_UNICODE);

    try {
        $stmtIns->execute([
            $vin, $vinDisp, $modelo, $color, $estado, $anio, $hecho, $notas,
            $numMotor ?: null, $potencia ?: null, $posicion ?: null,
            $fechaIng, $aduana ?: null, $pedimento ?: null, $factura ?: null,
            $cedis ?: null, $fEntAlm, $fSalAlm, $punto ?: null,
            $pedido ?: null, $log,
        ]);

        // Auto-create completed checklist_origen so moto shows as available
        $motoId = (int)$pdo->lastInsertId();
        try {
            $pdo->prepare("INSERT INTO checklist_origen (moto_id, dealer_id, vin, modelo, color, completado, bloqueado, hash_registro)
                VALUES (?, ?, ?, ?, ?, 1, 1, ?)")
                ->execute([$motoId, $uid, $vin, $modelo, $color, hash('sha256', "import-$motoId-" . date('c'))]);
        } catch (Throwable $ignore) {}

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
