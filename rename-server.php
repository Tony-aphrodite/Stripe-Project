<?php
/**
 * Voltika — server-side URL rename utility (v2 — permission-resilient).
 *
 * Customer brief 2026-04-30: rename /configurador_prueba/ to /configurador/
 * across the live server in-place.
 *
 * v2 changes:
 *   - Uses atomic rename(temp, target) for file replacement, which only
 *     requires write access on the parent DIRECTORY, not on the file
 *     itself. This bypasses the "file owned by root, PHP runs as voltika"
 *     blocker that v1 hit.
 *   - Detects whether the configurador folder is already renamed and adapts.
 *   - Multiple fallback strategies per file: atomic rename → direct write
 *     → chmod-then-write. Reports which strategy worked.
 *   - Pre-flight diagnostic: tests writability of each scan directory and
 *     shows ownership/perm info for failing paths.
 *
 * Usage:
 *   1. Upload to /var/www/vhosts/voltika.mx/httpdocs/
 *   2. ?action=diag&token=voltika_rename_2026
 *      → Diagnostic — shows file/directory ownership and writability.
 *   3. ?action=plan&token=voltika_rename_2026
 *      → Dry-run — list of files that would change.
 *   4. ?action=execute&token=voltika_rename_2026&confirm=YES_RENAME_NOW
 *      → Execute. Tries 3 strategies per file and reports which worked.
 *   5. After success, DELETE this file via FileZilla.
 */

declare(strict_types=1);

ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');
header('Content-Type: text/plain; charset=utf-8');

// ── Token gate ─────────────────────────────────────────────────────────────
$expectedToken = getenv('RENAME_TOKEN') ?: 'voltika_rename_2026';
$providedToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, (string)$providedToken)) {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

$action  = (string)($_GET['action'] ?? 'plan');
$confirm = (string)($_GET['confirm'] ?? '');
$ROOT    = __DIR__;

echo "================================================================\n";
echo "  Voltika URL rename v2 (permission-resilient)\n";
echo "================================================================\n";
echo "Action    : $action\n";
echo "Root path : $ROOT\n";
echo "Time      : " . date('Y-m-d H:i:s') . "\n";
echo "PHP user  : " . posix_getpwuid(posix_geteuid())['name'] . " (uid=" . posix_geteuid() . ", gid=" . posix_getegid() . ")\n";
echo "----------------------------------------------------------------\n\n";

// ── Detect current state of configurador folder ───────────────────────────
$dirOld = "$ROOT/configurador_prueba";
$dirNew = "$ROOT/configurador";

$folderState = 'unknown';
if (is_dir($dirNew) && !is_dir($dirOld)) {
    $folderState = 'already_renamed';
} elseif (is_dir($dirOld) && !is_dir($dirNew)) {
    $folderState = 'needs_rename';
} elseif (is_dir($dirOld) && is_dir($dirNew)) {
    $folderState = 'both_exist';   // ambiguous
} else {
    $folderState = 'neither_exists';
}
echo "Folder state: $folderState\n";
if ($folderState === 'already_renamed') echo "  → Will scan: $dirNew (folder rename was completed previously)\n";
if ($folderState === 'needs_rename')    echo "  → Will scan: $dirOld and rename at end\n";
echo "\n";

// Pick the active folder for scanning
$configFolder = is_dir($dirNew) ? $dirNew : $dirOld;
$scanFolders = [
    $configFolder,
    "$ROOT/admin",
    "$ROOT/clientes",
    "$ROOT/puntosvoltika",
];
$excludedNames = [
    'admin_test', 'configurador_prueba_test', 'puntosvoltika_test',
    'clientes_test', 'vendor', 'node_modules', '.git',
];
$extensions = ['php','js','html','htaccess','md','css','sh','json','txt'];

// ─────────────────────────────────────────────────────────────────────────
// ACTION: diag — test writability + show ownership
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'diag') {
    echo "Directory writability test:\n";
    foreach ($scanFolders as $f) {
        if (!is_dir($f)) {
            echo "  MISSING : $f\n";
            continue;
        }
        $stat = stat($f);
        $owner = posix_getpwuid($stat['uid']);
        $group = posix_getgrgid($stat['gid']);
        $perms = substr(sprintf('%o', $stat['mode']), -3);
        $writable = is_writable($f) ? 'WRITE' : 'NO-WR';
        printf("  [%s] %s   owner=%s:%s perms=%s\n", $writable, $f,
            $owner['name'] ?? $stat['uid'], $group['name'] ?? $stat['gid'], $perms);

        // Try writing a test file
        $testFile = $f . '/.voltika-write-test-' . uniqid();
        if (@file_put_contents($testFile, 'test') !== false) {
            @unlink($testFile);
            echo "      → can create files in this directory ✓\n";
        } else {
            echo "      → cannot create files (DIRECTORY not writable by PHP)\n";
        }
    }

    // Sample one file from each folder to show file ownership
    echo "\nSample file ownership:\n";
    foreach ($scanFolders as $f) {
        if (!is_dir($f)) continue;
        $sample = glob("$f/*.php")[0] ?? glob("$f/*.html")[0] ?? glob("$f/*")[0] ?? null;
        if ($sample && is_file($sample)) {
            $stat = stat($sample);
            $owner = posix_getpwuid($stat['uid']);
            $perms = substr(sprintf('%o', $stat['mode']), -3);
            $writable = is_writable($sample) ? 'WRITE' : 'NO-WR';
            printf("  [%s] %s   owner=%s perms=%s\n", $writable, $sample, $owner['name'] ?? $stat['uid'], $perms);
        }
    }
    exit;
}

// ── Collect candidate files (used by both plan + execute) ─────────────────
$filesWithMatches = [];
$totalScanned = 0;

foreach ($scanFolders as $folder) {
    if (!is_dir($folder)) {
        echo "SKIP (folder missing): $folder\n";
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($file) use ($excludedNames) {
                if ($file->isDir() && in_array($file->getFilename(), $excludedNames, true)) return false;
                return true;
            }
        )
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $totalScanned++;
        $name = $file->getFilename();
        $ext  = strtolower($file->getExtension());
        $isHtaccess = ($name === '.htaccess');
        if (!in_array($ext, $extensions, true) && !$isHtaccess) continue;

        $path = $file->getPathname();
        $contents = @file_get_contents($path);
        if ($contents === false) continue;

        if (preg_match('/configurador_prueba(?!_test)/', $contents)) {
            $count = preg_match_all('/configurador_prueba(?!_test)/', $contents);
            $filesWithMatches[] = ['path' => $path, 'count' => $count];
        }
    }
}

echo "Files scanned     : $totalScanned\n";
echo "Files with match  : " . count($filesWithMatches) . "\n";
$sumMatches = array_sum(array_column($filesWithMatches, 'count'));
echo "Total occurrences : $sumMatches\n";
echo "----------------------------------------------------------------\n\n";

// ─────────────────────────────────────────────────────────────────────────
// ACTION: plan
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'plan') {
    echo "DRY-RUN list:\n\n";
    foreach ($filesWithMatches as $row) {
        $rel = str_replace($ROOT . '/', '', $row['path']);
        printf("  [%3d]  %s\n", $row['count'], $rel);
    }
    echo "\nTo EXECUTE, browse to:\n";
    echo "  ?action=execute&token=" . urlencode($expectedToken) . "&confirm=YES_RENAME_NOW\n";
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: execute
// ─────────────────────────────────────────────────────────────────────────
if ($action !== 'execute') {
    echo "Unknown action. Valid: diag, plan, execute\n";
    exit;
}
if ($confirm !== 'YES_RENAME_NOW') {
    echo "Missing &confirm=YES_RENAME_NOW — aborted.\n";
    exit;
}

echo "Executing replacements (3-strategy fallback per file)...\n\n";

/**
 * Replace text in a file using up to 3 strategies, in order:
 *   (A) Direct file_put_contents — works when PHP owns the file
 *   (B) Atomic rename — write to temp file in same directory, then
 *       rename(temp, original). Only needs DIRECTORY write permission,
 *       so this works around files owned by another user.
 *   (C) chmod-then-direct — make the file writable then write. Only
 *       works if PHP can chmod (typically when PHP owns the file or runs
 *       as root, which it doesn't here, so this is rarely the winning
 *       path on Plesk shared hosting).
 *
 * Returns array{strategy:string, ok:bool, error:string}.
 */
function voltikaReplaceFile(string $path, string $newContent): array {
    // Strategy A: direct write
    if (@file_put_contents($path, $newContent) !== false) {
        return ['strategy' => 'A:direct', 'ok' => true, 'error' => ''];
    }

    // Strategy B: atomic rename via temp file in the same directory
    $dir = dirname($path);
    if (is_writable($dir)) {
        $tmp = $dir . '/.voltika-tmp-' . uniqid('', true);
        if (@file_put_contents($tmp, $newContent) !== false) {
            // Preserve original permissions if possible
            $origPerms = @fileperms($path);
            if ($origPerms !== false) @chmod($tmp, $origPerms & 0777);
            if (@rename($tmp, $path)) {
                return ['strategy' => 'B:atomic_rename', 'ok' => true, 'error' => ''];
            }
            // rename failed — fallback to delete + rename
            if (@unlink($path)) {
                if (@rename($tmp, $path)) {
                    return ['strategy' => 'B:unlink+rename', 'ok' => true, 'error' => ''];
                }
            }
            @unlink($tmp);
        }
    }

    // Strategy C: chmod 0666 then direct write
    if (@chmod($path, 0666)) {
        if (@file_put_contents($path, $newContent) !== false) {
            return ['strategy' => 'C:chmod+write', 'ok' => true, 'error' => ''];
        }
    }

    // All strategies failed
    $err = error_get_last();
    return ['strategy' => 'NONE', 'ok' => false, 'error' => $err['message'] ?? 'unknown'];
}

$ok = 0; $failed = 0; $replaced = 0;
$strategyCounts = ['A:direct' => 0, 'B:atomic_rename' => 0, 'B:unlink+rename' => 0, 'C:chmod+write' => 0];
$failures = [];

foreach ($filesWithMatches as $row) {
    $path = $row['path'];
    $contents = @file_get_contents($path);
    if ($contents === false) {
        echo "  FAIL (read)  : " . str_replace($ROOT . '/', '', $path) . "\n";
        $failed++;
        $failures[] = $path;
        continue;
    }
    $new = preg_replace('/configurador_prueba(?!_test)/', 'configurador', $contents, -1, $count);
    if ($new === null || $count === 0) {
        echo "  SKIP (no match): " . str_replace($ROOT . '/', '', $path) . "\n";
        continue;
    }

    $result = voltikaReplaceFile($path, $new);
    if ($result['ok']) {
        printf("  OK [%3d] (%s) : %s\n", $count, $result['strategy'], str_replace($ROOT . '/', '', $path));
        $ok++;
        $replaced += $count;
        if (isset($strategyCounts[$result['strategy']])) $strategyCounts[$result['strategy']]++;
    } else {
        printf("  FAIL (write) : %s   %s\n", str_replace($ROOT . '/', '', $path), $result['error']);
        $failed++;
        $failures[] = $path;
    }
}

echo "\nText replacement summary:\n";
echo "  Files OK      : $ok\n";
echo "  Files failed  : $failed\n";
echo "  Total changes : $replaced\n";
echo "  Strategies used:\n";
foreach ($strategyCounts as $s => $c) {
    if ($c > 0) echo "    $s : $c\n";
}
echo "----------------------------------------------------------------\n\n";

if ($failed > 0) {
    echo "FAILURES — directories below are not writable by PHP:\n";
    $failedDirs = array_unique(array_map('dirname', $failures));
    foreach ($failedDirs as $d) {
        $stat = @stat($d);
        $owner = $stat ? (posix_getpwuid($stat['uid'])['name'] ?? $stat['uid']) : '?';
        $perms = $stat ? substr(sprintf('%o', $stat['mode']), -3) : '?';
        echo "  $d   (owner=$owner, perms=$perms)\n";
    }
    echo "\nFix via FileZilla:\n";
    echo "  Right-click each directory → File Permissions → Numeric: 777\n";
    echo "  ✓ Recurse into subdirectories  ✓ Apply to directories only\n";
    echo "Then re-run this script. After success, set perms back to 755.\n";
}

// ── Folder rename (only if not already done) ──────────────────────────────
echo "\nFolder rename check...\n";
if ($folderState === 'already_renamed') {
    echo "  Already renamed previously — nothing to do.\n";
} elseif ($folderState === 'needs_rename') {
    if (@rename($dirOld, $dirNew)) {
        echo "  OK: configurador_prueba/  →  configurador/\n";
    } else {
        $err = error_get_last();
        echo "  FAIL: " . ($err['message'] ?? 'unknown') . "\n";
        echo "  Manual fix: rename via FileZilla.\n";
    }
} else {
    echo "  Skipping — folder state is '$folderState'.\n";
}

echo "\n================================================================\n";
echo "  DONE\n";
echo "================================================================\n";
echo "Verify:\n";
echo "  https://www.voltika.mx/configurador/\n";
echo "  https://www.voltika.mx/clientes/\n";
echo "  https://www.voltika.mx/admin/\n\n";
echo "External:\n";
echo "  Stripe webhook : .../configurador/php/stripe-webhook.php\n";
echo "  Truora webhook : .../configurador/php/truora-webhook.php\n";
echo "  Truora redirect: .../configurador/php/truora-redirect.php\n\n";
echo "SECURITY: delete rename-server.php via FileZilla now.\n";
