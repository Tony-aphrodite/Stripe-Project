window.AD_documentos = (function(){
  var _backBtn = '<button class="ad-back" onclick="ADApp.go(\'dashboard\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg> Volver</button>';
  var _tiposLabel = {
    contrato:'Contrato', acta_entrega:'Acta de entrega', factura:'Factura',
    carta_factura:'Carta factura', seguro:'Seguro', ine:'INE', pagare:'Pagaré'
  };

  function render(){
    ADApp.render(_backBtn+'<div class="ad-h1">Documentos</div><div><span class="ad-spin"></span></div>');
    load();
  }

  function load(){
    ADApp.api('documentos/listar.php').done(paint).fail(function(){
      ADApp.render(_backBtn+'<div class="ad-h1">Documentos</div><div class="ad-banner err">Error al cargar</div>');
    });
  }

  function paint(r){
    var html = _backBtn;
    html += '<div class="ad-toolbar"><div class="ad-h1">Documentos por Cliente</div></div>';

    html += '<div class="ad-table-wrap"><table class="ad-table"><thead><tr>';
    html += '<th>Cliente</th><th>Email</th><th>Teléfono</th><th>Docs subidos</th><th>Verificados</th><th>Completitud</th><th></th>';
    html += '</tr></thead><tbody>';

    (r.clientes||[]).forEach(function(c){
      var pct = c.docs_requeridos > 0 ? Math.round((c.docs_subidos / c.docs_requeridos) * 100) : 0;
      var pctColor = pct >= 100 ? 'green' : (pct >= 50 ? 'yellow' : 'red');
      html += '<tr>';
      html += '<td><strong>'+esc(c.nombre||'—')+'</strong></td>';
      html += '<td>'+esc(c.email||'—')+'</td>';
      html += '<td>'+esc(c.telefono||'—')+'</td>';
      html += '<td>'+c.docs_subidos+'/'+c.docs_requeridos+'</td>';
      html += '<td>'+(c.docs_verificados||0)+'</td>';
      html += '<td><div class="ad-progress" style="width:100px;"><div style="width:'+pct+'%;background:var(--ad-'+pctColor+')"></div></div> <small>'+pct+'%</small></td>';
      html += '<td><button class="ad-btn sm ghost dcVer" data-id="'+c.id+'">Ver docs</button></td>';
      html += '</tr>';
    });
    if (!r.clientes || !r.clientes.length) {
      html += '<tr><td colspan="7" class="ad-dim" style="text-align:center;">Sin clientes registrados</td></tr>';
    }
    html += '</tbody></table></div>';

    ADApp.render(html);
    $('.dcVer').on('click', function(){ showClienteDocs($(this).data('id')); });
  }

  function showClienteDocs(clienteId){
    ADApp.modal('<div class="ad-h2">Documentos del cliente</div><div style="text-align:center;padding:20px;"><span class="ad-spin"></span></div>');
    ADApp.api('documentos/listar.php?cliente_id='+clienteId).done(function(r){
      var docs = r.documentos || [];
      var html = '<div class="ad-h2">Documentos del cliente #'+clienteId+'</div>';

      // Show all doc types with status
      var tiposList = ['contrato','acta_entrega','factura','carta_factura','seguro','ine','pagare'];
      html += '<div style="display:grid;gap:8px;">';
      tiposList.forEach(function(tipo){
        var doc = docs.find(function(d){ return d.tipo === tipo; });
        var label = _tiposLabel[tipo] || tipo;
        if (doc) {
          var estadoBadge = doc.estado === 'verificado' ? '<span class="ad-badge green">Verificado</span>'
            : (doc.estado === 'subido' ? '<span class="ad-badge blue">Subido</span>' : '<span class="ad-badge yellow">Pendiente</span>');
          html += '<div class="ad-card" style="padding:12px;margin:0;display:flex;align-items:center;justify-content:space-between;">';
          html += '<div><strong>'+label+'</strong> '+estadoBadge;
          if (doc.archivo_nombre) html += '<br><small class="ad-dim">'+esc(doc.archivo_nombre)+'</small>';
          html += '</div>';
          html += '<div style="display:flex;gap:6px;">';
          if (doc.archivo_url) html += '<a href="'+esc(doc.archivo_url)+'" target="_blank" class="ad-btn sm ghost">Descargar</a>';
          if (ADApp.canWrite()) html += '<button class="ad-btn sm ghost dcEditDoc" data-cid="'+clienteId+'" data-tipo="'+tipo+'" data-doc=\''+JSON.stringify(doc).replace(/'/g,"&#39;")+'\'>Editar</button>';
          html += '</div></div>';
        } else {
          html += '<div class="ad-card" style="padding:12px;margin:0;display:flex;align-items:center;justify-content:space-between;opacity:.6;">';
          html += '<div><strong>'+label+'</strong> <span class="ad-badge red">Faltante</span></div>';
          if (ADApp.canWrite()) html += '<button class="ad-btn sm primary dcEditDoc" data-cid="'+clienteId+'" data-tipo="'+tipo+'" data-doc="{}">Subir</button>';
          html += '</div>';
        }
      });
      html += '</div>';

      ADApp.modal(html);
      $('.dcEditDoc').on('click', function(){
        var doc = {};
        try { doc = JSON.parse($(this).attr('data-doc')); } catch(e){}
        showDocForm($(this).data('cid'), $(this).data('tipo'), doc);
      });
    });
  }

  function showDocForm(clienteId, tipo, doc){
    var label = _tiposLabel[tipo] || tipo;
    var html = '<div class="ad-h2">'+label+'</div>';
    html += '<div style="display:grid;gap:10px;font-size:13px;">';
    html += '<label>URL del archivo<input id="dcUrl" class="ad-input" value="'+esc(doc.archivo_url||'')+'" placeholder="https://..."></label>';
    html += '<label>Nombre del archivo<input id="dcNombre" class="ad-input" value="'+esc(doc.archivo_nombre||'')+'"></label>';
    html += '<label>Estado<select id="dcEstado" class="ad-input">';
    ['pendiente','subido','verificado'].forEach(function(e){
      html += '<option value="'+e+'"'+(e===(doc.estado||'subido')?' selected':'')+'>'+e.charAt(0).toUpperCase()+e.slice(1)+'</option>';
    });
    html += '</select></label>';
    html += '<label>Notas<input id="dcNotas" class="ad-input" value="'+esc(doc.notas||'')+'"></label>';
    html += '</div>';
    html += '<div style="margin-top:14px;text-align:right;">';
    html += '<button class="ad-btn ghost" onclick="AD_documentos.showClienteDocs('+clienteId+')">Volver</button> ';
    html += '<button class="ad-btn primary" id="dcGuardar">Guardar</button>';
    html += '</div><div id="dcMsg" style="margin-top:8px;font-size:12px;"></div>';

    ADApp.modal(html);
    $('#dcGuardar').on('click', function(){
      var payload = {
        id: doc.id || 0,
        cliente_id: clienteId,
        tipo: tipo,
        archivo_url: $('#dcUrl').val().trim(),
        archivo_nombre: $('#dcNombre').val().trim(),
        estado: $('#dcEstado').val(),
        notas: $('#dcNotas').val().trim(),
      };
      $('#dcGuardar').prop('disabled',true).text('Guardando...');
      ADApp.api('documentos/guardar.php', payload).done(function(r){
        if (r.ok) { showClienteDocs(clienteId); }
        else { $('#dcMsg').html('<span style="color:#b91c1c;">'+esc(r.error)+'</span>'); $('#dcGuardar').prop('disabled',false).text('Guardar'); }
      });
    });
  }

  function esc(s){ return $('<span>').text(s||'').html(); }
  return { render: render, showClienteDocs: showClienteDocs };
})();
