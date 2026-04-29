<?php
/**
 * Voltika — Truora iframe host page.
 *
 * Renders the Truora identity iframe inside an isolated browsing context
 * (its own window/document, separate from the SPA). The configurador SPA
 * embeds THIS file as an iframe; this file then embeds Truora as a
 * nested iframe and forwards Truora's postMessages back up to the SPA.
 *
 * Why: the same Truora iframe URL renders blank when embedded directly
 * in the configurador SPA, but renders correctly when loaded in any
 * standalone page (verified via truora-test-personal.php — works in
 * every wrapper combination including the SPA's exact DOM nesting and
 * CSS). Conclusion: something in the SPA's window context (one of the
 * many JS modules loaded during the credit flow) interferes with the
 * Truora iframe. Wrapping Truora in a same-origin host iframe gives it
 * a fresh window scope and dodges the interference completely.
 *
 * Usage from the SPA:
 *   <iframe src="php/truora-iframe-host.php?u=<urlencoded iframe_url>">
 *
 * The host listens to Truora postMessages and forwards each one to the
 * SPA via window.parent.postMessage with a `__from_truora_host` flag so
 * paso-credito-identidad.js can recognise and unwrap them.
 */
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika · Truora</title>
<style>
html,body{margin:0;padding:0;height:100%;background:#fff;}
#vk-host-iframe{display:block;width:100%;height:100%;border:0;background:#fff;}
#vk-host-error{font-family:system-ui,-apple-system,sans-serif;padding:30px;color:#991b1b;font-size:14px;}
</style>
</head>
<body>
<iframe id="vk-host-iframe"
        allow="camera; microphone; geolocation; payment; clipboard-write"
        allowfullscreen
        referrerpolicy="origin-when-cross-origin"></iframe>
<script>
(function(){
    var truoraUrl = new URLSearchParams(location.search).get('u') || '';
    var f = document.getElementById('vk-host-iframe');

    if (!truoraUrl) {
        document.body.innerHTML = '<div id="vk-host-error">Falta el parámetro <code>u</code> con el URL del iframe.</div>';
        return;
    }

    // Notify parent of host lifecycle so the SPA can show a precise debug
    // trail without losing the events captured by Truora itself.
    function tellParent(msg) {
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ __from_truora_host: true, host_event: msg }, '*');
            }
        } catch(e) {}
    }

    f.addEventListener('load', function(){ tellParent('host-iframe-load'); });
    f.addEventListener('error', function(){ tellParent('host-iframe-error'); });

    // Forward EVERY postMessage that arrives in this host window (which
    // is where Truora's iframe will post into) up to the SPA. The SPA
    // unwraps messages tagged with __from_truora_host.
    window.addEventListener('message', function(ev) {
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    __from_truora_host: true,
                    origin: ev.origin || '',
                    data:   ev.data
                }, '*');
            }
        } catch(e) {}
    });

    // Set the src AFTER attaching listeners (no cache-bust — the JWT in the
    // URL is unique per call already).
    f.src = truoraUrl;
    tellParent('host-ready');
})();
</script>
</body>
</html>
