window.VK_notificaciones = (function(){

  // Icon + human label per notification tipo, matching voltika-notify templates
  var TIPO_META = {
    punto_asignado:        { icon:'📍', label:'Punto asignado' },
    moto_enviada:          { icon:'🚚', label:'Moto enviada' },
    lista_para_recoger:    { icon:'✅', label:'Lista para recoger' },
    otp_entrega:           { icon:'🔐', label:'Código de entrega' },
    acta_firmada:          { icon:'📄', label:'Acta firmada' },
    entrega_completada:    { icon:'🎉', label:'Entrega completada' },
    recepcion_incidencia:  { icon:'⚠️', label:'Incidencia reportada' },
    pago_recibido:         { icon:'💳', label:'Pago recibido' },
    pago_vencido:          { icon:'🚨', label:'Pago vencido' },
    recordatorio_pago:     { icon:'⏰', label:'Recordatorio de pago' }
  };

  function canalBadge(canal){
    var map = { email:'📧 Email', sms:'💬 SMS', whatsapp:'🟢 WhatsApp', push:'🔔 Push' };
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
        '<div class="vk-h1" style="margin:0">🔔 Notificaciones</div>'+
      '</div>'+
      '<div id="vkNotifList"><div class="vk-muted">Cargando…</div></div>'
    );
    VKApp.api('cliente/notificaciones.php').done(function(r){
      var items = (r && r.items) || [];
      if(items.length === 0){
        $('#vkNotifList').html(
          '<div class="vk-card" style="text-align:center;color:#666;padding:32px 16px">'+
            '<div style="font-size:36px;margin-bottom:8px">📭</div>'+
            '<div style="font-weight:700;margin-bottom:4px">Sin notificaciones aún</div>'+
            '<div style="font-size:13px">Cuando enviemos avisos sobre tu moto, pagos o entrega aparecerán aquí.</div>'+
          '</div>'
        );
        return;
      }
      var html = '';
      items.forEach(function(n){
        var meta = TIPO_META[n.tipo] || { icon:'🔔', label:n.tipo };
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
