<?php
/**
 * GET — Pre-fill data for the PAGARÉ signing form in punto-entrega.js stepPagare.
 *
 * Round 111 (2026-05-27). Returns auto-populated CURP, partial address, OTP
 * status, credit amounts, maturity date, and vehicle info so the punto
 * operator can verify/edit before the customer signs.
 *
 * Auth: admin or punto session.
 * Input: ?moto_id=N
 * Response: { ok, curp, curp_source, nombre, address, otp, amounts, maturity_date, vehicle }
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$uid = 0;
if (!empty($_SESSION['admin_user_id'])) {
    $uid = adminRequireAuth(['admin','cedis']);
} else {
    @session_write_close();
    @session_name('VOLTIKA_PUNTO');
    @session_start();
    if (!empty($_SESSION['punto_user_id'])) {
        $uid = (int)$_SESSION['punto_user_id'];
    } else {
        adminJsonOut(['error' => 'No autorizado'], 401);
    }
}

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// ── Moto ────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$st->execute([$motoId]);
$moto = $st->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$email = (string)($moto['cliente_email']    ?? '');
$tel   = (string)($moto['cliente_telefono'] ?? '');

// ── CURP ────────────────────────────────────────────────────────────────
$curp = '';
$curpSource = '';
$dob = '';
$truoraPayload = null;
// 1) clientes table
try {
    if (!empty($moto['cliente_id'])) {
        $cq = $pdo->prepare("SELECT curp FROM clientes WHERE id = ?");
        $cq->execute([(int)$moto['cliente_id']]);
        $curp = strtoupper(trim((string)($cq->fetchColumn() ?: '')));
        if ($curp !== '') $curpSource = 'clientes';
    }
} catch (Throwable $e) {}
// 2) verificaciones_identidad (Truora) — find latest row WITH verified_curp.
// Carlos had 11+ rows where most were empty retries; only id=69 had real data.
// Fallback: latest row with raw_truora_payload (if verified_curp extraction
// failed on Truora's end, the payload still has the INE data).
try {
    if ($email !== '' || $tel !== '') {
        $vc = null;
        // First: row with verified_curp populated
        $vq = $pdo->prepare("SELECT verified_curp, expected_curp, raw_truora_payload
            FROM verificaciones_identidad
            WHERE ((LENGTH(?) > 0 AND email = ?) OR (LENGTH(?) > 0 AND telefono = ?))
              AND verified_curp IS NOT NULL AND verified_curp <> ''
            ORDER BY id DESC LIMIT 1");
        $vq->execute([$email, $email, $tel, $tel]);
        $vc = $vq->fetch(PDO::FETCH_ASSOC) ?: null;
        // Fallback: row with payload but no verified_curp
        if (!$vc) {
            $vq2 = $pdo->prepare("SELECT verified_curp, expected_curp, raw_truora_payload
                FROM verificaciones_identidad
                WHERE ((LENGTH(?) > 0 AND email = ?) OR (LENGTH(?) > 0 AND telefono = ?))
                  AND raw_truora_payload IS NOT NULL AND raw_truora_payload <> ''
                ORDER BY id DESC LIMIT 1");
            $vq2->execute([$email, $email, $tel, $tel]);
            $vc = $vq2->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        if ($curp === '' && $vc) {
            $curp = strtoupper(trim((string)($vc['verified_curp'] ?: ($vc['expected_curp'] ?? ''))));
            if ($curp !== '') $curpSource = 'verificaciones_identidad';
        }
        if (!empty($vc['raw_truora_payload'])) {
            $truoraPayload = json_decode((string)$vc['raw_truora_payload'], true);
        }
    }
} catch (Throwable $e) {}

// ── Address — parse from Truora INE OCR payload (residence_address), then
// fall back to transacciones for state/CP only. Truora captures the full
// address from the customer's INE — street, number, colonia, CP, alcaldía,
// state — so we extract it via regex.
$address = ['calle'=>'','num_exterior'=>'','num_interior'=>'','colonia'=>'','alcaldia'=>'','estado'=>'','cp'=>''];
$addressSource = '';

// Helper: extract Truora document_validation block from any payload shape
$_findDocVal = function($p) use (&$_findDocVal) {
    if (!is_array($p)) return null;
    if (isset($p['document_validation']) && is_array($p['document_validation'])) return $p['document_validation'];
    foreach ($p as $v) {
        if (is_array($v)) {
            $r = $_findDocVal($v);
            if ($r) return $r;
        }
    }
    return null;
};

// Geolocation captured by Truora at signing time — top-level field in payload
$geolat = '';
$geolng = '';
if ($truoraPayload && !empty($truoraPayload['geolocation_device'])) {
    $g = (string)$truoraPayload['geolocation_device'];
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $g, $gm)) {
        $geolat = $gm[1];
        $geolng = $gm[2];
    }
}

if ($truoraPayload) {
    $doc = $_findDocVal($truoraPayload);
    if ($doc) {
        $resAddr = trim((string)($doc['residence_address'] ?? ''));
        $cp      = trim((string)($doc['postal_code']       ?? ''));
        $alc     = trim((string)($doc['municipality_name'] ?? ''));
        $est     = trim((string)($doc['state_name']        ?? ''));
        $dob     = trim((string)($doc['date_of_birth']     ?? ''));

        // Parse residence_address — typical INE format:
        // "C NORTE 80 4748 COL NUEVA TENOCHTITLAN 07890 GUSTAVO A. MADERO, CDMX"
        // pattern: <street> COL <colonia> <CP> <alcaldia>, <state>
        if ($resAddr !== '' && preg_match('/^(.+?)\s+COL\s+(.+?)\s+(\d{5})\s+(.+?),\s*(.+)$/i', $resAddr, $m)) {
            $streetPart = trim($m[1]);
            $address['colonia']  = trim($m[2]);
            $address['cp']       = trim($m[3]);
            $address['alcaldia'] = trim($m[4]);
            $address['estado']   = trim($m[5]);
            // Try to split street into name + numbers: last numeric tokens = number
            if (preg_match('/^(.+?)\s+(\d+(?:\s+\d+)?)$/', $streetPart, $sm)) {
                $address['calle']        = trim($sm[1]);
                $address['num_exterior'] = trim($sm[2]);
            } else {
                $address['calle'] = $streetPart;
            }
            $addressSource = 'truora_ine_ocr';
        } elseif ($resAddr !== '') {
            // Couldn't parse — at least preserve the full string and INE-OCR fields
            $address['calle']    = $resAddr;
            $address['cp']       = $cp;
            $address['alcaldia'] = $alc;
            $address['estado']   = $est;
            $addressSource = 'truora_ine_ocr_raw';
        }
        // Always overlay CP/alcaldia/estado if they came from Truora separately
        if ($address['cp'] === '' && $cp !== '')       $address['cp'] = $cp;
        if ($address['alcaldia'] === '' && $alc !== '') $address['alcaldia'] = $alc;
        if ($address['estado'] === '' && $est !== '')   $address['estado'] = $est;
    }
}

// Fall back to transacciones if Truora didn't yield CP / state
if ($address['estado'] === '' || $address['cp'] === '') {
    try {
        $trans = null;
        if (!empty($moto['transaccion_id'])) {
            $tq = $pdo->prepare("SELECT ciudad, estado, cp FROM transacciones WHERE id = ?");
            $tq->execute([(int)$moto['transaccion_id']]);
            $trans = $tq->fetch(PDO::FETCH_ASSOC);
        }
        if (!$trans && $email !== '') {
            $tq = $pdo->prepare("SELECT ciudad, estado, cp FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 1");
            $tq->execute([$email]);
            $trans = $tq->fetch(PDO::FETCH_ASSOC);
        }
        if ($trans) {
            if ($address['estado'] === '') $address['estado'] = trim((string)($trans['estado'] ?? ''));
            if ($address['cp'] === '')     $address['cp']     = trim((string)($trans['cp'] ?? ''));
            if ($addressSource === '')     $addressSource = 'transacciones';
        }
    } catch (Throwable $e) {}
}

// Normalize legacy "Distrito Federal" → "Ciudad de México"
if (preg_match('/distrito\s*federal/i', $address['estado'])) $address['estado'] = 'Ciudad de México';
if (strtoupper($address['estado']) === 'CDMX') $address['estado'] = 'Ciudad de México';

// ── OTP status ──────────────────────────────────────────────────────────
$otp = ['verified' => false, 'verified_at' => null, 'code' => null];
try {
    $oq = $pdo->prepare("SELECT otp_verified, otp_verified_at, otp_code
                            FROM entregas WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
    $oq->execute([$motoId]);
    $or = $oq->fetch(PDO::FETCH_ASSOC) ?: [];
    $otp['verified']    = (int)($or['otp_verified'] ?? 0) === 1;
    $otp['verified_at'] = $or['otp_verified_at'] ?? null;
    $otp['code']        = $or['otp_code'] ?? null;
} catch (Throwable $e) {}

// ── Credit amounts ──────────────────────────────────────────────────────
$catalogo = [
    'M05' => 48260, 'M03' => 39900, 'Ukko S+' => 89900,
    'MC10 Streetx' => 109900, 'MC10' => 109900,
    'Pesgo Plus' => 36600, 'Mino-B' => 41820, 'mino B' => 41820,
];
$precioContado = floatval($moto['precio_venta'] ?? 0);
if (!$precioContado) {
    $modelo = (string)($moto['modelo'] ?? '');
    $precioContado = $catalogo[$modelo] ?? 0;
    if (!$precioContado) {
        foreach ($catalogo as $k => $v) {
            if (stripos($modelo, $k) !== false) { $precioContado = $v; break; }
        }
    }
}

$enganche = 0; $pagoSemanal = 0; $numPagos = 0; $plazoMeses = 36;
// Enganche from transacciones
try {
    $tq2 = null;
    if (!empty($moto['transaccion_id'])) {
        $tq2 = $pdo->prepare("SELECT total FROM transacciones WHERE id = ?");
        $tq2->execute([(int)$moto['transaccion_id']]);
    } elseif ($email !== '') {
        $tq2 = $pdo->prepare("SELECT total FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 1");
        $tq2->execute([$email]);
    }
    if ($tq2) $enganche = floatval($tq2->fetchColumn() ?: 0);
} catch (Throwable $e) {}

// Credit plan from subscripciones_credito
try {
    $sq = null;
    if (!empty($moto['cliente_id'])) {
        $sq = $pdo->prepare("SELECT monto_semanal, plazo_meses, plazo_semanas, enganche
            FROM subscripciones_credito WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $sq->execute([(int)$moto['cliente_id']]);
    } elseif ($email !== '') {
        $sq = $pdo->prepare("SELECT sc.monto_semanal, sc.plazo_meses, sc.plazo_semanas, sc.enganche
            FROM subscripciones_credito sc JOIN clientes c ON c.id = sc.cliente_id
            WHERE c.email = ? ORDER BY sc.id DESC LIMIT 1");
        $sq->execute([$email]);
    }
    if ($sq) {
        $cr = $sq->fetch(PDO::FETCH_ASSOC) ?: [];
        $pagoSemanal = floatval($cr['monto_semanal'] ?? 0);
        $plazoMeses  = intval($cr['plazo_meses'] ?? 36);
        $plazoSem    = intval($cr['plazo_semanas'] ?? 0);
        $numPagos    = $plazoSem > 0 ? $plazoSem : (int)round($plazoMeses * 4.33);
        if (!empty($cr['enganche'])) $enganche = floatval($cr['enganche']);
    }
} catch (Throwable $e) {}

$totalOperacion = $enganche + ($pagoSemanal > 0 && $numPagos > 0
    ? round($pagoSemanal * $numPagos, 2) : 0);
if ($totalOperacion <= 0 && $precioContado > 0) $totalOperacion = $precioContado;

$maturityDate = date('Y-m-d', strtotime("+{$plazoMeses} months"));

// ── Customer name ───────────────────────────────────────────────────────
$nombre = trim((string)($moto['cliente_nombre'] ?? ''));
if ($nombre === '') $nombre = 'Cliente';

// ── Vehicle ─────────────────────────────────────────────────────────────
$vehicle = [
    'modelo' => (string)($moto['modelo'] ?? ''),
    'color'  => (string)($moto['color']  ?? ''),
    'vin'    => (string)($moto['vin_display'] ?? $moto['vin'] ?? ''),
    'anio'   => (string)($moto['anio_modelo'] ?? date('Y')),
];

// Derive RFC base from CURP — first 4 letters + 6 birth-date digits.
// The Mexican RFC for personas físicas is 4-letter-name + YYMMDD + 3-char homoclave.
// The first 10 chars are the same as CURP's first 10 (same name + birth-date rules).
// The 3-char homoclave requires a SAT lookup we don't have; emit just the base.
$rfc = '';
if ($curp !== '' && preg_match('/^([A-Z]{4}\d{6})/', $curp, $rm)) {
    $rfc = $rm[1];  // 10-char RFC base, legally identifiable without homoclave
}

adminJsonOut([
    'ok'             => true,
    'curp'           => $curp,
    'curp_source'    => $curpSource,
    'rfc'            => $rfc,
    'fecha_nacimiento' => $dob,
    'nombre'         => $nombre,
    'address'        => $address,
    'address_source' => $addressSource,
    'geolat'         => $geolat ?? '',
    'geolng'         => $geolng ?? '',
    'otp'            => $otp,
    'amounts'        => [
        'enganche'        => $enganche,
        'pago_semanal'    => $pagoSemanal,
        'num_pagos'       => $numPagos,
        'total_operacion' => $totalOperacion,
        'plazo_meses'     => $plazoMeses,
    ],
    'maturity_date'  => $maturityDate,
    'vehicle'        => $vehicle,
]);
