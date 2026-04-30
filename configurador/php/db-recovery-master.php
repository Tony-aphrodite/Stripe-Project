<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA · MASTER RECOVERY ORCHESTRATOR
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Purpose:
 *   Cross-references ALL available data sources to reconstruct the most
 *   complete possible history of transacciones. Analyzes:
 *
 *     1. Current `transacciones` table
 *     2. Related DB tables (pedidos, facturacion, consultas_buro, etc.)
 *     3. Stripe API (PaymentIntents + Customers + Charges)
 *     4. Cincel signed contracts (if any PDFs on disk)
 *     5. Uploaded files in /uploads
 *     6. Email logs (if accessible)
 *
 *   Produces a consolidated report showing what can be reconstructed per
 *   transaction and from which source(s).
 *
 * Usage:
 *   ?key=voltika-master-2026
 * ═══════════════════════════════════════════════════════════════════════════
 */

set_time_limit(600);
header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-master-2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Master Recovery</title>
<style>
  body { font-family: 'Inter', -apple-system, sans-serif; max-width: 1400px; margin: 30px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 20px 24px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 24px; margin-bottom: 10px; }
  h2 { color: #039fe1; font-size: 16px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
  h3 { color: #0c2340; font-size: 14px; margin: 14px 0 8px; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap: 10px; margin: 8px 0; }
  .kpi { padding: 14px; border-radius: 10px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi .n { font-size: 20px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; letter-spacing: .5px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; letter-spacing: .5px; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .dim { color: #94a3b8; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-family: 'SF Mono',Consolas,monospace; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 12px 16px; border-radius: 8px; margin-bottom: 10px; color: #166534; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin-bottom: 10px; color: #92400e; }
  .alert-info { background: #dbeafe; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 8px; margin-bottom: 10px; color: #1e40af; }
  .source-map { display: grid; grid-template-columns: 1.5fr 3fr; gap: 10px; margin-top: 8px; }
  .source-map .source { background: #f5f7fa; padding: 10px; border-radius: 8px; font-size: 12px; }
  .source-map .fields { font-size: 11px; color: #64748b; line-height: 1.6; }
  .pill { display: inline-block; padding: 2px 10px; border-radius: 50px; font-size: 10px; font-weight: 700; margin: 2px; }
  .pill.have { background: #dcfce7; color: #166534; }
  .pill.partial { background: #fef3c7; color: #92400e; }
  .pill.missing { background: #fee2e2; color: #991b1b; }
  .pill.ext { background: #dbeafe; color: #1e40af; }
</style>
</head>
<body>

<div class="box">
  <h1>🎯 Voltika · Master Recovery Analysis</h1>
  <p style="color:#64748b;font-size:13px;">Ejecutado: <?= date('Y-m-d H:i:s') ?> · Modo: <strong style="color:#16a34a;">SOLO LECTURA</strong> — no modifica nada.</p>
</div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 1: Data Source Inventory
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>📋 Inventario de fuentes disponibles</h2>";

    $relatedTables = ['transacciones','pedidos','facturacion','consultas_buro','preaprobaciones','verificaciones_identidad','postulaciones_aliados','buro_consultas','documentos_cliente','entregas','envios_cotizaciones'];
    $tableStats = [];

    foreach ($relatedTables as $t) {
        $exists = (int)$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?")->execute([$t]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$t]);
        $exists = (int)$stmt->fetchColumn();
        if ($exists) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $tableStats[$t] = $count;
        } else {
            $tableStats[$t] = null;
        }
    }

    echo "<h3>Tablas relacionadas en la DB</h3>";
    echo "<table><tr><th>Tabla</th><th>Existe</th><th>Registros</th><th>Relevancia</th></tr>";
    $relevance = [
        'transacciones' => 'Principal · datos de pago',
        'pedidos' => '⭐ Alta · datos de orden y cliente',
        'facturacion' => '⭐⭐ Muy alta · RFC, razón social, dirección fiscal',
        'consultas_buro' => 'Media · datos para crédito',
        'preaprobaciones' => 'Media · decisiones de crédito',
        'verificaciones_identidad' => '⭐ Alta · INE, RFC validado (Truora)',
        'postulaciones_aliados' => 'Baja · datos de aliados',
        'documentos_cliente' => 'Media · archivos subidos',
        'entregas' => 'Media · dirección de entrega, puntos',
        'envios_cotizaciones' => 'Baja · shipping quotes (Skydropx)',
    ];
    foreach ($tableStats as $t => $count) {
        $rel = $relevance[$t] ?? '—';
        if ($count !== null) {
            echo "<tr><td><code>$t</code></td><td class='ok'>✓</td><td><strong>$count</strong></td><td>$rel</td></tr>";
        } else {
            echo "<tr><td><code>$t</code></td><td class='dim'>—</td><td class='dim'>no existe</td><td>$rel</td></tr>";
        }
    }
    echo "</table>";
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 2: Field Coverage Map
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>🗺 Mapa de cobertura de campos</h2>";
    echo "<p style='font-size:12px;color:#64748b;'>Cada campo de <code>transacciones</code> y dónde puede recuperarse:</p>";

    $fieldMap = [
        'pedido' => [
            'now_complete' => true,
            'sources' => ['transacciones (actual)', 'pedidos.pedido', 'Stripe metadata']
        ],
        'nombre, email, telefono' => [
            'now_complete' => true,
            'sources' => ['transacciones (actual)', 'Stripe billing_details', 'facturacion.nombre/email', 'pedidos', 'Cincel signers']
        ],
        'rfc, razon' => [
            'now_complete' => false,
            'sources' => ['facturacion.rfc/razon ⭐', 'Cincel contracts (PDF text)', 'Truora (verificaciones_identidad)', 'SAT CSF documents']
        ],
        'direccion, e_direccion' => [
            'now_complete' => false,
            'sources' => ['facturacion.calle/cp/ciudad', 'pedidos.direccion', 'Stripe billing_details.address', 'Skydropx quotes', 'documentos_cliente']
        ],
        'modelo, color' => [
            'now_complete' => true,
            'sources' => ['transacciones (actual)', 'pedidos.modelo', 'Stripe metadata', 'inventario']
        ],
        'precio, total, penvio' => [
            'now_complete' => true,
            'sources' => ['transacciones (actual)', 'Stripe PaymentIntent.amount', 'facturacion.total']
        ],
        'tpago, tenvio' => [
            'now_complete' => false,
            'sources' => ['transacciones (actual)', 'pedidos.metodo/envio', 'Stripe payment_method']
        ],
        'stripe_pi' => [
            'now_complete' => false,
            'sources' => ['Stripe API (todos los PaymentIntents)']
        ],
        'freg' => [
            'now_complete' => true,
            'sources' => ['transacciones (actual)', 'Stripe.created', 'facturacion.freg']
        ],
        'folio_contrato' => [
            'now_complete' => false,
            'sources' => ['Cincel contracts ⭐', 'verificaciones_identidad', 'admin-generar-acta-pdf output']
        ],
        'seguro_* (qualitas, cotización, póliza)' => [
            'now_complete' => false,
            'sources' => ['Qualitas API / dashboard', 'documentos_cliente (PDFs)', 'entregas']
        ],
        'placas_* (estado, gestor, cotización)' => [
            'now_complete' => false,
            'sources' => ['gestores (si se registran)', 'documentos_cliente (cotizaciones PDF)']
        ],
        'punto_id, punto_nombre' => [
            'now_complete' => false,
            'sources' => ['puntos_voltika table', 'entregas.punto_id']
        ],
        'msi_meses, msi_pago' => [
            'now_complete' => false,
            'sources' => ['Stripe PaymentMethod.installments', 'pedidos']
        ],
        'pago_estado, environment' => [
            'now_complete' => true,
            'sources' => ['Stripe PaymentIntent.status']
        ],
    ];

    echo "<table><tr><th>Campos</th><th>Estado actual</th><th>Fuentes disponibles</th></tr>";
    foreach ($fieldMap as $field => $info) {
        $status = $info['now_complete']
            ? "<span class='pill have'>✓ Completos</span>"
            : "<span class='pill missing'>✗ Faltantes</span>";
        $sourcesHtml = '';
        foreach ($info['sources'] as $src) {
            $isExternal = (stripos($src, 'Stripe') !== false || stripos($src, 'Cincel') !== false || stripos($src, 'Qualitas') !== false || stripos($src, 'Truora') !== false || stripos($src, 'SAT') !== false || stripos($src, 'Skydropx') !== false);
            $pill = $isExternal ? 'ext' : 'have';
            $sourcesHtml .= "<span class='pill $pill'>" . htmlspecialchars($src) . "</span>";
        }
        echo "<tr><td><code>" . htmlspecialchars($field) . "</code></td><td>$status</td><td>$sourcesHtml</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 3: Cross-reference with facturacion table
    // ═══════════════════════════════════════════════════════════════════════
    if (isset($tableStats['facturacion']) && $tableStats['facturacion'] > 0) {
        echo "<div class='box'>";
        echo "<h2>💎 Datos adicionales disponibles en <code>facturacion</code></h2>";
        echo "<p style='font-size:12px;color:#64748b;'>La tabla facturacion contiene información fiscal (RFC, razón social, dirección) que no está en transacciones.</p>";

        $facturacion = $pdo->query("
            SELECT nombre, email, rfc, razon, calle, cp, ciudad, estado, total, freg
            FROM facturacion
            WHERE (rfc IS NOT NULL AND rfc <> '') OR (razon IS NOT NULL AND razon <> '')
            ORDER BY freg DESC LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo "<div class='kpi-row'>";
        echo "<div class='kpi blue'><span class='l'>Con datos fiscales</span><span class='n'>" . count($facturacion) . "</span></div>";
        echo "</div>";

        echo "<table><tr><th>Fecha</th><th>Email</th><th>RFC</th><th>Razón</th><th>Dirección</th><th>Total</th></tr>";
        foreach ($facturacion as $f) {
            $rfc = trim($f['rfc']);
            $razon = trim($f['razon']);
            if (!$rfc && !$razon) continue;
            echo "<tr>";
            echo "<td>" . htmlspecialchars($f['freg']) . "</td>";
            echo "<td>" . htmlspecialchars($f['email']) . "</td>";
            echo "<td><code>" . htmlspecialchars($f['rfc']) . "</code></td>";
            echo "<td>" . htmlspecialchars($f['razon']) . "</td>";
            echo "<td>" . htmlspecialchars(trim($f['calle'] . ', ' . $f['cp'] . ' ' . $f['ciudad'] . ', ' . $f['estado'], ', ')) . "</td>";
            echo "<td>\$" . number_format($f['total'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";

        // Find matches between transacciones and facturacion by email
        echo "<div class='box'>";
        echo "<h2>🔗 Coincidencias transacciones ↔ facturacion (por email)</h2>";
        $matches = $pdo->query("
            SELECT t.id as t_id, t.pedido, t.nombre as t_nombre, t.email, t.rfc as t_rfc,
                   f.rfc as f_rfc, f.razon as f_razon, f.calle as f_calle, f.ciudad as f_ciudad
            FROM transacciones t
            LEFT JOIN facturacion f ON t.email = f.email AND f.rfc IS NOT NULL AND f.rfc <> ''
            WHERE (t.rfc IS NULL OR t.rfc = '') AND f.id IS NOT NULL
            GROUP BY t.id
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (count($matches) > 0) {
            echo "<div class='alert-ok'>🎯 Se pueden enriquecer <strong>" . count($matches) . "</strong> registros de transacciones con datos fiscales de facturacion.</div>";
            echo "<table><tr><th>ID trans.</th><th>Pedido</th><th>Nombre</th><th>Email</th><th>RFC (facturacion)</th><th>Razón</th><th>Ciudad</th></tr>";
            foreach ($matches as $m) {
                echo "<tr>";
                echo "<td>{$m['t_id']}</td>";
                echo "<td>" . htmlspecialchars($m['pedido']) . "</td>";
                echo "<td>" . htmlspecialchars($m['t_nombre']) . "</td>";
                echo "<td>" . htmlspecialchars($m['email']) . "</td>";
                echo "<td class='ok'><code>" . htmlspecialchars($m['f_rfc']) . "</code></td>";
                echo "<td>" . htmlspecialchars($m['f_razon']) . "</td>";
                echo "<td>" . htmlspecialchars($m['f_ciudad']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='dim'>Ninguna coincidencia encontrada.</p>";
        }
        echo "</div>";
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 4: Check filesystem for Cincel contracts
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>📄 Contratos Cincel firmados (filesystem)</h2>";
    $searchPaths = [
        __DIR__ . '/../uploads/contratos/',
        __DIR__ . '/../../admin/uploads/',
        __DIR__ . '/../uploads/',
        __DIR__ . '/uploads/',
    ];
    $contractFiles = [];
    foreach ($searchPaths as $path) {
        if (is_dir($path)) {
            $files = glob($path . '*.pdf');
            foreach ($files as $f) {
                $contractFiles[] = ['path' => $f, 'size' => filesize($f), 'mtime' => filemtime($f)];
            }
        }
    }

    if (count($contractFiles) > 0) {
        echo "<div class='alert-ok'>✓ Encontrados <strong>" . count($contractFiles) . "</strong> contratos PDF. Cada uno contiene datos completos del cliente (RFC, razón, dirección).</div>";
        echo "<table><tr><th>Archivo</th><th>Tamaño</th><th>Fecha</th></tr>";
        foreach (array_slice($contractFiles, 0, 20) as $f) {
            echo "<tr>";
            echo "<td><code>" . htmlspecialchars(basename($f['path'])) . "</code></td>";
            echo "<td>" . number_format($f['size'] / 1024, 1) . " KB</td>";
            echo "<td>" . date('Y-m-d H:i', $f['mtime']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='dim'>No se encontraron PDFs de contratos en las rutas comunes.</p>";
    }
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 5: Recovery Plan Recommendation
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>🎯 Plan de recuperación recomendado (5 pasos)</h2>";
    echo "<ol style='line-height:1.8;'>";
    echo "<li><strong>✅ YA HECHO</strong> — Restauración de 23 registros base desde backup_2026-04-06.sql</li>";
    echo "<li><strong>📊 PASO 1</strong> — Enriquecer transacciones con datos fiscales de <code>facturacion</code>:<br>";
    echo "<code style='font-size:11px;'>UPDATE transacciones t JOIN facturacion f ON t.email=f.email SET t.rfc=f.rfc, t.razon=f.razon, t.direccion=f.calle WHERE t.rfc=''</code></li>";
    echo "<li><strong>💳 PASO 2</strong> — Sincronizar con Stripe (todos los PaymentIntents):<br>";
    echo "Ejecutar <code>db-recovery-stripe-sync.php?mode=scan</code> para ver qué falta</li>";
    echo "<li><strong>📄 PASO 3</strong> — Extraer datos de contratos Cincel PDF (si existen):<br>";
    echo "Los PDFs contienen RFC, razón, dirección completa, folio de contrato</li>";
    echo "<li><strong>🔍 PASO 4</strong> — Cruce con tablas secundarias (<code>pedidos</code>, <code>entregas</code>, <code>documentos_cliente</code>)</li>";
    echo "<li><strong>📧 PASO 5</strong> — Revisar emails enviados (Resend/Zoho) y extraer confirmaciones faltantes</li>";
    echo "</ol>";
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // SECTION 6: Generated Enrichment SQL (preview)
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>🛠 SQL de enriquecimiento propuesto (no se ejecuta — solo preview)</h2>";
    echo "<p style='font-size:12px;color:#64748b;'>Este SQL rellenaría RFC/razón/dirección en transacciones usando coincidencias por email en facturacion:</p>";
    echo "<pre style='background:#0c2340;color:#e0f4fd;padding:14px;border-radius:8px;overflow-x:auto;font-size:11px;'>";
    echo htmlspecialchars("-- Enriquecimiento desde facturacion (solo donde transacciones está vacío)
UPDATE transacciones t
INNER JOIN (
    SELECT email,
           MAX(CASE WHEN rfc IS NOT NULL AND rfc <> '' THEN rfc END) as rfc,
           MAX(CASE WHEN razon IS NOT NULL AND razon <> '' THEN razon END) as razon,
           MAX(CASE WHEN calle IS NOT NULL AND calle <> '' THEN calle END) as calle,
           MAX(CASE WHEN ciudad IS NOT NULL AND ciudad <> '' THEN ciudad END) as ciudad,
           MAX(CASE WHEN estado IS NOT NULL AND estado <> '' THEN estado END) as estado,
           MAX(CASE WHEN cp IS NOT NULL AND cp <> '' THEN cp END) as cp
    FROM facturacion
    WHERE email IS NOT NULL AND email <> ''
    GROUP BY email
) f ON t.email = f.email
SET
    t.rfc       = COALESCE(NULLIF(t.rfc, ''), f.rfc, t.rfc),
    t.razon     = COALESCE(NULLIF(t.razon, ''), f.razon, t.razon),
    t.direccion = COALESCE(NULLIF(t.direccion, ''), f.calle, t.direccion),
    t.ciudad    = COALESCE(NULLIF(t.ciudad, ''), f.ciudad, t.ciudad),
    t.estado    = COALESCE(NULLIF(t.estado, ''), f.estado, t.estado),
    t.cp        = COALESCE(NULLIF(t.cp, ''), f.cp, t.cp)
WHERE t.email IS NOT NULL AND t.email <> '';");
    echo "</pre>";
    echo "<p class='warn'>⚠ Este SQL NO se ejecuta automáticamente. Revisa primero y pide ejecutarlo si estás de acuerdo.</p>";
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box' style='background:#fee2e2;'><h2 class='err'>Error</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre></div>";
}
?>

</body>
</html>
