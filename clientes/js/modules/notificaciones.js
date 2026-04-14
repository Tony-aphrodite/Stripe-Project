window.VK_notificaciones = (function(){

  // Icon + human label per notification tipo, matching voltika-notify templates
  var _svgIcon = function(d){ return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+d+'</svg>'; };
  var TIPO_META = {
    punto_asignado:        { icon:_svgIcon('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>'), label:'Punto asignado' },
    moto_enviada:          { icon:_svgIcon('<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'), label:'Moto enviada' },
    lista_para_recoger:    { icon:_svgIcon('<polyline points="20 6 9 17 4 12"/>'), label:'Lista para recoger' },
    otp_entrega:           { icon:_svgIcon('<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>'), label:'Código de entrega' },
    acta_firmada:          { icon:_svgIcon('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'), label:'Acta firmada' },
    entrega_completada:    { icon:_svgIcon('<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'), label:'Entrega completada' },
    recepcion_incidencia:  { icon:_svgIcon('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'), label:'Incidencia reportada' },
    pago_recibido:         { icon:_svgIcon('<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>'), label:'Pago recibido' },
    pago_vencido:          { icon:_svgIcon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'), label:'Pago vencido' },
    recordatorio_pago:     { icon:_svgIcon('<circle cx="12" cy="13.5" r="8.5"/><polyline points="12 9.5 12 13.5 15 15"/><path d="M5.6 5.6L3 3M18.4 5.6L21 3"/>'), label:'Recordatorio de pago' }
  };

  function canalBadge(canal){
    var map = { email:'Email', sms:'SMS', whatsapp:'WhatsApp', push:'Push' };
    return map[canal] || canal;
  }

  function timeAgo(ts){
    if(!ts) return '';
    var d = new Date(ts.replace(' ', 'T'));
    if(isNaN(d)) return ts;
    var s = Math.floor((Date.now() - d.getTime())/1000);
    if(s < 60)  return 'hace '+s+' s';
    if(s < 3600) return 'hace '+Math.floor(s/60)+' min';
    if(s < 86400) return 'hace '+Math.floor(s/3600)+' h';
    if(s < 86400*7) return 'hace '+Math.floor(s/86400)+' d';
    return d.toLocaleDateString('es-MX',{day:'2-digit',month:'short',year:'numeric'});
  }

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function render(){
    VKApp.render(
      '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">'+
        '<button class="vk-back" onclick="VKApp.go(\'inicio\')" style="background:none;border:none;font-size:20px;cursor:pointer">←</button>'+
        '<div class="vk-h1" style="margin:0">Notificaciones</div>'+
      '</div>'+
      '<div id="vkNotifList"><div class="vk-muted">Cargando…</div></div>'
    );
    VKApp.api('cliente/notificaciones.php').done(function(r){
      var items = (r && r.items) || [];
      if(items.length === 0){
        $('#vkNotifList').html(
          '<div class="vk-card" style="text-align:center;color:#666;padding:32px 16px">'+
            '<div style="margin-bottom:8px"><svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#999" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg></div>'+
            '<div style="font-weight:700;margin-bottom:4px">Sin notificaciones aún</div>'+
            '<div style="font-size:13px">Cuando enviemos avisos sobre tu moto, pagos o entrega aparecerán aquí.</div>'+
          '</div>'
        );
        return;
      }
      var html = '';
      items.forEach(function(n){
        var meta = TIPO_META[n.tipo] || { icon:_svgIcon('<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>'), label:n.tipo };
        var failed = n.status && n.status !== 'sent' && n.status !== 'ok';
        html +=
          '<div class="vk-card vk-notif-item" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:8px'+(failed?';opacity:.6':'')+'">'+
            '<div style="font-size:26px;line-height:1">'+meta.icon+'</div>'+
            '<div style="flex:1;min-width:0">'+
              '<div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">'+
                '<div style="font-weight:700;color:#333">'+escapeHtml(meta.label)+'</div>'+
                '<div style="font-size:11px;color:#888;white-space:nowrap">'+escapeHtml(timeAgo(n.freg))+'</div>'+
              '</div>'+
              '<div style="font-size:13px;color:#555;margin:4px 0;white-space:pre-line;word-wrap:break-word">'+escapeHtml(n.mensaje||'')+'</div>'+
              '<div style="font-size:11px;color:#888">'+escapeHtml(canalBadge(n.canal))+
                (n.destino ? ' · '+escapeHtml(n.destino) : '')+
                (failed ? ' · <span style="color:#dc2626">no entregado</span>' : '')+
              '</div>'+
            '</div>'+
          '</div>';
      });
      $('#vkNotifList').html(html);
    }).fail(function(){
      $('#vkNotifList').html('<div class="vk-card" style="color:#dc2626">No se pudieron cargar las notificaciones.</div>');
    });
  }

  return { render:render };
})();
