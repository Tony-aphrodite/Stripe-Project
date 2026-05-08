<?php
/**
 * POST — Auto-save delivery wizard progress.
 *
 * Bug 5.1 (customer brief 2026-05-08): "The process must auto-save once
 * started, and only finalize after the customer's signature."
 *
 * Each time the wizard advances OR loses focus, the frontend posts here
 * with the current step + a small JSON snapshot. We persist into the
 * existing `entregas` row — no new table — so the next iniciar.php call
 * for the same moto can restore the state.
 *
 * Body: { moto_id, step, step_data (object) }
 *
 * Idempotent: writes are upserts on the latest entregas row for this moto.
 *
 * Backward-compat: if entregas.step / step_data columns are missing (old
 * schema) we silently ALTER them on first call. No existing column is
 * touched.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$step   = trim((string)($d['step'] ?? ''));
$data   = isset($d['step_data']) && is_array($d['step_data']) ? $d['step_data'] : [];
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);
$validSteps = ['step1','step2','step3','step4','step5'];
if (!in_array($step, $validSteps, true)) {
    puntoJsonOut(['error' => 'step inválido'], 400);
}

$pdo = getDB();

// Idempotent migration — adds columns if missing.
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN step VARCHAR(20) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN step_data MEDIUMTEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_motivo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_at DATETIME NULL"); } catch (Throwable $e) {}

// Locate the latest entrega row for this moto (must already exist —
// iniciar.php creates it). We don't auto-create here so we never lose track
// of an OTP that was already issued.
$q = $pdo->prepare("SELECT id FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$q->execute([$motoId]);
$entregaId = (int)($q->fetchColumn() ?: 0);
if (!$entregaId) {
    puntoJsonOut(['error' => 'No hay un proceso de entrega iniciado. Inicia desde el paso 1.'], 409);
}

$stepDataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
$pdo->prepare("UPDATE entregas SET step=?, step_data=? WHERE id=?")
    ->execute([$step, $stepDataJson, $entregaId]);

puntoLog('entrega_paso_guardado', ['moto_id' => $motoId, 'step' => $step]);
puntoJsonOut(['ok' => true, 'entrega_id' => $entregaId, 'step' => $step]);
