<?php
/**
 * Voltika — GPS tracker integration (scaffold).
 *
 * Tech Spec EN §5.9 + Cláusula Décima Sexta of v5 contract:
 *   "EL CLIENTE reconoce y acepta que la motocicleta cuenta con
 *    dispositivos tecnológicos de geolocalización (GPS) y monitoreo,
 *    los cuales serán utilizados exclusivamente para fines de seguridad,
 *    prevención de fraude, protección del bien y seguimiento durante la
 *    vigencia del contrato."
 *
 * §7 alert thresholds:
 *   - GPS signal lost > 24h         → alert security team
 *   - Vehicle moved out of allowed zone → alert + monitor
 *
 * Status: scaffold. Provider-agnostic interface; once Voltika contracts
 * a hardware vendor (e.g., Geotab, Coban, Calamp), wire its REST/MQTT
 * client into _gpsFetchProviderPositions().
 *
 * Configuration (config.php / env):
 *   GPS_PROVIDER         = 'geotab' | 'coban' | 'noop' (default noop)
 *   GPS_API_URL          = REST endpoint
 *   GPS_API_KEY          = provider key
 *   GPS_ALERT_ZONE_KM    = max distance from delivery point (default 100)
 *
 * DB tables:
 *   gps_positions  — raw positions (one row per ping)
 *   gps_devices    — tracker assignment per moto
 *
 * Public functions:
 *   gpsEnsureSchema(PDO)
 *   gpsRegisterDevice(int $motoId, string $deviceId, ...): array
 *   gpsRecordPosition(int $motoId, float $lat, float $lng, ?string $ts): void
 *   gpsCheckAlerts(): array  (cron-driven; produces escalations)
 */

require_once __DIR__ . '/config.php';

function gpsEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gps_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            moto_id   INT NOT NULL,
            device_id VARCHAR(80) NOT NULL UNIQUE,
            provider  VARCHAR(40) NULL,
            sim_iccid VARCHAR(40) NULL,
            home_lat  DECIMAL(10,7) NULL,
            home_lng  DECIMAL(10,7) NULL,
            estado    VARCHAR(20) DEFAULT 'activo',
            last_ping DATETIME NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_moto (moto_id),
            INDEX idx_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gps_positions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            moto_id INT NOT NULL,
            device_id VARCHAR(80) NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            speed_kmh DECIMAL(5,2) NULL,
            ts_device DATETIME NOT NULL,
            ts_received DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_moto_ts (moto_id, ts_device),
            INDEX idx_device_ts (device_id, ts_device)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('gpsEnsureSchema: ' . $e->getMessage()); }
}

function gpsRegisterDevice(int $motoId, string $deviceId, ?string $provider = null, ?float $homeLat = null, ?float $homeLng = null): array {
    $pdo = getDB();
    gpsEnsureSchema($pdo);
    try {
        $pdo->prepare("INSERT INTO gps_devices (moto_id, device_id, provider, home_lat, home_lng)
                       VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                           moto_id = VALUES(moto_id),
                           provider = VALUES(provider),
                           home_lat = VALUES(home_lat),
                           home_lng = VALUES(home_lng)")
            ->execute([$motoId, $deviceId, $provider, $homeLat, $homeLng]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function gpsRecordPosition(int $motoId, float $lat, float $lng, ?string $ts = null, ?float $speed = null, ?string $deviceId = null): void {
    $pdo = getDB();
    gpsEnsureSchema($pdo);
    try {
        $pdo->prepare("INSERT INTO gps_positions (moto_id, device_id, lat, lng, speed_kmh, ts_device)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$motoId, $deviceId, $lat, $lng, $speed, $ts ?: date('Y-m-d H:i:s')]);
        if ($deviceId) {
            $pdo->prepare("UPDATE gps_devices SET last_ping = NOW() WHERE device_id = ?")
                ->execute([$deviceId]);
        }
    } catch (Throwable $e) { error_log('gpsRecordPosition: ' . $e->getMessage()); }
}

/**
 * Spec §7 alert checker. Run from cron every hour.
 * Produces escalation rows with kind='gps_lost' or 'gps_out_of_zone'.
 */
function gpsCheckAlerts(): array {
    $pdo = getDB();
    gpsEnsureSchema($pdo);
    $stats = ['lost' => 0, 'out_of_zone' => 0];

    // 1. Devices that haven't pinged in > 24h
    try {
        $stale = $pdo->query("SELECT moto_id, device_id, last_ping
            FROM gps_devices
            WHERE estado = 'activo'
              AND (last_ping IS NULL OR last_ping < DATE_SUB(NOW(), INTERVAL 24 HOUR))")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $d) {
            _gpsOpenEscalationOnce($pdo, 'gps_lost', $d['moto_id'], $d['device_id'],
                'GPS sin señal >24h',
                'Dispositivo ' . $d['device_id'] . ' última señal: ' . ($d['last_ping'] ?: 'nunca'));
            $stats['lost']++;
        }
    } catch (Throwable $e) { error_log('gpsCheckAlerts lost: ' . $e->getMessage()); }

    // 2. Positions outside home zone (last position vs home_lat/lng)
    $maxKm = (float)(getenv('GPS_ALERT_ZONE_KM') ?: (defined('GPS_ALERT_ZONE_KM') ? GPS_ALERT_ZONE_KM : 100));
    try {
        $rows = $pdo->query("
            SELECT d.moto_id, d.device_id, d.home_lat, d.home_lng,
                   p.lat AS cur_lat, p.lng AS cur_lng
            FROM gps_devices d
            JOIN gps_positions p
              ON p.id = (SELECT id FROM gps_positions WHERE moto_id = d.moto_id ORDER BY ts_device DESC LIMIT 1)
            WHERE d.estado = 'activo' AND d.home_lat IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $km = _gpsHaversineKm((float)$r['home_lat'], (float)$r['home_lng'],
                                  (float)$r['cur_lat'],  (float)$r['cur_lng']);
            if ($km > $maxKm) {
                _gpsOpenEscalationOnce($pdo, 'gps_out_of_zone', $r['moto_id'], $r['device_id'],
                    'Vehículo fuera de zona permitida',
                    sprintf('Distancia desde punto de entrega: %.1f km (límite %.0f km)', $km, $maxKm));
                $stats['out_of_zone']++;
            }
        }
    } catch (Throwable $e) { error_log('gpsCheckAlerts zone: ' . $e->getMessage()); }

    return $stats;
}

function _gpsOpenEscalationOnce(PDO $pdo, string $kind, int $motoId, ?string $deviceId, string $titulo, string $detalle): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS escalations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40), severity VARCHAR(20) DEFAULT 'critical',
            cliente_id INT NULL, transaccion_id INT NULL, moto_id INT NULL,
            ref_externa VARCHAR(120) NULL, titulo VARCHAR(200), detalle TEXT,
            estado VARCHAR(20) DEFAULT 'open', asignado_a VARCHAR(80),
            notas MEDIUMTEXT, freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME, INDEX idx_estado_kind (estado, kind),
            INDEX idx_moto (moto_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Idempotency: one open escalation per (kind, moto_id).
        $st = $pdo->prepare("SELECT id FROM escalations
                              WHERE kind = ? AND moto_id = ? AND estado IN ('open','in_progress') LIMIT 1");
        $st->execute([$kind, $motoId]);
        if ($st->fetchColumn()) return;
        $pdo->prepare("INSERT INTO escalations (kind, severity, moto_id, ref_externa, titulo, detalle, asignado_a)
                       VALUES (?, 'high', ?, ?, ?, ?, 'security')")
            ->execute([$kind, $motoId, $deviceId, $titulo, $detalle]);
    } catch (Throwable $e) { error_log('_gpsOpenEscalationOnce: ' . $e->getMessage()); }
}

function _gpsHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371; // Earth radius km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $r * $c;
}
