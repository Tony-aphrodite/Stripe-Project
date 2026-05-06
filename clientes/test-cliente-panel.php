<?php
/**
 * Automated test runner for the customer-facing client panel.
 *
 * Calls each backend endpoint with the seeded test phone (5500000000)
 * and renders PASS/FAIL per feature. No Stripe, no real SMS, no manual
 * UI clicks required — just open the page and read the report.
 *
 * Customer brief 2026-05-06: customer wants to verify that client-panel
 * features still work after the 31-item refactor without going through
 * the full configurator/Stripe flow.
 *
 * URL: /clientes/test-cliente-panel.php
 *
 * Prereqs (already covered by seed-test-5500000000.php?run=1):
 *   - clientes row for 5500000000 / diag-test@voltika.mx
 *   - transacciones TEST-5500-CONTADO-1 (CONTADO, paid)
 *   - subscripciones_credito (active, Pesgo plus, $554/sem)
 *   - pagos_credito TEST-5500-CREDITO-1 (156 weeks)
 *   - preaprobaciones row (PREAPROBADO, score 580)
 *
 * The runner hits these endpoints from the BROWSER side (so cookies +
 * sessions match the real customer experience): the page renders a
 * stub then JS fetches each URL and updates the table.
 */

// Resolve base URL so the AJAX calls in the page hit the same origin.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
$base   = $scheme . '://' . $host;

$TEST_TEL          = '5500000000';
$TEST_EMAIL        = 'diag-test@voltika.mx';
$TEST_OTP          = '123456';
$PEDIDO_CREDITO    = 'TEST-5500-CREDITO-1';
$PEDIDO_CONTADO    = 'TEST-5500-CONTADO-1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Test runner — Client panel</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:32px;max-width:920px;margin:0 auto;line-height:1.5}
h1{color:#22d37a;font-size:22px;margin-bottom:8px}
h2{color:#9aa7b7;font-size:13px;text-transform:uppercase;letter-spacing:1px;margin:24px 0 12px}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:20px;margin-bottom:16px}
.row{display:grid;grid-template-columns:60px 1fr 90px;gap:14px;padding:12px 0;border-bottom:1px solid #202a36;align-items:center}
.row:last-child{border-bottom:0}
.row .num{color:#9aa7b7;font-weight:700;font-family:monospace}
.row .desc{color:#eef2f7}
.row .desc small{display:block;color:#9aa7b7;font-size:11px;font-family:monospace;margin-top:3px}
.status{padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;text-align:center}
.status.pass{background:rgba(34,211,122,.15);color:#22d37a;border:1px solid rgba(34,211,122,.4)}
.status.fail{background:rgba(255,140,140,.15);color:#ff8c8c;border:1px solid rgba(255,140,140,.4)}
.status.run {background:rgba(245,179,1,.15);color:#facc15;border:1px solid rgba(245,179,1,.4);animation:pulse 1s infinite}
.status.skip{background:rgba(154,167,183,.15);color:#9aa7b7;border:1px solid rgba(154,167,183,.4)}
@keyframes pulse{50%{opacity:.5}}
.detail{grid-column:1/-1;background:#0b0f14;border:1px solid #202a36;border-radius:6px;padding:8px 12px;margin-top:8px;font-family:monospace;font-size:11px;color:#b7f2cf;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow:auto;display:none}
.detail.show{display:block}
.detail.error{color:#ff8c8c}
.summary{background:#11161d;border:2px solid #22d37a;border-radius:12px;padding:20px;margin-bottom:16px;text-align:center}
.summary.fail-state{border-color:#ff8c8c}
.summary .big{font-size:36px;font-weight:900;color:#22d37a}
.summary.fail-state .big{color:#ff8c8c}
.btn{background:#22d37a;color:#04120a;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;margin-right:8px}
.btn.alt{background:#3b82f6;color:#fff}
code{background:#202a36;padding:2px 6px;border-radius:4px;font-size:12px}
.warn{background:rgba(245,179,1,.1);border:1px solid rgba(245,179,1,.35);color:#ffe19a;padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
</style>
</head>
<body>

<h1>🤖 Client Panel — Test Runner Automatizado</h1>
<p style="color:#9aa7b7;margin-bottom:16px">Verifica todos los endpoints del panel cliente con datos de prueba seeded (5500000000). Sin Stripe, sin SMS, sin clicks manuales.</p>

<div class="summary" id="summary">
  <div class="big" id="sumBig">—</div>
  <div id="sumLine">Iniciando…</div>
</div>

<div class="box">
  <div style="margin-bottom:14px"><strong>Configuración del test:</strong>
    <div style="margin-top:6px;font-size:13px;color:#9aa7b7">
      Teléfono: <code><?= htmlspecialchars($TEST_TEL) ?></code> ·
      Email: <code><?= htmlspecialchars($TEST_EMAIL) ?></code> ·
      OTP esperado: <code><?= htmlspecialchars($TEST_OTP) ?></code>
    </div>
  </div>
  <button class="btn" onclick="runAll()">▶ Ejecutar tests</button>
  <button class="btn alt" onclick="window.open('seed-test-5500000000.php', '_blank')">⚙ Re-seed datos</button>
</div>

<h2>Endpoints del panel cliente</h2>
<div class="box" id="resultBox">
  <!-- Tests rendered here -->
</div>

<div class="warn">
  ⚠ Si algún test falla, click en su fila para ver el response completo y diagnosticar.
  <br>⚠ Borra <code>seed-test-5500000000.php</code> + <code>test-cliente-panel.php</code> tras terminar las pruebas.
</div>

<script>
const BASE = <?= json_encode($base) ?>;
const TEST_TEL = <?= json_encode($TEST_TEL) ?>;
const TEST_EMAIL = <?= json_encode($TEST_EMAIL) ?>;
const TEST_OTP = <?= json_encode($TEST_OTP) ?>;
const PEDIDO_CREDITO = <?= json_encode($PEDIDO_CREDITO) ?>;
const PEDIDO_CONTADO = <?= json_encode($PEDIDO_CONTADO) ?>;

// Each test: {id, desc, endpoint_label, fn(): Promise<{ok:bool, msg, raw}>}
const TESTS = [
  {
    id: 'otp_send',
    desc: 'OTP bypass — enviar-otp.php returns test_mode:true + testCode:123456 for whitelisted phone',
    endpoint: 'POST /configurador/php/enviar-otp.php',
    async fn() {
      const r = await fetch(BASE + '/configurador/php/enviar-otp.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({telefono: TEST_TEL, nombre: 'Test'})
      });
      const data = await r.json();
      const ok = data.test_mode === true && data.testCode === TEST_OTP;
      return {ok, msg: ok ? 'OTP test mode activo, código fijo recibido' : 'test_mode=' + data.test_mode + ', testCode=' + data.testCode, raw: data};
    }
  },
  {
    id: 'otp_verify',
    desc: 'OTP verify — verificar-otp.php accepts 123456 for the whitelisted phone',
    endpoint: 'POST /configurador/php/verificar-otp.php',
    async fn() {
      const r = await fetch(BASE + '/configurador/php/verificar-otp.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({telefono: TEST_TEL, codigo: TEST_OTP})
      });
      const data = await r.json();
      const ok = data.valido === true;
      return {ok, msg: ok ? 'Código aceptado, valido=true' : 'valido=' + data.valido + ' error=' + (data.error||''), raw: data};
    }
  },
  {
    id: 'credito_buscar',
    desc: 'mi-credito.html busca — cliente-credito.php returns credit dashboard data for TEST-5500-CREDITO-1',
    endpoint: 'POST /configurador/php/cliente-credito.php (accion=buscar)',
    async fn() {
      const r = await fetch(BASE + '/configurador/php/cliente-credito.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({accion: 'buscar', pedido_num: PEDIDO_CREDITO})
      });
      const data = await r.json();
      const cr = data.credito || {};
      const ok = data.ok === true && cr.modelo && Number(cr.pago_semanal) > 0;
      return {ok, msg: ok ? `Modelo ${cr.modelo}, $${cr.pago_semanal}/sem, ${cr.semanas_total} semanas` : 'ok=' + data.ok + ' error=' + (data.error||''), raw: data};
    }
  },
  {
    id: 'credito_historial',
    desc: 'mi-credito.html historial — weekly payment schedule has at least 1 row',
    endpoint: 'POST /configurador/php/cliente-credito.php (accion=historial)',
    async fn() {
      const r = await fetch(BASE + '/configurador/php/cliente-credito.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({accion: 'historial', pedido_num: PEDIDO_CREDITO})
      });
      const data = await r.json();
      const rows = (data.historial || []);
      const ok = data.ok === true && rows.length > 0;
      return {ok, msg: ok ? `${rows.length} semanas en el historial` : 'ok=' + data.ok + ' error=' + (data.error||''), raw: data};
    }
  },
  {
    id: 'mi_credito_html',
    desc: 'mi-credito.html — page loads (HTTP 200, contains expected markup)',
    endpoint: 'GET /configurador/mi-credito.html',
    async fn() {
      const r = await fetch(BASE + '/configurador/mi-credito.html');
      const txt = await r.text();
      const ok = r.ok && txt.includes('Mi Crédito') && txt.includes('buscarCredito');
      return {ok, msg: ok ? `HTTP ${r.status}, markup OK` : `HTTP ${r.status}, markup mismatch`, raw: txt.substring(0, 400)};
    }
  },
  {
    id: 'otp_send_real',
    desc: 'OTP bypass NO se aplica a teléfonos NO-whitelisted (seguridad producción)',
    endpoint: 'POST /configurador/php/enviar-otp.php (5544332211)',
    async fn() {
      const r = await fetch(BASE + '/configurador/php/enviar-otp.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({telefono: '5544332211', nombre: 'NotTest'})
      });
      const data = await r.json();
      // Real number must NOT get test_mode flag
      const ok = !data.test_mode;
      return {ok, msg: ok ? 'Teléfono real NO recibió bypass (correcto)' : 'BYPASS LEAK: test_mode=true en teléfono no whitelisted', raw: data};
    }
  },
  {
    id: 'preap_record',
    desc: 'preaprobacion seeded — admin Solicitudes verá la fila [TEST] Voltika Diag',
    endpoint: 'POST /admin/php/preaprobaciones/listar.php (admin auth)',
    skip: true,
    async fn() { return {ok: true, msg: 'Skip — requiere auth admin (verifica manualmente en /admin/)', raw: null}; }
  },
];

const box = document.getElementById('resultBox');

function renderRow(t, status, msg, raw) {
  const id = 'row-' + t.id;
  let row = document.getElementById(id);
  if (!row) {
    row = document.createElement('div');
    row.className = 'row';
    row.id = id;
    row.innerHTML = `
      <div class="num">${t.id}</div>
      <div class="desc">${t.desc}<small>${t.endpoint}</small></div>
      <div><span class="status run">…</span></div>
      <div class="detail"></div>
    `;
    box.appendChild(row);
  }
  const stEl = row.querySelector('.status');
  const dt = row.querySelector('.detail');
  stEl.className = 'status ' + status;
  stEl.textContent = status === 'pass' ? '✓ PASS' : status === 'fail' ? '✗ FAIL' : status === 'skip' ? 'SKIP' : '…';
  if (msg) {
    dt.textContent = msg + (raw ? '\n\n--- response ---\n' + (typeof raw === 'string' ? raw : JSON.stringify(raw, null, 2)) : '');
    dt.classList.toggle('error', status === 'fail');
  }
  row.onclick = () => dt.classList.toggle('show');
}

async function runAll() {
  box.innerHTML = '';
  TESTS.forEach(t => renderRow(t, 'run', '', null));

  let pass = 0, fail = 0, skip = 0;
  for (const t of TESTS) {
    if (t.skip) {
      const r = await t.fn();
      renderRow(t, 'skip', r.msg, r.raw);
      skip++;
      continue;
    }
    try {
      const r = await t.fn();
      renderRow(t, r.ok ? 'pass' : 'fail', r.msg, r.raw);
      r.ok ? pass++ : fail++;
    } catch (e) {
      renderRow(t, 'fail', 'Exception: ' + e.message, e.stack);
      fail++;
    }
  }

  const sum = document.getElementById('summary');
  const big = document.getElementById('sumBig');
  const line = document.getElementById('sumLine');
  sum.classList.toggle('fail-state', fail > 0);
  big.textContent = fail === 0 ? '✅ TODO OK' : `❌ ${fail} FAIL`;
  line.textContent = `${pass} PASS · ${fail} FAIL · ${skip} SKIP de ${TESTS.length} tests`;
}

// Auto-run on load
window.addEventListener('DOMContentLoaded', runAll);
</script>

</body>
</html>
