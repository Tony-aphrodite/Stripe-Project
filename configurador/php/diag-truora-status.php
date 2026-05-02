<?php
/**
 * Voltika — preaprobaciones × verificaciones_identidad diagnostic.
 *
 * Customer brief 2026-05-02: verify that the new LEFT JOIN between
 * preaprobaciones and verificaciones_identidad correctly surfaces the
 * detailed Truora status (process_id, status, decline reason, CURP /
 * name match, manual-review flag) for each credit applicant.
 *
 * This is read-only — no DB writes, no Stripe calls, no emails.
 *
 * Usage:
 *   ?token=voltika_diag_2026
 *   ?token=voltika_diag_2026&search=carrasco   (filter by name/email)
 *
 * Delete this file via FileZilla after diagnosis.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '60');
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'voltika_diag_2026') {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));

echo "================================================================\n";
echo "  Voltika preaprobaciones × Truora diagnostic\n";
echo "================================================================\n";
echo "Time   : " . date('Y-m-d H:i:s') . "\n";
if ($search !== '') echo "Search : '$search'\n";
echo "\n";

try {
    $pdo = getDB();

    // ── 1. Verify the JOIN runs without error ─────────────────────────────
    echo "1. Schema check:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM verificaciones_identidad")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['truora_process_id', 'truora_status', 'truora_declined_reason',
                 'curp_match', 'name_match', 'approved', 'manual_review_required'];
    foreach ($required as $c) {
        printf("   %-30s : %s\n", $c, in_array($c, $cols, true) ? 'present ✓' : 'MISSING ✗');
    }
    echo "\n";

    // ── 2. Run the exact same query listar.php now uses ───────────────────
    $where = ["1=1"];
    $params = [];
    if ($search !== '') {
        $where[] = "(LOWER(p.nombre) LIKE ? OR LOWER(p.apellido_paterno) LIKE ? OR LOWER(p.email) LIKE ? OR p.telefono LIKE ?)";
        $like = '%' . strtolower($search) . '%';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = '%' . $search . '%';
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT p.id, p.nombre, p.apellido_paterno, p.email, p.telefono,
                   p.modelo, p.precio_contado, p.score, p.synth_score, p.circulo_source,
                   p.truora_ok, p.status, p.seguimiento, p.freg,
                   vi.truora_process_id,
                   vi.truora_status,
                   vi.truora_failure_status,
                   vi.truora_declined_reason,
                   vi.curp_match,
                   vi.name_match,
                   vi.approved          AS truora_approved,
                   vi.manual_review_required,
                   vi.manual_review_reason,
                   vi.truora_updated_at
            FROM preaprobaciones p
            LEFT JOIN verificaciones_identidad vi
                   ON vi.id = (
                       SELECT vi2.id FROM verificaciones_identidad vi2
                        WHERE (vi2.telefono <> '' AND vi2.telefono = p.telefono)
                           OR (vi2.email    <> '' AND vi2.email    = p.email)
                        ORDER BY vi2.id DESC LIMIT 1
                   )
            WHERE $whereSql
            ORDER BY p.freg DESC
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "2. Last 20 preaprobaciones with Truora detail:\n";
    echo "   Found: " . count($rows) . " rows\n\n";

    $i = 0;
    foreach ($rows as $r) {
        $i++;
        echo "──────────────────────────────────────────────────────────────────\n";
        printf("[%d]  id=%d   freg=%s\n", $i, $r['id'], substr((string)$r['freg'], 0, 19));
        printf("     nombre   : %s %s\n",
            $r['nombre'] ?? '?', $r['apellido_paterno'] ?? '');
        printf("     contacto : %s   tel=%s\n",
            $r['email'] ?? '—', $r['telefono'] ?? '—');
        printf("     modelo   : %s   precio=$%s\n",
            $r['modelo'] ?? '?', number_format((float)$r['precio_contado'], 0));
        printf("     status   : %s   seguimiento=%s\n",
            $r['status'] ?? '?', $r['seguimiento'] ?? '—');
        printf("     CDC      : score=%s synth=%s source=%s\n",
            $r['score'] ?? '—', $r['synth_score'] ?? '—', $r['circulo_source'] ?? '—');

        // Compute the badge that the admin UI will show
        $badge = computeTruoraBadge($r);
        echo "\n";
        echo "     ┌─────────────────────────────────────────────────────────┐\n";
        printf("     │  Truora badge : %-40s│\n", $badge);
        echo "     └─────────────────────────────────────────────────────────┘\n";

        if (!empty($r['truora_process_id'])) {
            printf("     truora_process_id  : %s\n", $r['truora_process_id']);
            printf("     truora_status      : %s\n", $r['truora_status'] ?? '—');
            printf("     truora_approved    : %s\n", isset($r['truora_approved']) ? (string)$r['truora_approved'] : '(NULL)');
            printf("     declined_reason    : %s\n", $r['truora_declined_reason'] ?? '—');
            printf("     curp_match         : %s\n", isset($r['curp_match']) ? (string)$r['curp_match'] : '(NULL)');
            printf("     name_match         : %s\n", isset($r['name_match']) ? (string)$r['name_match'] : '(NULL)');
            printf("     manual_review      : %s%s\n",
                ($r['manual_review_required'] == 1 ? 'YES' : 'no'),
                ($r['manual_review_required'] == 1 && !empty($r['manual_review_reason'])
                    ? ' (' . $r['manual_review_reason'] . ')' : ''));
            printf("     truora_updated_at  : %s\n", $r['truora_updated_at'] ?? '—');
        } else {
            printf("     truora_process_id  : (no row in verificaciones_identidad — not attempted)\n");
            printf("     legacy truora_ok   : %s\n", isset($r['truora_ok']) ? (string)$r['truora_ok'] : '(NULL)');
        }
        echo "\n";
    }

    // ── 3. Aggregate breakdown ────────────────────────────────────────────
    echo "==================================================================\n";
    echo "3. Aggregate Truora status breakdown (last 100 preaprobaciones):\n\n";
    $stmt = $pdo->query("SELECT p.id, p.truora_ok,
                                vi.truora_status, vi.approved AS truora_approved,
                                vi.curp_match, vi.truora_process_id
                         FROM preaprobaciones p
                         LEFT JOIN verificaciones_identidad vi
                                ON vi.id = (
                                    SELECT vi2.id FROM verificaciones_identidad vi2
                                     WHERE (vi2.telefono <> '' AND vi2.telefono = p.telefono)
                                        OR (vi2.email    <> '' AND vi2.email    = p.email)
                                     ORDER BY vi2.id DESC LIMIT 1
                                )
                         ORDER BY p.id DESC LIMIT 100");
    $bucket = [];
    foreach ($stmt as $r) {
        $b = computeTruoraBadge($r);
        // Strip ANSI/HTML; keep just the label
        $b = preg_replace('/<[^>]+>/', '', $b);
        $b = preg_replace('/\s+\d{4}-\d{2}-\d{2}.*$/', ' (with date)', $b);
        $bucket[$b] = ($bucket[$b] ?? 0) + 1;
    }
    foreach ($bucket as $label => $count) {
        printf("   %-40s : %d\n", $label, $count);
    }

} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    if (strpos($e->getMessage(), 'verificaciones_identidad') !== false) {
        echo "\nThe verificaciones_identidad table may not exist yet on this\n";
        echo "environment. Run any Truora flow once to bootstrap it.\n";
    }
    exit;
}

echo "\n================================================================\n";
echo "DELETE this file (diag-truora-status.php) via FileZilla after use.\n";

/**
 * Mirror of admin-preaprobaciones.js truoraStatusBadge() so we can preview
 * exactly what the admin UI will render for each row.
 */
function computeTruoraBadge(array $row): string {
    $approved = $row['truora_approved'] ?? null;   // null = no JOIN match
    $curpM    = $row['curp_match'] ?? null;         // null = unknown
    $status   = strtolower((string)($row['truora_status'] ?? ''));
    $legacyOk = ((int)($row['truora_ok'] ?? 0) === 1);
    $hasViRow = !empty($row['truora_process_id']);

    // Priority 1: no verificaciones_identidad row → "No iniciado" or
    // legacy verified. Without this short-circuit, (int)NULL === 0 would
    // falsely classify all not-attempted rows as "Rechazado".
    if (!$hasViRow) {
        return $legacyOk ? "✓ Verificado (legacy)" : "— No iniciado";
    }

    // Priority 2: explicit verified
    if ((int)$approved === 1 && (int)$curpM === 1) {
        $when = !empty($row['truora_updated_at'])
            ? '  ' . substr((string)$row['truora_updated_at'], 0, 16)
            : '';
        return "✓ Verificado$when";
    }

    // Priority 3: CURP mismatch (only when explicitly 0)
    if ($curpM !== null && (int)$curpM === 0) {
        return "✗ CURP no coincide";
    }

    // Priority 4: in-progress
    if ($status === 'in_progress' || $status === 'pending') {
        return "⏳ En proceso";
    }

    // Priority 5: explicit rejection
    if ($approved !== null && (int)$approved === 0) {
        return "✗ Rechazado";
    }

    return "— Sin estado";
}
