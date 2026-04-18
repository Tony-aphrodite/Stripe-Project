<?php
/**
 * POST (multipart) — Import puntos from CSV/XLSX (customer template v1, 2026-04-19).
 *
 * Accepts the official 32-column customer template "Puntos Voltika (version 1)".
 * Headers (first row, Spanish):
 *   Nombre del punto · Nombre del responsable · Ubicación · Dirección ·
 *   Calle y número · Codigo Postal · Colonia · Ciudad · Estado · Email ·
 *   Telefono/Whatsapp · Tipo de punto · Codigo de referido ·
 *   Codigo para Venta en Piso · Horario · Capacidad · Orden de Aparicion ·
 *   Configurador · Entrega · Exhibicion y venta · Servicio tecnico ·
 *   Prubas de Manejo · Refacciones · Latitud · Longitud · Comision de Entrega ·
 *   Venta Pesgo Plus · Venta M03 · Venta Mino B · Venta M05 · Venta Ukko S+ ·
 *   Venta MC10StreetX
 *
 * Flow: ensure schema → parse → upsert per row (match by nombre) → import model
 * commissions into punto_comisiones.
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

$pdo = getDB();

// ═══════════════════════════════════════════════════════════════════════════
// 1) Ensure schema — new columns for customer's 32-column template
// ═══════════════════════════════════════════════════════════════════════════
try {
    $cols = array_column(
        $pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    $alters = [
        'responsable_nombre' => "ALTER TABLE puntos_voltika ADD COLUMN responsable_nombre VARCHAR(200) NULL",
        'calle_numero'       => "ALTER TABLE puntos_voltika ADD COLUMN calle_numero VARCHAR(200) NULL",
        'ubicacion'          => "ALTER TABLE puntos_voltika ADD COLUMN ubicacion VARCHAR(120) NULL",
        'comision_entrega'   => "ALTER TABLE puntos_voltika ADD COLUMN comision_entrega DECIMAL(10,2) NULL DEFAULT 0",
        'svc_configurador'   => "ALTER TABLE puntos_voltika ADD COLUMN svc_configurador TINYINT(1) NOT NULL DEFAULT 0",
        'svc_entrega'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_entrega TINYINT(1) NOT NULL DEFAULT 0",
        'svc_exhibicion'     => "ALTER TABLE puntos_voltika ADD COLUMN svc_exhibicion TINYINT(1) NOT NULL DEFAULT 0",
        'svc_tecnico'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_tecnico TINYINT(1) NOT NULL DEFAULT 0",
        'svc_pruebas'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_pruebas TINYINT(1) NOT NULL DEFAULT 0",
        'svc_refacciones'    => "ALTER TABLE puntos_voltika ADD COLUMN svc_refacciones TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($alters as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try { $pdo->exec($sql); } catch (Throwable $e) { error_log("importar alter $col: " . $e->getMessage()); }
        }
    }
} catch (Throwable $e) { error_log('importar ensure schema: ' . $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 2) Parse the file
// ═══════════════════════════════════════════════════════════════════════════
$rows = [];
if ($ext === 'csv' || $ext === 'txt') {
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) adminJsonOut(['error' => 'No se pudo leer el archivo'], 500);
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    while (($line = fgetcsv($handle, 0, ',')) !== false) {
        if (count($line) === 1 && strpos($line[0], ';') !== false) {
            $line = str_getcsv($line[0], ';');
        }
        $rows[] = $line;
    }
    fclose($handle);
} else {
    $rows = parseXlsx($file['tmp_name']);
    if ($rows === false) adminJsonOut(['error' => 'No se pudo leer el archivo XLSX'], 400);
}

if (count($rows) < 2) {
    adminJsonOut(['error' => 'El archivo está vacío o no tiene datos después del encabezado'], 400);
}

// ═══════════════════════════════════════════════════════════════════════════
// 3) Map column headers → indexes (header normalization is accent/case insensitive)
// ═══════════════════════════════════════════════════════════════════════════
$headerNorm = array_map('_normHeader', $rows[0]);
function _normHeader($s) {
    $s = (string)$s;
    // Strip accents
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
    return $s;
}
function _findCol(array $headers, array $needles) {
    foreach ($needles as $n) {
        $nn = _normHeader($n);
        $idx = array_search($nn, $headers, true);
        if ($idx !== false) return $idx;
    }
    return null;
}
$colMap = [
    'nombre'          => _findCol($headerNorm, ['Nombre del punto', 'Nombre', 'nombrepunto']),
    'responsable'     => _findCol($headerNorm, ['Nombre del responsable', 'Responsable']),
    'ubicacion'       => _findCol($headerNorm, ['Ubicación', 'Ubicacion']),
    'direccion'       => _findCol($headerNorm, ['Dirección', 'Direccion']),
    'calle_numero'    => _findCol($headerNorm, ['Calle y número', 'Calle y numero', 'Calle']),
    'cp'              => _findCol($headerNorm, ['Codigo Postal', 'CP', 'Código Postal']),
    'colonia'         => _findCol($headerNorm, ['Colonia']),
    'ciudad'          => _findCol($headerNorm, ['Ciudad']),
    'estado'          => _findCol($headerNorm, ['Estado']),
    'email'           => _findCol($headerNorm, ['Email', 'Correo', 'Correo electrónico']),
    'telefono'        => _findCol($headerNorm, ['Telefono/Whatsapp', 'Teléfono', 'Telefono', 'WhatsApp', 'Tel']),
    'tipo'            => _findCol($headerNorm, ['Tipo de punto', 'Tipo']),
    'cod_referido'    => _findCol($headerNorm, ['Codigo de referido', 'Código de referido', 'Codigo referido']),
    'cod_piso'        => _findCol($headerNorm, ['Codigo para Venta en Piso', 'Código para Venta en Piso', 'Codigo piso', 'Codigo venta piso']),
    'horario'         => _findCol($headerNorm, ['Horario', 'Horarios']),
    'capacidad'       => _findCol($headerNorm, ['Capacidad']),
    'orden'           => _findCol($headerNorm, ['Orden de Aparicion', 'Orden de aparición', 'Orden']),
    'svc_configurador'=> _findCol($headerNorm, ['Configurador']),
    'svc_entrega'     => _findCol($headerNorm, ['Entrega']),
    'svc_exhibicion'  => _findCol($headerNorm, ['Exhibicion y venta', 'Exhibición y venta']),
    'svc_tecnico'     => _findCol($headerNorm, ['Servicio tecnico', 'Servicio Técnico']),
    'svc_pruebas'     => _findCol($headerNorm, ['Prubas de Manejo', 'Pruebas de Manejo']),
    'svc_refacciones' => _findCol($headerNorm, ['Refacciones']),
    'lat'             => _findCol($headerNorm, ['Latitud', 'Lat', 'Latitude']),
    'lng'             => _findCol($headerNorm, ['Longitud', 'Lng', 'Longitude', 'Lon']),
    'comision'        => _findCol($headerNorm, ['Comision de Entrega', 'Comisión de Entrega', 'Comision entrega']),
    'com_pesgo_plus'  => _findCol($headerNorm, ['Venta Pesgo Plus']),
    'com_m03'         => _findCol($headerNorm, ['Venta M03']),
    'com_mino_b'      => _findCol($headerNorm, ['Venta Mino B', 'Venta M1n0 B', 'Venta MinoB']),
    'com_m05'         => _findCol($headerNorm, ['Venta M05']),
    'com_ukko_s_plus' => _findCol($headerNorm, ['Venta Ukko S+', 'Venta UkkoS+']),
    'com_mc10'        => _findCol($headerNorm, ['Venta MC10StreetX', 'Venta MC10']),
];

if ($colMap['nombre'] === null) {
    adminJsonOut(['error' => 'No se encontró la columna "Nombre del punto". Revisa la primera fila.'], 400);
}

// ═══════════════════════════════════════════════════════════════════════════
// 4) Helpers
// ═══════════════════════════════════════════════════════════════════════════
function _cell(array $row, ?int $idx): string {
    if ($idx === null || !isset($row[$idx])) return '';
    return trim((string)$row[$idx]);
}
function _siNo(string $v): int {
    $v = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE', trim($v)));
    return in_array($v, ['si','sí','s','yes','y','1','true'], true) ? 1 : 0;
}
function _normTipo(string $raw): string {
    $v = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE', trim($raw)));
    if (strpos($v, 'voltika') !== false)          return 'center';
    if (strpos($v, 'distribuidor') !== false
     || strpos($v, 'certificado') !== false)      return 'certificado';
    if (strpos($v, 'entrega') !== false
     || strpos($v, 'punto') !== false)            return 'entrega';
    return 'entrega';
}
function _floatOrNull(string $s): ?float {
    if ($s === '') return null;
    $s = str_replace([',', ' '], ['.', ''], $s);
    return is_numeric($s) ? (float)$s : null;
}
function _intOrZero(string $s): int {
    if ($s === '') return 0;
    $s = preg_replace('/[^0-9\-]/', '', $s);
    return (int)$s;
}

// ═══════════════════════════════════════════════════════════════════════════
// 5) Resolve modelo IDs for commission mapping (once up front)
// ═══════════════════════════════════════════════════════════════════════════
$modeloIds = [];
try {
    $q = $pdo->query("SELECT id, nombre FROM modelos");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $n = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $m['nombre']));
        $modeloIds[$n] = (int)$m['id'];
    }
} catch (Throwable $e) {}
function _modeloIdFor(string $label, array $modeloIds): ?int {
    $n = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $label));
    if (isset($modeloIds[$n])) return $modeloIds[$n];
    // Fuzzy: try prefix match
    foreach ($modeloIds as $mn => $id) {
        if (strpos($mn, $n) !== false || strpos($n, $mn) !== false) return $id;
    }
    return null;
}
// Ensure punto_comisiones exists (for model commissions)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS punto_comisiones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        punto_id INT NOT NULL,
        modelo_id INT NOT NULL,
        comision_venta_pct DECIMAL(6,3) NULL,
        comision_venta_monto DECIMAL(10,2) NULL,
        UNIQUE KEY ux_punto_modelo (punto_id, modelo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ═══════════════════════════════════════════════════════════════════════════
// 6) Upsert each row
// ═══════════════════════════════════════════════════════════════════════════
$stmtByName = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre = ? LIMIT 1");
$upsertSql = "
    nombre=?, responsable_nombre=?, ubicacion=?, direccion=?, calle_numero=?,
    cp=?, colonia=?, ciudad=?, estado=?, email=?, telefono=?, tipo=?,
    codigo_electronico=?, codigo_venta=?, horarios=?, capacidad=?, orden=?,
    svc_configurador=?, svc_entrega=?, svc_exhibicion=?, svc_tecnico=?,
    svc_pruebas=?, svc_refacciones=?, lat=?, lng=?, comision_entrega=?,
    activo=1
";
$stmtIns = $pdo->prepare("INSERT INTO puntos_voltika SET $upsertSql");
$stmtUpd = $pdo->prepare("UPDATE puntos_voltika SET $upsertSql WHERE id=?");

$stmtComDel = $pdo->prepare("DELETE FROM punto_comisiones WHERE punto_id = ?");
$stmtComIns = $pdo->prepare("INSERT INTO punto_comisiones (punto_id, modelo_id, comision_venta_monto) VALUES (?,?,?)");

$created = 0; $updated = 0; $errors = 0; $detail = [];

for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $nombre = _cell($row, $colMap['nombre']);
    if ($nombre === '') {
        // Skip empty rows silently (common in Excel files)
        if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
        $errors++; $detail[] = "Fila " . ($i+1) . ": Nombre vacío"; continue;
    }

    try {
        $params = [
            $nombre,
            _cell($row, $colMap['responsable']) ?: null,
            _cell($row, $colMap['ubicacion']) ?: null,
            _cell($row, $colMap['direccion']) ?: null,
            _cell($row, $colMap['calle_numero']) ?: null,
            _cell($row, $colMap['cp']) ?: null,
            _cell($row, $colMap['colonia']) ?: null,
            _cell($row, $colMap['ciudad']) ?: null,
            _cell($row, $colMap['estado']) ?: null,
            _cell($row, $colMap['email']) ?: null,
            _cell($row, $colMap['telefono']) ?: null,
            _normTipo(_cell($row, $colMap['tipo'])),
            _cell($row, $colMap['cod_referido']) ?: null,   // codigo_electronico (web referido)
            _cell($row, $colMap['cod_piso']) ?: null,       // codigo_venta (piso)
            _cell($row, $colMap['horario']) ?: null,
            _intOrZero(_cell($row, $colMap['capacidad'])),
            _intOrZero(_cell($row, $colMap['orden'])),
            _siNo(_cell($row, $colMap['svc_configurador'])),
            _siNo(_cell($row, $colMap['svc_entrega'])),
            _siNo(_cell($row, $colMap['svc_exhibicion'])),
            _siNo(_cell($row, $colMap['svc_tecnico'])),
            _siNo(_cell($row, $colMap['svc_pruebas'])),
            _siNo(_cell($row, $colMap['svc_refacciones'])),
            _floatOrNull(_cell($row, $colMap['lat'])),
            _floatOrNull(_cell($row, $colMap['lng'])),
            _floatOrNull(_cell($row, $colMap['comision'])) ?? 0,
        ];

        $stmtByName->execute([$nombre]);
        $existing = $stmtByName->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmtUpd->execute(array_merge($params, [$existing['id']]));
            $puntoId = (int)$existing['id'];
            $updated++;
        } else {
            $stmtIns->execute($params);
            $puntoId = (int)$pdo->lastInsertId();
            $created++;
        }

        // Per-model commissions (replace existing set)
        $stmtComDel->execute([$puntoId]);
        $commissionColumns = [
            'com_pesgo_plus'  => 'Pesgo Plus',
            'com_m03'         => 'M03',
            'com_mino_b'      => 'Mino B',
            'com_m05'         => 'M05',
            'com_ukko_s_plus' => 'Ukko S+',
            'com_mc10'        => 'MC10StreetX',
        ];
        foreach ($commissionColumns as $key => $modelLabel) {
            if ($colMap[$key] === null) continue;
            $monto = _floatOrNull(_cell($row, $colMap[$key]));
            if ($monto === null || $monto <= 0) continue;
            $mid = _modeloIdFor($modelLabel, $modeloIds);
            if (!$mid) continue;
            try { $stmtComIns->execute([$puntoId, $mid, $monto]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        $errors++;
        $detail[] = "Fila " . ($i+1) . " ('$nombre'): " . $e->getMessage();
    }
}

adminLog('puntos_importar_v1', [
    'archivo'  => $file['name'],
    'creados'  => $created,
    'updated'  => $updated,
    'errores'  => $errors,
]);

adminJsonOut([
    'ok'           => true,
    'creados'      => $created,
    'actualizados' => $updated,
    'errores'      => $errors,
    'total_filas'  => count($rows) - 1,
    'detalle'      => array_slice($detail, 0, 30),
]);


// ═══════════════════════════════════════════════════════════════════════════
// XLSX parser (sparse-cell aware — respects cell references A1/B1/etc.)
// ═══════════════════════════════════════════════════════════════════════════
function parseXlsx(string $path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            // handle runs <r><t>…</t></r>
            if (isset($si->r) && count($si->r) > 0) {
                $buf = '';
                foreach ($si->r as $r) $buf .= (string)$r->t;
                $strings[] = $buf;
            } else {
                $strings[] = (string)$si->t;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) { $zip->close(); return false; }

    $sheet = new SimpleXMLElement($sheetXml);
    $rows = [];

    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $maxCol = -1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $colIdx = _xlsxColIdx($ref);
            if ($colIdx === null) continue;
            $val = (string)$c->v;
            if ((string)$c['t'] === 's' && isset($strings[(int)$val])) {
                $val = $strings[(int)$val];
            } elseif ((string)$c['t'] === 'inlineStr') {
                $val = (string)$c->is->t;
            }
            $cells[$colIdx] = $val;
            if ($colIdx > $maxCol) $maxCol = $colIdx;
        }
        // Fill gaps with empty strings up to maxCol
        if ($maxCol >= 0) {
            $padded = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $padded[] = $cells[$i] ?? '';
            }
            $rows[] = $padded;
        } else {
            $rows[] = [];
        }
    }
    $zip->close();
    return $rows;
}
function _xlsxColIdx(string $ref): ?int {
    if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) return null;
    $letters = $m[1];
    $idx = 0;
    for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1; // 0-based
}
