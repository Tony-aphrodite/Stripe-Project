window.AD_notificaciones = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Notificaciones</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('notificaciones/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Notificaciones</div><div class="ad-banner err">Error</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-h1">Notificaciones</div>';

    // KPIs
    html += '<div class="ad-kpis">';
    html += kpi('Enviados hoy', r.enviados_hoy, 'blue');
    html += kpi('Enviados semana', r.enviados_semana, 'blue');
    html += kpi('Clientes con Email', r.preferencias.email||0, 'green');
    html += kpi('Clientes con WhatsApp', r.preferencias.whatsapp||0, r.preferencias.whatsapp>0?'green':'yellow');
    html += kpi('Clientes con SMS', r.preferencias.sms||0, 'green');
    html += '</div>';

    // Triggers configuration
    html += '<div class="ad-h2">Triggers de notificación</div>';
    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Trigger</th><th>Descripción</th><th>Canal</th><th>Estado</th>';
    html += '</tr></thead><tbody>';
    (r.triggers||[]).forEach(function(t){
      html += '<tr>';
      html += '<td><code style="font-size:11px;background:var(--ad-surface-2);padding:2px 6px;border-radius:4px;">'+esc(t.tipo)+'</code></td>';
      html += '<td>'+esc(t.label)+'</td>';
      html += '<td><span class="ad-badge blue">'+esc(t.canal)+'</span></td>';
      html += '<td>'+(t.activo?'<span class="ad-badge green">Activo</span>':'<span class="ad-badge yellow">Pendiente</span>')+'</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';

    // WhatsApp status
    html += '<div class="ad-h2">Integración WhatsApp</div>';
    html += '<div class="ad-banner warn">La integración con WhatsApp Business API está pendiente de configuración. '+
      'Se requiere una cuenta de WhatsApp Business verificada y las credenciales de la API de Meta/WhatsApp. '+
      'Los templates de mensaje deben ser aprobados por Meta antes de enviar notificaciones masivas.</div>';

    // Recent log
    html += '<div class="ad-h2">Historial reciente</div>';
    html += '<div class="ad-table-wrap"><div style="overflow-x:auto;"><table class="ad-table"><thead><tr>';
    html += '<th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Canal</th><th>Estado</th>';
    html += '</tr></thead><tbody>';
    (r.recientes||[]).slice(0,30).forEach(function(n){
      html += '<tr>';
      html += '<td style="font-size:12px;">'+(n.freg||'—')+'</td>';
      html += '<td>'+esc(n.cliente_nombre||'ID:'+n.cliente_id)+'</td>';
      html += '<td><code style="font-size:11px;">'+esc(n.tipo||'—')+'</code></td>';
      html += '<td>'+esc(n.canal||'sms')+'</td>';
      html += '<td><span class="ad-badge '+(n.enviado?'green':'red')+'">'+(n.enviado?'Enviado':'Error')+'</span></td>';
      html += '</tr>';
    });
    if (!r.recientes||!r.recientes.length) html += '<tr><td colspan="5" class="ad-dim" style="text-align:center">Sin historial</td></tr>';
    html += '</tbody></table></div></div>';

    ADApp.render(html);
  }

  function kpi(label,value,cls){
    return '<div class="ad-kpi"><div class="label">'+label+'</div><div class="value '+cls+'">'+value+'</div></div>';
  }
  function esc(s){return $('<span>').text(s||'').html();}
  return { render:render };
})();
