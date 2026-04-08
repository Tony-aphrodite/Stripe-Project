<?php
/**
 * Voltika — Self-diagnostic for the latest changes
 *
 * Verifies that all the recent code changes (centroEntrega, comprobante,
 * firmas_contratos, subscripciones_credito, transacciones new columns,
 * webhook receiver) are deployed and wired correctly.
 *
 * USAGE:
 *   /configurador_prueba/php/test-cambios.php?key=voltika_test_2026
 *
 * Optional flags:
 *   &write=1   → also runs INSERT smoke tests inside a ROLLBACK'd transaction
 *                so we verify column wiring without leaving any rows behind.
 *
 * IMPORTANT: Delete or protect this file once testing is done.
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_test_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

$writeMode = !empty($_GET['write']);

header('Content-Type: text/html; charset=UTF-8');

// ─── Tracking arrays ─────────────────────────────────────────────────────────
$results = [];
$totalChecks = 0;
$passed      = 0;

function check(string $section, string $name, bool $ok, string $detail = ''): void {
    global $results, $totalChecks, $passed;
    $totalChecks++;
    if ($ok) $passed++;
    $results[$section][] = compact('name', 'ok', 'detail');
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. PHP files exist
// ═════════════════════════════════════════════════════════════════════════════
$expectedFiles = [
    'verificar-identidad.php',
    'confirmar-orden.php',
    'generar-contrato-pdf.php',
    'create-setup-intent.php',
    'confirmar-autopago.php',
    'truora-webhook.php',
    'notificaciones.php',
    'centros-entrega-data.php',
    'test-notificaciones.php',
];
foreach ($expectedFiles as $f) {
    $path = __DIR__ . '/' . $f;
    check('1. PHP files', $f, file_exists($path),
          file_exists($path) ? filesize($path) . ' bytes' : 'NOT FOUND');
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. DB connectivity + tables exist
// ═════════════════════════════════════════════════════════════════════════════
$pdo = null;
try {
    $pdo = getDB();
    check('2. DB connection', 'getDB()', true, 'Connected to ' . DB_NAME);
} catch (Exception $e) {
    check('2. DB connection', 'getDB()', false, $e->getMessage());
}

$expectedTables = [
    'transacciones',
    'inventario_motos',
    'verificaciones_identidad',
    'consultas_buro',
    'firmas_contratos',
    'subscripciones_credito',
];

if ($pdo) {
    $existingTables = [];
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        check('3. Tables', 'SHOW TABLES', false, $e->getMessage());
    }

    foreach ($expectedTables as $t) {
        $exists = in_array($t, $existingTables, true);
        $detail = $exists ? '✓' : 'NOT FOUND (will be auto-created on first use)';
        // firmas_contratos and subscripciones_credito are auto-created on first
        // hit, so missing them is informational, not a failure.
        $isLazy = in_array($t, ['firmas_contratos', 'subscripciones_credito'], true);
        check('3. Tables', $t, $exists || $isLazy, $detail);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. Required columns on each table
// ═════════════════════════════════════════════════════════════════════════════
$requiredColumns = [
    'transacciones' => [
        'asesoria_placas', 'seguro_qualitas',
        'punto_id', 'punto_nombre',
        'msi_meses', 'msi_pago',
    ],
    'inventario_motos' => [
        'punto_id', 'punto_nombre',
    ],
    'verificaciones_identidad' => [
        'ine_frente_path', 'ine_reverso_path', 'selfie_path',
        'face_check_id', 'face_score', 'face_match',
        'doc_check_id', 'doc_status',
        'comprobante_path', 'domicilio_diferente',
        'webhook_payload', 'webhook_received_at',
    ],
];

if ($pdo) {
    foreach ($requiredColumns as $table => $cols) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $col) {
                $ok = in_array($col, $existing, true);
                check("4. Columns ($table)", $col, $ok, $ok ? '✓' : 'MISSING — call the endpoint once to auto-create');
            }
        } catch (PDOException $e) {
            check("4. Columns ($table)", 'lookup', false, $e->getMessage());
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. Smoke-test inserts (only with &write=1) — wrapped in ROLLBACK
// ═════════════════════════════════════════════════════════════════════════════
$smokeOutput = [];
if ($writeMode && $pdo) {
    // 4a) transacciones — verify new column wiring
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO transacciones
                (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
                 precio, total, freg, pedido, stripe_pi,
                 asesoria_placas, seguro_qualitas, punto_id, punto_nombre,
                 msi_meses, msi_pago)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'TEST_DIAG', 'test@diag.local', '5500000000', 'voltika-one', 'negro',
            'CDMX', 'CDMX', '01000', 'unico',
            65000, 65000, date('Y-m-d H:i'), 'TEST-' . time(), 'pi_test_diag',
            1, 1, 'godike-motors', 'Godike Motors',
            null, null,
        ]);
        $newId = $pdo->lastInsertId();
        $row = $pdo->query("SELECT punto_id, asesoria_placas, seguro_qualitas FROM transacciones WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
        $pdo->rollBack();

        $ok = $row && $row['punto_id'] === 'godike-motors' && $row['asesoria_placas'] == 1;
        check('5. Smoke INSERT', 'transacciones (rolled back)', $ok,
              $ok ? "punto_id={$row['punto_id']} asesoria={$row['asesoria_placas']} seguro={$row['seguro_qualitas']}" : 'INSERT or column read failed');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        check('5. Smoke INSERT', 'transacciones', false, $e->getMessage());
    }

    // 4b) verificaciones_identidad — verify comprobante + domicilio_diferente
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO verificaciones_identidad
                (nombre, apellidos, fecha_nacimiento, telefono, email,
                 truora_check_id, truora_score, identity_status, approved, files_saved,
                 ine_frente_path, ine_reverso_path, selfie_path,
                 face_check_id, face_score, face_match,
                 doc_check_id, doc_status,
                 comprobante_path, domicilio_diferente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'TEST', 'DIAG', '1990-01-01', '5500000000', 'test@diag.local',
            'check_test_diag', 0.99, 'valid', 1, '[]',
            'ine_f.jpg', 'ine_r.jpg', 'selfie.jpg',
            'face_test', 0.85, 1,
            null, null,
            'comprobante_test.jpg', 1,
        ]);
        $newId = $pdo->lastInsertId();
        $row = $pdo->query("SELECT comprobante_path, domicilio_diferente, face_score FROM verificaciones_identidad WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
        $pdo->rollBack();

        $ok = $row && $row['comprobante_path'] === 'comprobante_test.jpg' && (int)$row['domicilio_diferente'] === 1;
        check('5. Smoke INSERT', 'verificaciones_identidad (rolled back)', $ok,
              $ok ? "comprobante=✓ domicilio_diferente=1 face_score={$row['face_score']}" : 'INSERT or column read failed');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        check('5. Smoke INSERT', 'verificaciones_identidad', false, $e->getMessage());
    }

    // 4c) firmas_contratos — auto-create + insert
    try {
        // Trigger auto-create if missing
        $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nombre        VARCHAR(200),
            email         VARCHAR(200),
            telefono      VARCHAR(30),
            curp          VARCHAR(20),
            modelo        VARCHAR(200),
            pdf_file      VARCHAR(255),
            customer_id   VARCHAR(100),
            firma_base64  MEDIUMTEXT,
            firma_sha256  CHAR(64),
            ip            VARCHAR(64),
            user_agent    VARCHAR(500),
            freg          DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->beginTransaction();
        $base64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $stmt = $pdo->prepare("
            INSERT INTO firmas_contratos
                (nombre, email, telefono, curp, modelo, pdf_file, customer_id,
                 firma_base64, firma_sha256, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'TEST DIAG', 'test@diag.local', '5500000000', 'TEST900101HDFXXX01',
            'voltika-one', 'test_diag.pdf', 'cus_test',
            $base64, hash('sha256', $base64), '127.0.0.1', 'TestAgent/1.0',
        ]);
        $newId = $pdo->lastInsertId();
        $row = $pdo->query("SELECT firma_sha256, ip FROM firmas_contratos WHERE id=$newId")->fetch(PDO::FETCH_ASSOC);
        $pdo->rollBack();

        $ok = $row && strlen($row['firma_sha256']) === 64;
        check('5. Smoke INSERT', 'firmas_contratos (rolled back)', $ok,
              $ok ? 'sha256 length=64 ip=' . $row['ip'] : 'audit row not created');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        check('5. Smoke INSERT', 'firmas_contratos', false, $e->getMessage());
    }

    // 4d) subscripciones_credito — auto-create + insert + update path
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscripciones_credito (
            id                       INT AUTO_INCREMENT PRIMARY KEY,
            nombre                   VARCHAR(200),
            email                    VARCHAR(200),
            telefono                 VARCHAR(30),
            stripe_customer_id       VARCHAR(100),
            stripe_setup_intent_id   VARCHAR(100) UNIQUE,
            stripe_payment_method_id VARCHAR(100) NULL,
            status                   VARCHAR(20) DEFAULT 'pending',
            monto_semanal            DECIMAL(12,2) NULL,
            inventario_moto_id       INT NULL,
            freg                     DATETIME DEFAULT CURRENT_TIMESTAMP,
            factivacion              DATETIME NULL
        )");

        $pdo->beginTransaction();
        $sid = 'seti_test_diag_' . uniqid();
        $stmt = $pdo->prepare("
            INSERT INTO subscripciones_credito
                (nombre, email, stripe_customer_id, stripe_setup_intent_id, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->execute(['TEST', 'test@diag.local', 'cus_test', $sid]);

        $upd = $pdo->prepare("
            UPDATE subscripciones_credito
            SET status='active', stripe_payment_method_id=?, monto_semanal=?, factivacion=NOW()
            WHERE stripe_setup_intent_id=?
        ");
        $upd->execute(['pm_test_diag', 850.00, $sid]);

        $row = $pdo->query("SELECT status, stripe_payment_method_id, monto_semanal FROM subscripciones_credito WHERE stripe_setup_intent_id='$sid'")->fetch(PDO::FETCH_ASSOC);
        $pdo->rollBack();

        $ok = $row && $row['status'] === 'active' && $row['stripe_payment_method_id'] === 'pm_test_diag';
        check('5. Smoke INSERT', 'subscripciones_credito (rolled back)', $ok,
              $ok ? "status=active monto={$row['monto_semanal']}" : 'pending→active flow failed');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        check('5. Smoke INSERT', 'subscripciones_credito', false, $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// 5. Endpoint syntax / loadability check (require_once with output buffer)
// ═════════════════════════════════════════════════════════════════════════════
$loadCheck = [
    'notificaciones.php'      => function($p) { ob_start(); $r = (bool)@include_once $p; ob_end_clean(); return $r; },
    'centros-entrega-data.php'=> function($p) { ob_start(); $r = (bool)@include_once $p; ob_end_clean(); return $r && function_exists('obtenerPuntoPorId'); },
];
foreach ($loadCheck as $f => $fn) {
    $path = __DIR__ . '/' . $f;
    if (!file_exists($path)) continue;
    try {
        $ok = $fn($path);
        check('6. Includable', $f, $ok, $ok ? '✓' : 'failed to load');
    } catch (Throwable $e) {
        check('6. Includable', $f, false, $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. Centros mirror sanity check
// ═════════════════════════════════════════════════════════════════════════════
if (function_exists('obtenerPuntoPorId')) {
    $punto = obtenerPuntoPorId('godike-motors');
    $ok = $punto && !empty($punto['nombre']) && !empty($punto['link_maps']);
    check('7. Data', 'obtenerPuntoPorId(godike-motors)', (bool)$ok,
          $ok ? "nombre={$punto['nombre']}" : 'lookup failed');
}

// ═════════════════════════════════════════════════════════════════════════════
// Render
// ═════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Voltika — Test Cambios</title>
<style>
body{font-family:-apple-system,Arial,sans-serif;max-width:980px;margin:24px auto;padding:0 20px;color:#1a1a1a;}
h1{color:#039fe1;margin-bottom:4px;}
.summary{padding:14px 18px;border-radius:10px;margin:14px 0 22px;font-size:15px;font-weight:700;}
.summary.ok{background:#E8F5E9;color:#1B5E20;border-left:5px solid #2E7D32;}
.summary.partial{background:#FFF8E1;color:#5D4037;border-left:5px solid #F57F17;}
.summary.fail{background:#FFEBEE;color:#B71C1C;border-left:5px solid #C62828;}
section{background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px 18px;margin:12px 0;}
section h3{margin:0 0 10px;font-size:15px;color:#1a3a5c;border-bottom:2px solid #E5E7EB;padding-bottom:6px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
td{padding:6px 8px;border-bottom:1px solid #F5F5F5;vertical-align:top;}
td.ok{color:#2E7D32;font-weight:700;width:30px;text-align:center;}
td.fail{color:#C62828;font-weight:700;width:30px;text-align:center;}
td.name{font-family:Menlo,Consolas,monospace;width:300px;}
td.detail{color:#666;font-size:12px;}
.btn{display:inline-block;padding:8px 14px;background:#039fe1;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;font-size:13px;margin:6px 4px 0 0;}
.warn{background:#FFEBEE;border-left:5px solid #C62828;padding:12px;margin-top:24px;border-radius:8px;font-size:13px;color:#C62828;}
</style>
</head><body>

<h1>🧪 Voltika — Test de Cambios</h1>
<p style="color:#666;font-size:13px;margin:0 0 6px;">Self-diagnostic for the latest backend changes (centroEntrega, comprobante, firmas, subscripciones, transacciones).</p>

<?php
$ratio = $totalChecks ? round($passed / $totalChecks * 100) : 0;
$cls   = $passed === $totalChecks ? 'ok' : ($ratio >= 60 ? 'partial' : 'fail');
$icon  = $passed === $totalChecks ? '✅' : ($ratio >= 60 ? '⚠️' : '❌');
?>
<div class="summary <?= $cls ?>">
    <?= $icon ?> <?= $passed ?> / <?= $totalChecks ?> checks passed (<?= $ratio ?>%)
    <?php if (!$writeMode): ?>
        — read-only mode
    <?php endif; ?>
</div>

<?php if (!$writeMode): ?>
<div>
    <a class="btn" href="?key=voltika_test_2026&write=1">▶ Run smoke INSERTs (rolled back, safe)</a>
</div>
<?php endif; ?>

<?php foreach ($results as $section => $checks): ?>
<section>
    <h3><?= htmlspecialchars($section) ?></h3>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></td>
            <td class="name"><?= htmlspecialchars($c['name']) ?></td>
            <td class="detail"><?= htmlspecialchars($c['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php endforeach; ?>

<div class="warn">
⚠️ Eliminar este script (<code>test-cambios.php</code>) después de finalizar las pruebas en producción.
</div>

</body></html>
