<?php
/**
 * GET — CDC diagnostic dump
 *
 * Shows the most recent raw CDC responses so we can see WHY the flow is
 * rejecting real customers. Schema-tolerant: inspects which columns exist
 * before querying, so older DBs don't 500 the whole page.
 *
 * Access: admin role only.
 * URL: /admin/php/ventas/cdc-diagnostico.php
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

header('Content-Type: text/html; charset=UTF-8');
$pdo = getDB();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableCols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`")->fetchAll(PDO::FETCH_COLUMN);
        return array_values($rows);
    } catch (Throwable $e) {
        return [];
    }
}

function classifyResp(?int $code, ?string $resp): string {
    $r = (string)$resp;
    if ($code === null || $code === 0) return 'timeout/no_response';
    if ($code === 503 || ($code >= 500 && $code < 600 && trim($r) === '')) return '5xx_empty (Apigee backend fail)';
    if ($code === 404 && strpos($r, '"404.1"') !== false) return '404.1 (persona no existe)';
    if ($code === 400) return '400 (schema error)';
    if ($code === 401 || $code === 403) return $code . ' (auth/signature)';
    if ($code >= 200 && $code < 300) {
        if (strpos($r, '"scores"') !== false) return '200 CON score';
        if (strpos($r, '"score"') !== false) return '200 SIN scores[]';
        return '200 (otro)';
    }
    return $code . ' (otro)';
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CDC Diagnóstico</title>';
echo '<style>
body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;max-width:1200px;margin:20px auto;padding:0 16px;color:#1a1a1a;}
h1{color:#1a3a5c;margin-bottom:4px}
h2{margin-top:26px;color:#1a3a5c;border-bottom:2px solid #039fe1;padding-bottom:4px}
.muted{color:#666;font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12.5px}
th,td{padding:7px 9px;border:1px solid #e1e4e8;text-align:left;vertical-align:top}
th{background:#f4f6f8;font-weight:700}
tr.ok     td.code{color:#0a7d2a;font-weight:700}
tr.fail   td.code{color:#b91c1c;font-weight:700}
tr.warn   td.code{color:#b45309;font-weight:700}
pre{margin:0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;white-space:pre-wrap;word-break:break-word;max-height:180px;overflow:auto;background:#fafbfc;padding:6px;border-radius:4px;border:1px solid #eaecef}
.tag{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;letter-spacing:.2px}
.tag.red{background:#fde7e7;color:#b91c1c}
.tag.green{background:#e6f6ec;color:#0a7d2a}
.tag.amber{background:#fff1d8;color:#b45309}
.summary{background:#f4f6f8;padding:14px;border-radius:8px;margin-top:8px}
.kv{display:flex;gap:10px;align-items:baseline;margin:2px 0}
.kv b{min-width:180px;color:#555}
.colnote{font-size:11px;color:#999;font-style:italic;margin:6px 0 10px}
</style></head><body>';

echo '<h1>CDC Diagnóstico</h1>';
echo '<div class="muted">Las últimas llamadas reales a Círculo de Crédito para entender por qué no se aprueba con datos reales.</div>';

// ═══════════════════════════════════════════════════════════════════════
// SECTION 1: cdc_query_log — HTTP responses
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>1. cdc_query_log (últimas 15 llamadas)</h2>';
$logCols = tableCols($pdo, 'cdc_query_log');
if (!$logCols) {
    echo '<div class="summary"><span class="tag red">Tabla no existe</span> <code>cdc_query_log</code> aún no ha sido creada — ninguna consulta a CDC se ha registrado.</div>';
} else {
    echo '<div class="colnote">Columnas detectadas: ' . h(implode(', ', $logCols)) . '</div>';

    // Only SELECT columns that actually exist
    $want = ['id','endpoint','http_code','has_sig','resp_sig_ok','body_sent','response','curl_err','freg'];
    $sel = array_values(array_intersect($want, $logCols));
    if (!in_array('id', $sel, true)) array_unshift($sel, 'id');
    $selSql = '`' . implode('`, `', $sel) . '`';

    try {
        $rows = $pdo->query("SELECT $selSql FROM cdc_query_log ORDER BY id DESC LIMIT 15")
            ->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo '<div class="summary">⚠️ La tabla <code>cdc_query_log</code> existe pero está vacía — ninguna consulta a CDC se ha registrado todavía.</div>';
        } else {
            // Quick summary counts
            $counts = [];
            foreach ($rows as $r) {
                $k = classifyResp(isset($r['http_code']) ? (int)$r['http_code'] : null, (string)($r['response'] ?? ''));
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            echo '<div class="summary"><b>Resumen de los últimos 15:</b><br>';
            foreach ($counts as $k => $n) {
                $cls = (strpos($k, '200 CON') !== false) ? 'green'
                     : (strpos($k, '5xx') !== false || strpos($k, '400') !== false ? 'red' : 'amber');
                echo '<span class="tag ' . $cls . '" style="margin:4px 6px 0 0">' . h($k) . ': ' . $n . '</span>';
            }
            echo '</div>';

            echo '<table><tr>';
            foreach ($sel as $c) echo '<th>' . h($c) . ($c === 'http_code' ? '' : '') . '</th>';
            echo '<th>Tipo</th></tr>';
            foreach ($rows as $r) {
                $code = isset($r['http_code']) ? (int)$r['http_code'] : 0;
                $kind = classifyResp($code, (string)($r['response'] ?? ''));
                $rowCls = ($code >= 200 && $code < 300 && strpos($kind, 'CON score') !== false) ? 'ok'
                        : (($code >= 500 || $code === 400) ? 'fail' : 'warn');
                echo '<tr class="' . $rowCls . '">';
                foreach ($sel as $c) {
                    $v = $r[$c] ?? '';
                    if ($c === 'http_code') {
                        echo '<td class="code">' . h($v) . '</td>';
                    } elseif ($c === 'body_sent' || $c === 'response' || $c === 'curl_err') {
                        echo '<td><pre>' . h(substr((string)$v, 0, 500)) . '</pre></td>';
                    } else {
                        echo '<td>' . h($v) . '</td>';
                    }
                }
                echo '<td>' . h($kind) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    } catch (Throwable $e) {
        echo '<div class="summary"><span class="tag red">Error SQL</span> ' . h($e->getMessage()) . '</div>';
    }
}

// ═══════════════════════════════════════════════════════════════════════
// SECTION 2: consultas_buro — parsed scores
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>2. consultas_buro (últimas 10 — score parseado)</h2>';
$buroCols = tableCols($pdo, 'consultas_buro');
if (!$buroCols) {
    echo '<div class="summary"><span class="tag red">Tabla no existe</span> <code>consultas_buro</code> aún no ha sido creada — ninguna consulta ha completado el parseo de score.</div>';
} else {
    echo '<div class="colnote">Columnas detectadas: ' . h(implode(', ', $buroCols)) . '</div>';

    $want = ['id','nombre','apellido_paterno','apellido_materno','rfc','curp','cp','fecha_nacimiento',
            'score','pago_mensual','num_cuentas','dpd90_flag','dpd_max','folio_consulta','freg'];
    $sel = array_values(array_intersect($want, $buroCols));
    if (!in_array('id', $sel, true)) array_unshift($sel, 'id');
    $selSql = '`' . implode('`, `', $sel) . '`';

    try {
        $rows = $pdo->query("SELECT $selSql FROM consultas_buro ORDER BY id DESC LIMIT 10")
            ->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo '<div class="summary">⚠️ La tabla <code>consultas_buro</code> está vacía. Combinado con el resultado de la sección 1, esto significa que <b>ninguna llamada a CDC llegó a la rama de éxito</b> (HTTP 200 con score parseable).</div>';
        } else {
            echo '<table><tr>';
            foreach ($sel as $c) echo '<th>' . h($c) . '</th>';
            echo '</tr>';
            foreach ($rows as $r) {
                echo '<tr>';
                foreach ($sel as $c) {
                    $v = $r[$c] ?? '';
                    if ($c === 'score') {
                        $scoreCls = $v !== null && $v !== '' && (int)$v >= 420 ? 'green' : (($v === null || $v === '') ? 'amber' : 'red');
                        echo '<td><span class="tag ' . $scoreCls . '">' . h($v === null || $v === '' ? 'null' : $v) . '</span></td>';
                    } elseif ($c === 'pago_mensual') {
                        echo '<td>$' . number_format((float)$v, 2) . '</td>';
                    } elseif ($c === 'dpd90_flag') {
                        echo '<td>' . ((int)$v ? 'Sí' : 'No') . '</td>';
                    } else {
                        echo '<td>' . h($v) . '</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</table>';
        }
    } catch (Throwable $e) {
        echo '<div class="summary"><span class="tag red">Error SQL</span> ' . h($e->getMessage()) . '</div>';
    }
}

// ═══════════════════════════════════════════════════════════════════════
// SECTION 3: transacciones_errores — orders that never got saved
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>3. transacciones_errores (últimas 5)</h2>';
$errCols = tableCols($pdo, 'transacciones_errores');
if ($errCols) {
    $want = ['id','email','stripe_pi','error_msg','freg'];
    $sel = array_values(array_intersect($want, $errCols));
    if (!in_array('id', $sel, true)) array_unshift($sel, 'id');
    $selSql = '`' . implode('`, `', $sel) . '`';
    try {
        $rows = $pdo->query("SELECT $selSql FROM transacciones_errores ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo '<div class="summary">✓ Sin errores recientes en transacciones_errores.</div>';
        } else {
            echo '<table><tr>';
            foreach ($sel as $c) echo '<th>' . h($c) . '</th>';
            echo '</tr>';
            foreach ($rows as $r) {
                echo '<tr>';
                foreach ($sel as $c) {
                    $v = $r[$c] ?? '';
                    if ($c === 'stripe_pi') echo '<td><code>' . h($v) . '</code></td>';
                    elseif ($c === 'error_msg') echo '<td><pre>' . h(substr((string)$v, 0, 500)) . '</pre></td>';
                    else echo '<td>' . h($v) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        }
    } catch (Throwable $e) {
        echo '<div class="summary"><span class="tag amber">transacciones_errores</span> ' . h($e->getMessage()) . '</div>';
    }
} else {
    echo '<div class="summary">Sin errores registrados (tabla no existe).</div>';
}

// ═══════════════════════════════════════════════════════════════════════
// SECTION 4: environment sanity check
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>4. Configuración actual (sanity check)</h2>';
echo '<div class="summary">';
// Force-load consultar-buro constants by intercepting the require (it defines CDC_BASE_URL)
$cdcBase = defined('CDC_BASE_URL') ? CDC_BASE_URL : null;
if ($cdcBase === null) {
    // Peek at the source without executing the whole script
    $src = @file_get_contents(__DIR__ . '/../../../configurador_prueba/php/consultar-buro.php');
    if ($src && preg_match("/define\s*\(\s*'CDC_BASE_URL'\s*,\s*getenv\('CDC_BASE_URL'\)\s*\?:\s*'([^']+)'/", $src, $m)) {
        $cdcBase = '(hardcoded fallback) ' . $m[1];
    }
    $envOverride = getenv('CDC_BASE_URL');
    if ($envOverride) $cdcBase = '(from .env) ' . $envOverride;
}
echo '<div class="kv"><b>CDC_BASE_URL</b> <code>' . h($cdcBase ?: '—') . '</code></div>';
echo '<div class="kv"><b>CDC_API_KEY</b> ' . (defined('CDC_API_KEY') && CDC_API_KEY ? '✓ definido (' . strlen(CDC_API_KEY) . ' chars)' : '<span class="tag red">NO DEFINIDO</span>') . '</div>';
echo '<div class="kv"><b>CDC_USER</b> ' . (defined('CDC_USER') && CDC_USER ? '✓ <code>' . h(CDC_USER) . '</code>' : '<span class="tag red">vacío — v2 requiere username</span>') . '</div>';
echo '<div class="kv"><b>CDC_PASS</b> ' . (defined('CDC_PASS') && CDC_PASS ? '✓ definido' : '<span class="tag red">vacío — v2 requiere password</span>') . '</div>';
echo '<div class="kv"><b>CDC_FOLIO</b> ' . h(defined('CDC_FOLIO') ? CDC_FOLIO : '(no definido)') . '</div>';

try {
    $row = $pdo->query("SELECT fingerprint, freg FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo '<div class="kv"><b>cdc_certificates.active</b> ✓ fingerprint <code>' . h(substr($row['fingerprint'], 0, 64)) . '...</code> (creado ' . h($row['freg']) . ')</div>';
    } else {
        echo '<div class="kv"><b>cdc_certificates.active</b> <span class="tag red">ninguna fila activa</span></div>';
    }
} catch (Throwable $e) {
    echo '<div class="kv"><b>cdc_certificates</b> <span class="tag amber">' . h($e->getMessage()) . '</span></div>';
}

$serverCert = __DIR__ . '/../../../configurador_prueba/php/certs/cdc_server_certificate.pem';
echo '<div class="kv"><b>cdc_server_certificate.pem</b> ' . (is_file($serverCert) ? ('✓ ' . filesize($serverCert) . ' bytes') : '<span class="tag red">falta</span>') . '</div>';
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════
// SECTION 5: Schema drift detector — what columns are MISSING
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>5. Drift de schema (columnas esperadas vs existentes)</h2>';
echo '<div class="summary">';

$expected = [
    'cdc_query_log'    => ['id','endpoint','http_code','has_sig','resp_sig_ok','body_sent','response','curl_err','freg'],
    'consultas_buro'   => ['id','nombre','apellido_paterno','apellido_materno','rfc','curp','cp','fecha_nacimiento','score','pago_mensual','num_cuentas','dpd90_flag','dpd_max','folio_consulta','freg'],
    'transacciones'    => ['id','nombre','email','stripe_pi','pago_estado','notif_sent_at','freg'],
    'cdc_certificates' => ['id','private_key','certificate','fingerprint','active','freg'],
];
foreach ($expected as $tbl => $cols) {
    $have = tableCols($pdo, $tbl);
    $missing = array_diff($cols, $have);
    if (!$have) {
        echo '<div class="kv"><b>' . h($tbl) . '</b> <span class="tag red">tabla no existe</span></div>';
    } elseif (!$missing) {
        echo '<div class="kv"><b>' . h($tbl) . '</b> ✓ ok</div>';
    } else {
        echo '<div class="kv"><b>' . h($tbl) . '</b> <span class="tag amber">faltan: ' . h(implode(', ', $missing)) . '</span></div>';
    }
}
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════
// Interpretation hint
// ═══════════════════════════════════════════════════════════════════════
echo '<h2>Interpretación rápida</h2>';
echo '<div class="summary" style="line-height:1.7">';
echo '• <b>Sección 1 vacía + Sección 2 vacía</b> → ninguna llamada a CDC se ha ejecutado aún (o la tabla log no existe). Probar una solicitud de crédito real y recargar esta página.<br>';
echo '• <b>http_code = 503 repetido con body vacío</b> → Apigee no contacta el backend (cert/mTLS/producto aún no propagado).<br>';
echo '• <b>http_code = 400</b> → el body no matchea schema del producto /v2/rccficoscore.<br>';
echo '• <b>http_code = 200 pero consultas_buro sin fila</b> → el parser <code>extractPreaprobacionData()</code> no encontró el score en la estructura devuelta.<br>';
echo '• <b>http_code = 404 + 404.1</b> → CDC no encuentra a la persona (revisar RFC/CURP/fecha_nac que enviamos).<br>';
echo '• <b>Sección 5 muestra columnas faltantes</b> → auto-migraciones no corrieron; ejecutar una petición real las dispara (via idempotent <code>ALTER TABLE</code>).';
echo '</div>';

echo '<p style="margin-top:30px;font-size:12px;color:#999">Eliminar este archivo después del diagnóstico.</p>';
echo '</body></html>';
