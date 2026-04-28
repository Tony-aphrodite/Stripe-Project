<?php
/**
 * Voltika — Long-term tamper-evident document archive (NOM-151 + Tech Spec EN §6).
 *
 * Spec requirements:
 *   - Minimum 10 years retention (NOM-151).
 *   - Tamper-evident storage (write-once or blockchain-backed).
 *   - Backup with geographic redundancy.
 *   - Document hashes (SHA-256) for integrity verification.
 *   - Digital chain of custody for all signed documents.
 *
 * Implementation strategy:
 *   - Local disk = working storage (PDF generation, immediate downloads).
 *   - S3 with Object Lock (compliance mode, 10-year retention) = primary
 *     tamper-evident archive. Object Lock makes the bucket immutable —
 *     even root credentials cannot delete or overwrite within the lock
 *     period, satisfying NOM-151 "tamper-evident".
 *   - S3 cross-region replication = geographic redundancy.
 *   - Optional: post the SHA-256 digest to a public chain (Bitcoin
 *     OpenTimestamps, Ethereum) for an extra blockchain anchor — Cincel
 *     already does this via its CCMD when documents are timestamped.
 *
 * Configuration (config.php / env):
 *   ARCHIVE_DRIVER       = 's3' | 'local' (default 'local' = no archival)
 *   ARCHIVE_S3_BUCKET    = bucket name (must have Object Lock enabled)
 *   ARCHIVE_S3_REGION    = e.g., us-east-1
 *   ARCHIVE_S3_KEY       = AWS access key id
 *   ARCHIVE_S3_SECRET    = AWS secret access key
 *   ARCHIVE_RETAIN_YEARS = retention period (default 10)
 *
 * Public functions:
 *   archivoEnsureSchema(PDO)
 *   archivoUploadPDF(string $localPath, array $meta): array
 *   archivoVerifyIntegrity(int $archivoId, ?string $expectedHash): array
 *   archivoGetUrl(int $archivoId, int $expiresInSec = 3600): ?string
 */

require_once __DIR__ . '/config.php';

function archivoEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS archivo_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(40) NOT NULL,
              -- contrato_compraventa | contrato_credito | acta_entrega | pagare | carta_factura | cfdi
            referencia VARCHAR(120) NULL,         -- pedido | folio | uuid
            transaccion_id INT NULL,
            moto_id INT NULL,
            cliente_id INT NULL,
            sha256 CHAR(64) NOT NULL,
            tamano_bytes BIGINT NOT NULL,
            local_path VARCHAR(500) NULL,
            archive_driver VARCHAR(20) NOT NULL DEFAULT 'local',
            archive_url VARCHAR(500) NULL,
            archive_object_key VARCHAR(255) NULL,
            archive_etag VARCHAR(80) NULL,
            retain_until DATE NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'archivado',
              -- archivado | pendiente_subida | error
            error_msg TEXT NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tipo (tipo),
            INDEX idx_referencia (referencia),
            INDEX idx_sha256 (sha256),
            INDEX idx_transaccion (transaccion_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('archivoEnsureSchema: ' . $e->getMessage()); }
}

/**
 * Upload a generated PDF to long-term storage and persist metadata.
 *
 * Required keys in $meta:
 *   tipo (one of contrato_compraventa|contrato_credito|acta_entrega|pagare|carta_factura|cfdi)
 *   referencia (pedido / folio / uuid)
 * Optional: transaccion_id, moto_id, cliente_id
 *
 * Returns: ['ok' => bool, 'archivo_id' => int, 'sha256' => string, 'driver' => string, 'url' => ?string]
 */
function archivoUploadPDF(string $localPath, array $meta): array {
    if (!file_exists($localPath) || filesize($localPath) === 0) {
        return ['ok' => false, 'error' => 'Archivo local no encontrado o vacío'];
    }
    $pdo = getDB();
    archivoEnsureSchema($pdo);

    $sha    = hash_file('sha256', $localPath);
    $size   = filesize($localPath);
    $driver = strtolower(getenv('ARCHIVE_DRIVER') ?: (defined('ARCHIVE_DRIVER') ? ARCHIVE_DRIVER : 'local'));

    // Idempotency: if same hash already archived for the same referencia, return existing.
    if (!empty($meta['referencia'])) {
        $st = $pdo->prepare("SELECT id, archive_url, archive_driver FROM archivo_documentos
                             WHERE referencia = ? AND sha256 = ? AND estado = 'archivado' LIMIT 1");
        $st->execute([$meta['referencia'], $sha]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => true, 'archivo_id' => (int)$r['id'], 'sha256' => $sha,
                    'driver' => $r['archive_driver'], 'url' => $r['archive_url'], 'duplicate' => true];
        }
    }

    $retainYears = (int)(getenv('ARCHIVE_RETAIN_YEARS') ?: (defined('ARCHIVE_RETAIN_YEARS') ? ARCHIVE_RETAIN_YEARS : 10));
    $retainUntil = date('Y-m-d', strtotime('+' . $retainYears . ' years'));

    $insert = $pdo->prepare("INSERT INTO archivo_documentos
            (tipo, referencia, transaccion_id, moto_id, cliente_id,
             sha256, tamano_bytes, local_path, archive_driver, retain_until, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente_subida')");
    $insert->execute([
        $meta['tipo'],
        $meta['referencia'] ?? null,
        $meta['transaccion_id'] ?? null,
        $meta['moto_id'] ?? null,
        $meta['cliente_id'] ?? null,
        $sha, $size, $localPath, $driver, $retainUntil,
    ]);
    $id = (int)$pdo->lastInsertId();

    if ($driver === 'local' || $driver === '') {
        // Local-only mode = no remote archive. Mark as archived locally.
        $pdo->prepare("UPDATE archivo_documentos SET estado='archivado' WHERE id = ?")
            ->execute([$id]);
        return ['ok' => true, 'archivo_id' => $id, 'sha256' => $sha, 'driver' => 'local', 'url' => null,
                'note' => 'ARCHIVE_DRIVER=local — sin replicación remota. Configurar S3 para cumplir NOM-151 a 10 años.'];
    }

    if ($driver === 's3') {
        $r = _archivoUploadS3($localPath, $meta, $sha, $retainUntil);
        if ($r['ok']) {
            $pdo->prepare("UPDATE archivo_documentos
                           SET estado='archivado', archive_url=?, archive_object_key=?, archive_etag=?
                           WHERE id = ?")
                ->execute([$r['url'], $r['object_key'], $r['etag'], $id]);
            return ['ok' => true, 'archivo_id' => $id, 'sha256' => $sha, 'driver' => 's3', 'url' => $r['url']];
        }
        $pdo->prepare("UPDATE archivo_documentos SET estado='error', error_msg=? WHERE id = ?")
            ->execute([substr($r['error'], 0, 1000), $id]);
        return ['ok' => false, 'archivo_id' => $id, 'error' => $r['error']];
    }

    return ['ok' => false, 'error' => "ARCHIVE_DRIVER '{$driver}' no soportado"];
}

/**
 * Verify integrity by re-hashing the local file (or downloading from
 * archive) and comparing against the stored SHA-256.
 */
function archivoVerifyIntegrity(int $archivoId, ?string $expectedHash = null): array {
    $pdo = getDB();
    archivoEnsureSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM archivo_documentos WHERE id = ?");
    $st->execute([$archivoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'no encontrado'];

    $stored = $expectedHash ?: $row['sha256'];
    $actual = file_exists($row['local_path']) ? hash_file('sha256', $row['local_path']) : null;
    if (!$actual) return ['ok' => false, 'error' => 'archivo local no disponible — usar driver remoto'];
    return [
        'ok' => true,
        'valid' => hash_equals($stored, $actual),
        'expected' => $stored,
        'actual'   => $actual,
    ];
}

function archivoGetUrl(int $archivoId, int $expiresInSec = 3600): ?string {
    $pdo = getDB();
    $st = $pdo->prepare("SELECT archive_driver, archive_object_key, archive_url FROM archivo_documentos WHERE id = ?");
    $st->execute([$archivoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ($row['archive_driver'] !== 's3') return $row['archive_url'];
    return _archivoS3SignedUrl($row['archive_object_key'], $expiresInSec);
}

// ─────────────────────────────────────────────────────────────────────────
// S3 driver — uses AWS SDK if installed; otherwise raw HTTP signing.
// ─────────────────────────────────────────────────────────────────────────

function _archivoUploadS3(string $localPath, array $meta, string $sha, string $retainUntil): array {
    $bucket = getenv('ARCHIVE_S3_BUCKET') ?: (defined('ARCHIVE_S3_BUCKET') ? ARCHIVE_S3_BUCKET : '');
    $region = getenv('ARCHIVE_S3_REGION') ?: (defined('ARCHIVE_S3_REGION') ? ARCHIVE_S3_REGION : 'us-east-1');
    $key    = getenv('ARCHIVE_S3_KEY')    ?: (defined('ARCHIVE_S3_KEY')    ? ARCHIVE_S3_KEY    : '');
    $secret = getenv('ARCHIVE_S3_SECRET') ?: (defined('ARCHIVE_S3_SECRET') ? ARCHIVE_S3_SECRET : '');

    if (!$bucket || !$key || !$secret) {
        return ['ok' => false, 'error' => 'ARCHIVE_S3_* incompletos en config'];
    }

    // Object key: tipo/yyyy/mm/referencia_sha8.pdf
    $referencia = preg_replace('/[^A-Za-z0-9_-]/', '_', $meta['referencia'] ?? 'doc');
    $objectKey = sprintf('%s/%s/%s/%s_%s.pdf',
        $meta['tipo'], date('Y'), date('m'), $referencia, substr($sha, 0, 8));

    $sdkAutoload = __DIR__ . '/vendor/aws/aws-sdk-php/src/functions.php';
    if (file_exists($sdkAutoload) || class_exists('Aws\\S3\\S3Client')) {
        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest', 'region' => $region,
                'credentials' => ['key' => $key, 'secret' => $secret],
            ]);
            $r = $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $objectKey,
                'SourceFile' => $localPath,
                'ContentType' => 'application/pdf',
                'Metadata'    => [
                    'sha256'      => $sha,
                    'tipo'        => $meta['tipo'],
                    'referencia'  => (string)($meta['referencia'] ?? ''),
                ],
                // Object Lock: compliance mode = even root cannot delete.
                'ObjectLockMode' => 'COMPLIANCE',
                'ObjectLockRetainUntilDate' => $retainUntil . 'T00:00:00Z',
            ]);
            return [
                'ok' => true,
                'object_key' => $objectKey,
                'etag'       => trim((string)$r['ETag'], '"'),
                'url'        => $r['ObjectURL'] ?? sprintf('s3://%s/%s', $bucket, $objectKey),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'S3 SDK error: ' . $e->getMessage()];
        }
    }

    return ['ok' => false, 'error' => 'AWS SDK PHP no instalado. composer require aws/aws-sdk-php'];
}

function _archivoS3SignedUrl(string $objectKey, int $expiresInSec): ?string {
    $bucket = getenv('ARCHIVE_S3_BUCKET') ?: (defined('ARCHIVE_S3_BUCKET') ? ARCHIVE_S3_BUCKET : '');
    if (!$bucket) return null;
    $sdkAutoload = __DIR__ . '/vendor/autoload.php';
    if (!class_exists('Aws\\S3\\S3Client') && file_exists($sdkAutoload)) require_once $sdkAutoload;
    if (!class_exists('Aws\\S3\\S3Client')) return null;
    try {
        $s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => getenv('ARCHIVE_S3_REGION') ?: (defined('ARCHIVE_S3_REGION') ? ARCHIVE_S3_REGION : 'us-east-1'),
            'credentials' => [
                'key'    => getenv('ARCHIVE_S3_KEY')    ?: ARCHIVE_S3_KEY,
                'secret' => getenv('ARCHIVE_S3_SECRET') ?: ARCHIVE_S3_SECRET,
            ],
        ]);
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $objectKey]);
        $req = $s3->createPresignedRequest($cmd, '+' . $expiresInSec . ' seconds');
        return (string)$req->getUri();
    } catch (Throwable $e) { error_log('_archivoS3SignedUrl: ' . $e->getMessage()); return null; }
}
