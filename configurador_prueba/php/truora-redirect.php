<?php
/**
 * Voltika — Truora post-step redirect landing page.
 *
 * Truora's flow configuration requires a `redirect_url` for steps that
 * navigate the user away from the iframe (notably email-OTP and SMS
 * confirmation links). When Truora navigates to that URL after a step,
 * we MUST NOT land on the SPA itself — the SPA's localStorage-based
 * state restoration would route the user to whatever paso the
 * persisted state points at (the boss's case 2026-04-29: ended up on
 * the Círculo de Crédito consent screen mid-flow).
 *
 * This page is intentionally static: it instructs the user to return
 * to the original tab where the Truora iframe is still loaded, and
 * does NOT trigger any SPA navigation. If the page is opened inside
 * an iframe (Truora's flow can do that for some validations), it
 * tries to close the popup or signal the parent.
 *
 * Reachable at: https://voltika.mx/configurador_prueba/php/truora-redirect.php
 * Truora calls it after email/SMS OTP confirmation.
 */
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika · Verificación confirmada</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0c2340;}
.wrap{max-width:520px;margin:60px auto;padding:36px 24px;background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.05);text-align:center;}
.check{width:64px;height:64px;border-radius:50%;background:#22c55e;color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 16px;}
h1{font-size:20px;margin:0 0 8px;}
p{font-size:14px;color:#475569;margin:8px 0 16px;line-height:1.55;}
.btn{display:inline-block;padding:12px 22px;background:#039fe1;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin-top:8px;}
.muted{font-size:12px;color:#94a3b8;margin-top:18px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="check">&#10003;</div>
  <h1>Verificación recibida</h1>
  <p>Tu confirmación fue procesada correctamente.</p>
  <p><strong>Vuelve a la pestaña original</strong> donde iniciaste la verificación de identidad para continuar con tu compra.</p>
  <button class="btn" onclick="closeOrReturn()">Cerrar / Volver</button>
  <div class="muted">Si esta página apareció en una nueva pestaña, ciérrala y regresa a la pestaña anterior.</div>
</div>

<script>
function closeOrReturn() {
    // Try in order:
    //   1. window.close() — works only for popups opened by JS
    //   2. history.back() — works only if there's a back entry
    //   3. fall back to a plain message
    try {
        window.close();
        // If still here after a tick, fall back.
        setTimeout(function(){
            try { history.back(); } catch(e) {}
        }, 200);
    } catch(e) {
        try { history.back(); } catch(_) {}
    }
}

// If we're inside an iframe (Truora may load us inside its own iframe
// post-OTP), notify the topmost window we completed and stop there —
// do NOT navigate the parent or top.
try {
    if (window.top !== window) {
        window.top.postMessage({ vk_truora_otp_returned: true }, '*');
    }
} catch(e) {}
</script>
</body>
</html>
