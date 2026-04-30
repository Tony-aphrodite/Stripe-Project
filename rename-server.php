<?php
/**
 * Voltika — server-side URL rename utility.
 *
 * Customer brief 2026-04-30: rename /configurador_prueba/ to /configurador/
 * across the live server in-place, without re-uploading 137 files via SFTP.
 *
 * Usage:
 *   1. Upload this single file to /var/www/vhosts/voltika.mx/httpdocs/
 *      (the SAME directory that contains configurador_prueba/, admin/, etc.)
 *   2. Browse to:
 *        https://www.voltika.mx/rename-server.php?token=voltika_rename_2026
 *      → DRY-RUN: shows all files that WOULD change, no edits made.
 *   3. When the dry-run looks correct, browse to:
 *        https://www.voltika.mx/rename-server.php?token=voltika_rename_2026&confirm=YES_RENAME_NOW
 *      → EXECUTE: rewrites every file + renames the folder.
 *   4. After success, DELETE this file via FileZilla (security: it lets
 *      anyone with the token modify production code).
 *
 * What it does:
 *   - Walks production folders: configurador_prueba/, admin/, clientes/, puntosvoltika/
 *   - In every .php / .js / .html / .htaccess / .md / .css / .sh / .json / .txt:
 *       replaces  configurador_prueba   →   configurador
 *       does NOT  configurador_prueba_test (test env stays intact)
 *   - Skips admin_test, configurador_prueba_test, puntosvoltika_test,
 *     clientes_test, vendor, node_modules, .git
 *   - Finally:  mv configurador_prueba/  →  configurador/
 *
 * Safety:
 *   - Token-gated. Default token below; override via env if you want.
 *   - Plan-first: dry-run is the default; execution requires explicit
 *     confirm flag.
 *   - Folder rename is the LAST step, so if text replacement fails partway
 *     the site keeps working under the old folder name and you can fix the
 *     remainder manually.
 *   - Refuses to overwrite an existing /configurador/ folder.
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

$DRY_RUN = (($_GET['confirm'] ?? '') !== 'YES_RENAME_NOW');
$ROOT    = __DIR__;

echo "================================================================\n";
echo "  Voltika URL rename — configurador_prueba → configurador\n";
echo "================================================================\n";
echo "Mode      : " . ($DRY_RUN ? "DRY-RUN (no changes)" : "EXECUTE") . "\n";
echo "Root path : $ROOT\n";
echo "Time      : " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------------------------------\n\n";

// ── What folders to scan ───────────────────────────────────────────────────
$scanFolders = [
    "$ROOT/configurador_prueba",
    "$ROOT/admin",
    "$ROOT/clientes",
    "$ROOT/puntosvoltika",
];

// ── What folders to skip even if they appear inside scanFolders ────────────
$excludedNames = [
    'admin_test',
    'configurador_prueba_test',
    'puntosvoltika_test',
    'clientes_test',
    'vendor',
    'node_modules',
    '.git',
];

// ── What file types to process ─────────────────────────────────────────────
$extensions = ['php','js','html','htaccess','md','css','sh','json','txt'];

// ── Step 1: collect candidate files ────────────────────────────────────────
$filesWithMatches = [];
$totalScanned = 0;

foreach ($scanFolders as $folder) {
    if (!is_dir($folder)) {
        echo "SKIP (folder missing): " . basename($folder) . "/\n";
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($file) use ($excludedNames) {
                if ($file->isDir() && in_array($file->getFilename(), $excludedNames, true)) {
                    return false;
                }
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

        // Negative lookahead — skip configurador_prueba_test occurrences.
        if (preg_match('/configurador_prueba(?!_test)/', $contents, $m, 0)) {
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

// ── Step 2: list/replace ───────────────────────────────────────────────────
if ($DRY_RUN) {
    echo "DRY-RUN list (file → occurrence count):\n\n";
    foreach ($filesWithMatches as $row) {
        $rel = str_replace($ROOT . '/', '', $row['path']);
        printf("  [%3d]  %s\n", $row['count'], $rel);
    }
    echo "\nFolder rename (would happen on EXECUTE):\n";
    echo "  $ROOT/configurador_prueba  →  $ROOT/configurador\n";
    echo "\n----------------------------------------------------------------\n";
    echo "To EXECUTE these changes, browse to:\n";
    echo "  ?token=" . urlencode($expectedToken) . "&confirm=YES_RENAME_NOW\n";
    echo "================================================================\n";
    exit;
}

// ── EXECUTE ────────────────────────────────────────────────────────────────
echo "Executing replacements...\n\n";
$ok        = 0;
$failed    = 0;
$replaced  = 0;
foreach ($filesWithMatches as $row) {
    $path = $row['path'];
    $contents = @file_get_contents($path);
    if ($contents === false) {
        echo "  FAIL (read)  : " . str_replace($ROOT . '/', '', $path) . "\n";
        $failed++;
        continue;
    }
    $new = preg_replace('/configurador_prueba(?!_test)/', 'configurador', $contents, -1, $count);
    if ($new === null) {
        echo "  FAIL (regex) : " . str_replace($ROOT . '/', '', $path) . "\n";
        $failed++;
        continue;
    }
    if (@file_put_contents($path, $new) === false) {
        echo "  FAIL (write) : " . str_replace($ROOT . '/', '', $path) . "  (check permissions)\n";
        $failed++;
        continue;
    }
    printf("  OK [%3d]    : %s\n", $count, str_replace($ROOT . '/', '', $path));
    $ok++;
    $replaced += $count;
}

echo "\nText replacement summary:\n";
echo "  Files OK      : $ok\n";
echo "  Files failed  : $failed\n";
echo "  Total changes : $replaced\n";
echo "----------------------------------------------------------------\n\n";

// ── Folder rename ──────────────────────────────────────────────────────────
echo "Renaming folder...\n";
$oldDir = "$ROOT/configurador_prueba";
$newDir = "$ROOT/configurador";

if (!is_dir($oldDir)) {
    echo "  configurador_prueba/ not found — possibly already renamed.\n";
} elseif (is_dir($newDir)) {
    echo "  ABORT: $newDir already exists. Folder rename skipped to avoid clobbering.\n";
    echo "  Manual fix: rename or remove the existing /configurador/ then re-run.\n";
} else {
    if (@rename($oldDir, $newDir)) {
        echo "  OK: configurador_prueba/  →  configurador/\n";
    } else {
        $err = error_get_last();
        echo "  FAIL: " . ($err['message'] ?? 'unknown error') . "\n";
        echo "  Manual fix: rename the folder via FileZilla.\n";
    }
}

echo "\n================================================================\n";
echo "  DONE\n";
echo "================================================================\n";
echo "Verify:\n";
echo "  https://www.voltika.mx/configurador/\n";
echo "  https://www.voltika.mx/clientes/\n";
echo "  https://www.voltika.mx/admin/\n\n";
echo "External systems to update manually (Stripe / Truora dashboards):\n";
echo "  Stripe webhook URL : .../configurador/php/stripe-webhook.php\n";
echo "  Truora webhook URL : .../configurador/php/truora-webhook.php\n";
echo "  Truora redirect URL: .../configurador/php/truora-redirect.php\n\n";
echo "SECURITY: delete this file (rename-server.php) immediately via FileZilla.\n";
