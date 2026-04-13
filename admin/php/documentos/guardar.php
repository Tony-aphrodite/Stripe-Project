<?php
/**
 * POST — Create or update a document record for a customer
 * Body: { cliente_id, tipo, archivo_url, archivo_nombre, estado, notas }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$clienteId = (int)($d['cliente_id'] ?? 0);
$tipo = trim($d['tipo'] ?? '');

if (!$clienteId) adminJsonOut(['error' => 'cliente_id requerido'], 400);
if (!$tipo) adminJsonOut(['error' => 'tipo de documento requerido'], 400);

$validTypes = ['contrato','acta_entrega','factura','carta_factura','seguro','ine','pagare'];
if (!in_array($tipo, $validTypes)) adminJsonOut(['error' => 'Tipo de documento no válido'], 400);

$pdo = getDB();

$id = (int)($d['id'] ?? 0);
$fields = [
    'cliente_id'     => $clienteId,
    'tipo'           => $tipo,
    'archivo_url'    => trim($d['archivo_url'] ?? ''),
    'archivo_nombre' => trim($d['archivo_nombre'] ?? ''),
    'estado'         => $d['estado'] ?? 'subido',
    'notas'          => trim($d['notas'] ?? ''),
    'subido_por'     => $uid,
];

if ($id) {
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "$k=?"; $vals[] = $v; }
    $vals[] = $id;
    $pdo->prepare("UPDATE documentos_cliente SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    adminLog('documento_actualizado', ['id' => $id, 'tipo' => $tipo, 'cliente_id' => $clienteId]);
    adminJsonOut(['ok' => true, 'id' => $id]);
} else {
    $cols = implode(',', array_keys($fields));
    $ph = implode(',', array_fill(0, count($fields), '?'));
    $pdo->prepare("INSERT INTO documentos_cliente ({$cols}) VALUES ({$ph})")->execute(array_values($fields));
    $newId = (int)$pdo->lastInsertId();
    adminLog('documento_creado', ['id' => $newId, 'tipo' => $tipo, 'cliente_id' => $clienteId]);
    adminJsonOut(['ok' => true, 'id' => $newId]);
}
