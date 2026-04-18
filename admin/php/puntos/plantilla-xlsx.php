<?php
/**
 * GET — Download XLSX template for bulk punto import.
 * Same columns as CSV template; built as a minimal XLSX (ZIP of XML) without libraries.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$headers = ['Acción','Nombre','Tipo','Dirección','Colonia','Ciudad','Estado','CP','Teléfono','Email','Latitud','Longitud','Horarios','Capacidad','Descripción'];
$rows = [
    ['agregar','Punto Ejemplo','entrega','Av. Reforma 123','Juárez','Ciudad de México','CDMX','06600','5551234567','punto@ejemplo.com','19.4326','-99.1332','Lun-Vie 9:00-18:00','20','Punto de entrega ejemplo'],
    ['actualizar','Punto Existente','center','Blvd. Centro 456','Centro','Querétaro','QRO','76000','4421234567','centro@ejemplo.com','20.5881','-100.3899','Lun-Sab 10:00-20:00','50','Centro Voltika actualizado'],
    ['eliminar','Punto A Eliminar','','','','','','76060','','','','','','',''],
];

$all = array_merge([$headers], $rows);

// Build shared strings
$strings = [];
foreach ($all as $r) {
    foreach ($r as $c) {
        if ($c !== '' && !isset($strings[$c])) {
            $strings[$c] = count($strings);
        }
    }
}

function xmlEsc($s) {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
foreach ($strings as $s => $i) {
    $ssXml .= '<si><t xml:space="preserve">' . xmlEsc($s) . '</t></si>';
}
$ssXml .= '</sst>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
$rowIdx = 1;
foreach ($all as $r) {
    $sheetXml .= '<row r="' . $rowIdx . '">';
    $colIdx = 0;
    foreach ($r as $c) {
        $colLetter = chr(65 + $colIdx);
        if ($c === '' || $c === null) {
            // skip empty
        } else {
            $idx = $strings[$c];
            $sheetXml .= '<c r="' . $colLetter . $rowIdx . '" t="s"><v>' . $idx . '</v></c>';
        }
        $colIdx++;
    }
    $sheetXml .= '</row>';
    $rowIdx++;
}
$sheetXml .= '</sheetData></worksheet>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '</Types>';

$relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Puntos" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '</Relationships>';

// Build XLSX (ZIP)
$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $relsXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('xl/sharedStrings.xml', $ssXml);
$zip->close();

// Send to browser
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="plantilla_puntos.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;
