<?php
/**
 * Voltika — Truora iframe personal test bench.
 *
 * Lets the developer/owner test the Truora iframe without consuming a
 * fresh API token on every reload. The first call generates a token and
 * caches it in sessionStorage for ~50 minutes. Subsequent reloads /
 * "rerun iframe" clicks reuse the same URL → 0 additional API calls →
 * 0 cost, 0 CDC impact.
 *
 * Reproduces the real-flow environment as closely as possible:
 *   - Same origin (voltika.mx) → same frame-ancestors enforcement
 *   - Same iframe construction code as paso-credito-identidad.js
 *     (set src → append, no cache-bust, full allow attributes)
 *   - Realistic state (phone/name/email like a real customer)
 *   - SPA-style container so DOM nesting matches real flow
 *
 * Access: ?token=voltika_diag_2026 OR admin login.
 *
 * URL: https://voltika.mx/configurador_prueba/php/truora-test-personal.php?token=voltika_diag_2026
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$adminOk  = !empty($_SESSION['admin_user_id']);
$tokenOk  = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;">';
    echo '<h2>Truora test bench — acceso protegido</h2>';
    echo '<p>Use: <code>?token=' . htmlspecialchars($expectedToken) . '</code></p>';
    echo '<p><a href="?token=' . urlencode($expectedToken) . '">▶ Abrir</a></p>';
    exit;
}

// Default fake-but-realistic data. Customise via query string.
$defaults = [
    'cliente_id' => $_GET['cliente_id'] ?? '5500000001',
    'nombre'     => $_GET['nombre']     ?? 'Test',
    'apellidos'  => $_GET['apellidos']  ?? 'Voltika QA',
    'telefono'   => $_GET['telefono']   ?? '5500000001',
    'email'      => $_GET['email']      ?? 'qa@voltika.mx',
    'curp'       => $_GET['curp']       ?? 'XAXX010101HDFAAA01',
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Truora · Test Personal (sin gasto)</title>
<!-- Load the SAME CSS files as the real SPA so the iframe environment
     reproduces the configurador as faithfully as possible. -->
<link rel="stylesheet" href="../css/configurador-variables.css">
<link rel="stylesheet" href="../css/configurador.css">
<style>
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0c2340;margin:0;padding:18px;line-height:1.5;}
.wrap{max-width:1100px;margin:0 auto;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:12px;color:#64748b;margin:0 0 22px;text-transform:uppercase;letter-spacing:.5px;font-weight:500;}
.card{background:#fff;padding:16px 18px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:14px;}
.row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;}
.row label{flex:1 1 160px;font-size:12px;color:#475569;}
.row label small{display:block;color:#94a3b8;font-weight:400;}
.row input{width:100%;padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:ui-monospace,Menlo,monospace;}
.btn{padding:10px 16px;background:#039fe1;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;margin:4px 4px 4px 0;}
.btn.green{background:#22c55e;}
.btn.amber{background:#f59e0b;}
.btn.gray{background:#64748b;}
.btn.red{background:#ef4444;}
.tip{background:#fef3c7;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:6px;font-size:12.5px;color:#78350f;margin-top:8px;}
#vk-truora-container{position:relative;width:100%;min-height:720px;border-radius:14px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0;margin-top:14px;}
#vk-truora-debug{margin-top:14px;padding:10px 12px;background:#0f172a;color:#94a3b8;border-radius:8px;font-family:ui-monospace,Menlo,monospace;font-size:11px;line-height:1.55;max-height:340px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;}
.kbd{background:#1e293b;color:#e2e8f0;padding:2px 7px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;}
.status{display:inline-block;padding:2px 9px;border-radius:4px;font-weight:700;font-size:11px;}
.status.cached{background:#dcfce7;color:#14532d;}
.status.fresh{background:#fef3c7;color:#78350f;}
/* Reproduce-the-SPA-bug wrapper: applies the EXACT same animation/transform
   the configurador's .vk-paso--active uses, so we can prove the iframe
   blank-paint bug is caused by a transformed parent. */
.spa-wrapper {
    animation: spaSlideIn 0.35s ease forwards;
}
@keyframes spaSlideIn {
    from { opacity: 0; transform: translateX(30px); }
    to   { opacity: 1; transform: translateX(0); }
}
</style>
</head>
<body>
<div class="wrap">

<h1>🧪 Truora iframe · banco de pruebas personal</h1>
<h2>1 token = pruebas ilimitadas (~50 min) · 0 costos extra</h2>

<div class="card">
  <p style="margin:0 0 12px;font-size:13px;">Datos de prueba (no se persisten — solo se envían a <span class="kbd">truora-token.php</span> al generar token).</p>
  <div class="row">
    <label>cliente_id<small>= account_id base</small><input id="f-cliente_id" value="<?= htmlspecialchars($defaults['cliente_id']) ?>"></label>
    <label>nombre<input id="f-nombre" value="<?= htmlspecialchars($defaults['nombre']) ?>"></label>
    <label>apellidos<input id="f-apellidos" value="<?= htmlspecialchars($defaults['apellidos']) ?>"></label>
  </div>
  <div class="row">
    <label>telefono<input id="f-telefono" value="<?= htmlspecialchars($defaults['telefono']) ?>"></label>
    <label>email<input id="f-email" value="<?= htmlspecialchars($defaults['email']) ?>"></label>
    <label>curp<input id="f-curp" value="<?= htmlspecialchars($defaults['curp']) ?>"></label>
  </div>
</div>

<div class="card">
  <div>
    <button class="btn green" onclick="renderWithCachedOrFresh()">▶ Render iframe (usa caché si existe)</button>
    <button class="btn amber" onclick="forceFresh()">⟳ Forzar token NUEVO (gasta 1 API)</button>
    <button class="btn gray" onclick="rerunIframeOnly()">↻ Re-render iframe (mismo URL)</button>
    <button class="btn red" onclick="clearAll()">🗑 Limpiar caché + iframe</button>
  </div>
  <div style="margin-top:10px;font-size:13px;display:flex;gap:18px;flex-wrap:wrap;">
    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
      <input type="checkbox" id="repro-transform">
      <span><b>+ transform parent</b> (wrapper con animación translateX).</span>
    </label>
    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
      <input type="checkbox" id="repro-spa-dom">
      <span><b>+ SPA DOM completo</b> (anida iframe en
      <code>.vk-paso.vk-paso--active &gt; .vk-paso__content &gt; #vk-credito-identidad-container</code>
      como el flujo real). Marca esto para reproducir la falla del SPA.</span>
    </label>
    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
      <input type="checkbox" id="use-host">
      <span><b>+ Wrapper host-iframe</b> — embebe Truora a través de
      <code>php/truora-iframe-host.php</code> (solución para el SPA).</span>
    </label>
  </div>
  <div id="cache-status" class="tip" style="margin-top:10px;">Estado: cargando…</div>
</div>

<div id="vk-truora-container">
  <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;">
    Presiona "Render iframe" para iniciar.
  </div>
</div>

<div id="vk-truora-debug">[debug] esperando primer click...</div>

<div class="card" style="margin-top:14px;">
  <strong style="font-size:13px;">📌 Cómo usar para diagnosticar el bug del flujo real:</strong>
  <ol style="margin:8px 0 0 18px;font-size:12.5px;line-height:1.7;">
    <li>Click <b>"Render iframe"</b> → si NO se ve UI de Truora, mira el debug abajo. Capturalo.</li>
    <li>Click <b>"Re-render iframe"</b> → recarga el iframe SIN gastar otro API call. Repite hasta entender.</li>
    <li>El comportamiento aquí debe ser idéntico al flujo real (mismo origen, mismo código de iframe).</li>
    <li>Si aquí <b>SÍ funciona</b> pero el flujo real no → el bug es algo del entorno SPA (algún script interfiere).</li>
    <li>Si aquí <b>NO funciona tampoco</b> → el bug es del token/Truora, no del SPA.</li>
  </ol>
</div>

</div><!-- /wrap -->

<script>
var CACHE_KEY = 'vk_truora_test_cache';
var CACHE_TTL_MS = 50 * 60 * 1000; // 50 min

function dbg(line) {
    var el = document.getElementById('vk-truora-debug');
    if (!el) return;
    if (el.innerText.indexOf('esperando primer') === 0) el.innerText = '';
    el.innerText += '\n' + line;
    el.scrollTop = el.scrollHeight;
}

function getCache() {
    try {
        var raw = sessionStorage.getItem(CACHE_KEY);
        if (!raw) return null;
        var obj = JSON.parse(raw);
        if (!obj || !obj.iframe_url || !obj.ts) return null;
        if (Date.now() - obj.ts > CACHE_TTL_MS) {
            sessionStorage.removeItem(CACHE_KEY);
            return null;
        }
        return obj;
    } catch (e) { return null; }
}

function setCache(iframe_url, account_id, flow_id) {
    sessionStorage.setItem(CACHE_KEY, JSON.stringify({
        iframe_url: iframe_url, account_id: account_id, flow_id: flow_id, ts: Date.now()
    }));
    refreshCacheStatus();
}

function refreshCacheStatus() {
    var c = getCache();
    var el = document.getElementById('cache-status');
    if (c) {
        var ageMin = Math.round((Date.now() - c.ts) / 60000);
        var ttlLeft = Math.round((CACHE_TTL_MS - (Date.now() - c.ts)) / 60000);
        el.innerHTML = '<span class="status cached">✓ TOKEN EN CACHÉ</span> · ' +
            'account=' + c.account_id + ' · flow=' + c.flow_id + ' · ' +
            'edad=' + ageMin + 'min · expira en ~' + ttlLeft + 'min · ' +
            '<b>cada "Re-render" = 0 API calls</b>';
        el.style.background = '#dcfce7'; el.style.borderLeftColor = '#22c55e'; el.style.color = '#14532d';
    } else {
        el.innerHTML = '<span class="status fresh">SIN CACHÉ</span> · ' +
            'el primer "Render iframe" gastará 1 API call. Después: ilimitado.';
    }
}

function buildPayload() {
    return {
        cliente_id: document.getElementById('f-cliente_id').value.trim(),
        nombre:     document.getElementById('f-nombre').value.trim(),
        apellidos:  document.getElementById('f-apellidos').value.trim(),
        telefono:   document.getElementById('f-telefono').value.trim(),
        email:      document.getElementById('f-email').value.trim(),
        curp:       document.getElementById('f-curp').value.trim().toUpperCase()
    };
}

function fetchToken(callback) {
    dbg('POST truora-token.php (¡gasta 1 API call!) @ ' + new Date().toLocaleTimeString());
    fetch('truora-token.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(buildPayload())
    }).then(function(r) { return r.json(); })
    .then(function(j) {
        if (!j || !j.ok || !j.iframe_url) {
            dbg('token NOT OK: ' + JSON.stringify(j).substr(0, 200));
            return;
        }
        dbg('token OK · flow=' + j.flow_id + ' · account=' + j.account_id);
        setCache(j.iframe_url, j.account_id, j.flow_id);
        if (callback) callback(j.iframe_url);
    }).catch(function(e) {
        dbg('FETCH error: ' + e.message);
    });
}

function renderIframe(url) {
    dbg('--- render iframe ---');
    dbg('referrer=' + (document.referrer || '(empty)') + ' · location=' + location.href);
    dbg('URL: ' + url.substr(0, 80) + '...');

    // EXACT same code as paso-credito-identidad.js
    var iframe = document.createElement('iframe');
    iframe.id = 'vk-truora-iframe';
    iframe.allow = 'camera; microphone; geolocation; payment; clipboard-write';
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('referrerpolicy', 'origin-when-cross-origin');
    iframe.setAttribute('style', 'width:100%;height:720px;border:0;display:block;background:#fff;');

    var loadCount = 0;
    iframe.addEventListener('load', function() {
        loadCount++;
        dbg('iframe LOAD #' + loadCount + ' @ ' + new Date().toLocaleTimeString());
    });
    iframe.addEventListener('error', function() {
        dbg('iframe ERROR @ ' + new Date().toLocaleTimeString());
    });

    // If host-mode is on, replace the Truora URL with the host page URL,
    // passing the Truora URL via ?u=. The host page renders Truora inside
    // itself and forwards postMessages to us.
    var useHost = document.getElementById('use-host').checked;
    var srcUrl = useHost ? ('truora-iframe-host.php?u=' + encodeURIComponent(url)) : url;

    iframe.src = srcUrl;
    dbg('src set' + (useHost ? ' [via host]' : ''));
    var c = document.getElementById('vk-truora-container');
    c.innerHTML = '';

    // Optional reproduction wrappers — let us isolate which environment
    // factor in the SPA breaks the iframe.
    //   - repro-transform: wraps in a div with `animation: translateX(...)`
    //     (the .vk-paso--active CSS pattern). Already verified NOT to be
    //     the cause when used alone.
    //   - repro-spa-dom: nests iframe in the SAME DOM hierarchy and class
    //     names that the configurador SPA uses for the credito-identidad
    //     step. Combined with the SPA's CSS (loaded at top of this page),
    //     this should faithfully reproduce the bug.
    var reproT  = document.getElementById('repro-transform').checked;
    var reproDom = document.getElementById('repro-spa-dom').checked;
    var modes = [];

    var anchor = c;
    if (reproDom) {
        // Build the EXACT structure the SPA uses:
        //   <div class="vk-paso vk-paso--active" id="vk-paso-credito-identidad">
        //     <div class="vk-paso__content" id="vk-credito-identidad-container">
        //       <div id="vk-truora-container">{iframe}</div>
        //     </div>
        //   </div>
        var paso = document.createElement('div');
        paso.className = 'vk-paso vk-paso--active';
        paso.id = 'vk-paso-credito-identidad';
        var content = document.createElement('div');
        content.className = 'vk-paso__content';
        content.id = 'vk-credito-identidad-container';
        var inner = document.createElement('div');
        inner.id = 'vk-truora-container';
        inner.style.cssText = 'position:relative;width:100%;min-height:720px;border-radius:14px;overflow:hidden;background:#f8fafc;border:1px solid #e2e8f0;';
        content.appendChild(inner);
        paso.appendChild(content);
        c.appendChild(paso);
        anchor = inner;
        modes.push('SPA-DOM');
    }
    if (reproT) {
        var wrapper = document.createElement('div');
        wrapper.className = 'spa-wrapper';
        wrapper.appendChild(iframe);
        anchor.appendChild(wrapper);
        modes.push('transform');
    } else {
        anchor.appendChild(iframe);
    }
    dbg('iframe appended @ ' + new Date().toLocaleTimeString() +
        (modes.length ? ' [' + modes.join('+') + ']' : ' [plain]'));

    // Probes
    function probe(label) {
        var f = document.getElementById('vk-truora-iframe');
        if (!f) { dbg(label + ': iframe MISSING from DOM'); return; }
        var rect = f.getBoundingClientRect();
        var info = label + ': size=' + Math.round(rect.width) + 'x' + Math.round(rect.height);
        try {
            var loc = f.contentWindow && f.contentWindow.location;
            var href = loc && loc.href;
            info += ' contentLoc=' + (href || 'null');
        } catch (e) {
            info += ' contentLoc=BLOCKED-cross-origin-OK(' + (e.name || '') + ')';
        }
        dbg(info);
    }
    setTimeout(function() { probe('probe@3s'); }, 3000);
    setTimeout(function() { probe('probe@10s'); }, 10000);
    setTimeout(function() { probe('probe@25s'); }, 25000);
}

function renderWithCachedOrFresh() {
    var c = getCache();
    if (c) {
        dbg('Usando token cacheado (0 API calls).');
        renderIframe(c.iframe_url);
    } else {
        fetchToken(renderIframe);
    }
}

function forceFresh() {
    sessionStorage.removeItem(CACHE_KEY);
    fetchToken(renderIframe);
}

function rerunIframeOnly() {
    var c = getCache();
    if (!c) { dbg('Sin caché — primero presiona "Render iframe".'); return; }
    dbg('Re-render con MISMO URL (0 API calls).');
    renderIframe(c.iframe_url);
}

function clearAll() {
    sessionStorage.removeItem(CACHE_KEY);
    document.getElementById('vk-truora-container').innerHTML =
        '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;">Caché limpiada — listo para nuevo test.</div>';
    document.getElementById('vk-truora-debug').innerText = '[debug] caché limpiada · esperando próximo click...';
    refreshCacheStatus();
}

// Capture EVERYTHING we can about postMessages + CSP + service workers.
// Host-mode wraps Truora messages as { __from_truora_host, origin, data };
// log them in a way that surfaces both the wrapper and the inner payload.
window.addEventListener('message', function(ev) {
    var d = ev.data;
    var origin = ev.origin || '?';
    if (d && typeof d === 'object' && d.__from_truora_host) {
        if (d.host_event) {
            dbg('host-event: ' + d.host_event);
            return;
        }
        var inner = d.data;
        var preview = (typeof inner === 'string') ? inner.substr(0, 90)
            : (inner ? JSON.stringify(inner).substr(0, 90) : '(empty)');
        dbg('msg(via host)<' + (d.origin || '?') + '> ' + preview);
        return;
    }
    var preview = '';
    if (typeof d === 'string') preview = d.substr(0, 90);
    else if (d && typeof d === 'object') {
        try { preview = JSON.stringify(d).substr(0, 90); } catch(e) { preview = '[unserializable]'; }
    } else preview = '(empty)';
    dbg('msg<' + origin + '> ' + preview);
});
document.addEventListener('securitypolicyviolation', function(ev) {
    dbg('CSP-violation: ' + ev.violatedDirective + ' blocked=' + ev.blockedURI);
});
try {
    if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
        navigator.serviceWorker.getRegistrations().then(function(regs) {
            dbg('SW: ' + (regs && regs.length ? regs.length + ' registrations' : 'none'));
        });
    }
} catch (e) {}

dbg('UA=' + (navigator.userAgent || '').substr(0, 100));
dbg('cookies=' + navigator.cookieEnabled + ' · 3rd-party may be blocked in private mode');
refreshCacheStatus();
</script>
</body>
</html>
