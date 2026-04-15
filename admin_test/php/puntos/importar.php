<?php
/**
 * POST (multipart) — Import delivery points from CSV/Excel file
 * Expects file upload field: "archivo"
 * Accepts .csv and .xlsx
 *
 * Expected columns (first row = header, order auto-detected):
 *   Nombre, Tipo, Dirección, Colonia, Ciudad, Estado, CP,
 *   Teléfono, Email, Latitud, Longitud, Horarios, Capacidad, Descripción
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

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
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    while (($line = fgetcsv($handle, 0, ',')) !== false) {
        if (count($line) === 1 && strpos($line[0], ';') !== false) {
            $line = str_getcsv($line[0], ';');
        }
        $rows[] = $line;
    }
    fclose($handle);
} elseif ($ext === 'xlsx') {
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
    'nombre'      => findCol($headerRow, ['nombre', 'name', 'nombrepunto', 'punto']),
    'tipo'        => findCol($headerRow, ['tipo', 'type', 'tipopunto']),
    'direccion'   => findCol($headerRow, ['direccion', 'dirección', 'address', 'calle', 'domicilio']),
    'colonia'     => findCol($headerRow, ['colonia', 'neighborhood', 'barrio']),
    'ciudad'      => findCol($headerRow, ['ciudad', 'city', 'municipio']),
    'estado'      => findCol($headerRow, ['estado', 'state', 'entidad', 'entidadfederativa']),
    'cp'          => findCol($headerRow, ['cp', 'codigopostal', 'zipcode', 'zip', 'códigopostal']),
    'telefono'    => findCol($headerRow, ['telefono', 'teléfono', 'phone', 'tel']),
    'email'       => findCol($headerRow, ['email', 'correo', 'correoelectronico']),
    'lat'         => findCol($headerRow, ['lat', 'latitud', 'latitude']),
    'lng'         => findCol($headerRow, ['lng', 'longitud', 'longitude', 'lon']),
    'horarios'    => findCol($headerRow, ['horarios', 'horario', 'hours', 'schedule']),
    'capacidad'   => findCol($headerRow, ['capacidad', 'capacity', 'cap']),
    'descripcion' => findCol($headerRow, ['descripcion', 'descripción', 'description', 'notas', 'notes']),
];

if ($colMap['nombre'] === null) {
    adminJsonOut(['error' => 'No se encontró la columna Nombre en el encabezado. Columnas detectadas: ' . implode(', ', $rows[0])], 400);
}

// ── Insert ───────────────────────────────────────────────────────────────
$pdo = getDB();

// Generate unique codigo_venta for punto
function generateCodigoVenta(PDO $pdo, string $nombre): string {
    $base = 'PV-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $nombre), 0, 4));
    $code = $base . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
    // Ensure unique
    $chk = $pdo->prepare("SELECT id FROM puntos_voltika WHERE codigo_venta = ? LIMIT 1");
    $chk->execute([$code]);
    while ($chk->fetch()) {
        $code = $base . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
        $chk->execute([$code]);
    }
    return $code;
}

function generateCodigoElectronico(PDO $pdo, string $nombre): string {
    $base = 'PE-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $nombre), 0, 4));
    $code = $base . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
    $chk = $pdo->prepare("SELECT id FROM puntos_voltika WHERE codigo_electronico = ? LIMIT 1");
    $chk->execute([$code]);
    while ($chk->fetch()) {
        $code = $base . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
        $chk->execute([$code]);
    }
    return $code;
}

// Normalize tipo value
function normalizeTipo(string $raw): string {
    $raw = strtolower(trim($raw));
    $map = [
        'center' => 'center', 'centro' => 'center', 'voltika' => 'center',
        'certificado' => 'certificado', 'cert' => 'certificado', 'autorizado' => 'certificado',
        'entrega' => 'entrega', 'delivery' => 'entrega', 'punto' => 'entrega',
    ];
    return $map[$raw] ?? 'entrega';
}

$stmtChk = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre = ? AND cp = ? LIMIT 1");
$stmtIns = $pdo->prepare("INSERT INTO puntos_voltika
    (nombre, tipo, direccion, colonia, ciudad, estado, cp,
     telefono, email, lat, lng, horarios, capacidad, descripcion,
     activo, codigo_venta, codigo_electronico)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");

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
    $nombre = getVal($row, $colMap['nombre']);
    if ($nombre === '') { $errores++; $detalle[] = "Fila " . ($i + 1) . ": Nombre vacío"; continue; }

    $cp = getVal($row, $colMap['cp']);

    // Check duplicate by nombre + cp
    $stmtChk->execute([$nombre, $cp]);
    if ($stmtChk->fetch()) {
        $duplicados++;
        $detalle[] = "Fila " . ($i + 1) . ": '$nombre' (CP: $cp) ya existe";
        continue;
    }

    $tipo       = normalizeTipo(getVal($row, $colMap['tipo']));
    $direccion  = getVal($row, $colMap['direccion']);
    $colonia    = getVal($row, $colMap['colonia']);
    $ciudad     = getVal($row, $colMap['ciudad']);
    $estado     = getVal($row, $colMap['estado']);
    $telefono   = getVal($row, $colMap['telefono']);
    $email      = getVal($row, $colMap['email']);
    $lat        = getVal($row, $colMap['lat']) !== '' ? (float)getVal($row, $colMap['lat']) : null;
    $lng        = getVal($row, $colMap['lng']) !== '' ? (float)getVal($row, $colMap['lng']) : null;
    $horarios   = getVal($row, $colMap['horarios']);
    $capacidad  = getVal($row, $colMap['capacidad']) !== '' ? (int)getVal($row, $colMap['capacidad']) : 0;
    $descripcion = getVal($row, $colMap['descripcion']);

    $codVenta = generateCodigoVenta($pdo, $nombre);
    $codElec  = generateCodigoElectronico($pdo, $nombre);

    try {
        $stmtIns->execute([
            $nombre, $tipo, $direccion ?: null, $colonia ?: null,
            $ciudad ?: null, $estado ?: null, $cp ?: null,
            $telefono ?: null, $email ?: null, $lat, $lng,
            $horarios ?: null, $capacidad, $descripcion ?: null,
            $codVenta, $codElec,
        ]);
        $created++;
    } catch (Throwable $e) {
        $errores++;
        $detalle[] = "Fila " . ($i + 1) . ": " . $e->getMessage();
    }
}

adminLog('puntos_importar', [
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

    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            $strings[] = (string)$si->t ?: (string)$si;
        }
    }

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
