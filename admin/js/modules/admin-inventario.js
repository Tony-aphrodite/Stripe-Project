window.AD_inventario = (function(){
  var filters = {};
  function render(){
    ADApp.render('<div class="ad-h1">Inventario</div><div><span class="ad-spin"></span> Cargando...</div>');
    load();
  }
  function load(){
    ADApp.api('inventario/listar.php?' + $.param(filters)).done(paint);
  }
  function paint(r){
    var html = '<div class="ad-toolbar"><div class="ad-h1">Inventario</div>'+
      '<button class="ad-btn primary" id="adNewMoto">+ Nueva moto</button></div>';
    // Summary
    var s = r.resumen||{};
    html += '<div class="ad-kpis">';
    [{l:'Total',v:s.total},{l:'Disponible',v:s.disponible,c:'green'},{l:'Reservado',v:s.reservado,c:'yellow'},
     {l:'Entregado',v:s.entregado,c:'green'},{l:'En tránsito',v:s.en_transito,c:'blue'},
     {l:'En ensamble',v:s.en_ensamble,c:'yellow'},{l:'Bloqueado',v:s.bloqueado,c:'red'}].forEach(function(k){
      html += '<div class="ad-kpi"><div class="label">'+k.l+'</div><div class="value '+(k.c||'')+'">'+Number(k.v||0)+'</div></div>';
    });
    html += '</div>';
    // Filters
    html += '<div class="ad-filters">'+
      '<input class="ad-input" style="width:160px" placeholder="Buscar VIN..." id="adFVin">'+
      '<select class="ad-select" id="adFEstado"><option value="">Estado</option>'+
        ['por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida']
        .map(function(e){return '<option>'+e+'</option>';}).join('')+'</select>'+
      '<button class="ad-btn sm ghost" id="adFApply">Filtrar</button>'+
    '</div>';
    // Table
    html += '<table class="ad-table"><thead><tr><th>VIN</th><th>Modelo</th><th>Color</th><th>Estado</th><th>Punto</th><th>Cliente</th><th>Pago</th><th></th></tr></thead><tbody>';
    (r.motos||[]).forEach(function(m){
      html += '<tr>'+
        '<td>'+( m.vin_display||m.vin||'—')+'</td>'+
        '<td>'+m.modelo+'</td><td>'+m.color+'</td>'+
        '<td>'+ADApp.badgeEstado(m.estado)+'</td>'+
        '<td>'+(m.punto_voltika_nombre||'—')+'</td>'+
        '<td>'+(m.cliente_nombre||'—')+'</td>'+
        '<td>'+ADApp.badgeEstado(m.pago_estado||'—')+'</td>'+
        '<td><button class="ad-btn sm ghost adDetail" data-id="'+m.id+'">Ver</button></td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    // Pagination
    if(r.pages>1){
      html += '<div style="margin-top:10px;display:flex;gap:4px">';
      for(var p=1;p<=r.pages;p++) html += '<button class="ad-btn sm '+(p===r.page?'primary':'ghost')+' adPage" data-p="'+p+'">'+p+'</button>';
      html += '</div>';
    }
    ADApp.render(html);
    $('#adFApply').on('click',function(){ filters.vin=$('#adFVin').val(); filters.estado=$('#adFEstado').val(); load(); });
    $('.adDetail').on('click',function(){ showDetail($(this).data('id')); });
    $('.adPage').on('click',function(){ filters.page=$(this).data('p'); load(); });
    $('#adNewMoto').on('click', showNewForm);
  }
  function showDetail(id){
    ADApp.api('inventario/detalle.php?id='+id).done(function(r){
      var m=r.moto; if(!m) return;
      var html = '<div class="ad-h2">'+m.modelo+' — '+m.color+'</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">';
      [['VIN',m.vin_display||m.vin],['Estado',m.estado],['Pago',m.pago_estado],['Año',m.anio_modelo],
       ['Cliente',m.cliente_nombre||'—'],['Email',m.cliente_email||'—'],['Teléfono',m.cliente_telefono||'—'],
       ['Pedido',m.pedido_num||'—'],['Punto',m.punto_voltika_nombre||'—']].forEach(function(p){
        html += '<div><span style="color:var(--ad-dim)">'+p[0]+':</span> <strong>'+p[1]+'</strong></div>';
      });
      html += '</div>';
      // Checklists status
      html += '<div class="ad-h2">Checklists</div>';
      html += '<div style="display:flex;gap:8px">';
      html += '<span class="ad-badge '+(r.checklist_origen&&r.checklist_origen.completado?'green':'yellow')+'">'+(r.checklist_origen?'Origen ✓':'Origen ✗')+'</span>';
      html += '<span class="ad-badge '+(r.checklist_ensamble&&r.checklist_ensamble.completado?'green':'yellow')+'">'+(r.checklist_ensamble?'Ensamble ✓':'Ensamble ✗')+'</span>';
      html += '<span class="ad-badge '+(r.checklist_entrega&&r.checklist_entrega.completado?'green':'yellow')+'">'+(r.checklist_entrega?'Entrega ✓':'Entrega ✗')+'</span>';
      html += '</div>';
      // Envios
      if(r.envios&&r.envios.length){
        html += '<div class="ad-h2">Envíos</div>';
        r.envios.forEach(function(e){
          html += '<div class="ad-card" style="padding:10px;font-size:12px">→ '+e.punto_nombre+' · '+ADApp.badgeEstado(e.estado)+' · '+(e.fecha_envio||'sin fecha')+'</div>';
        });
      }
      // Assign to point action
      html += '<div class="ad-h2">Acciones</div>';
      html += '<button class="ad-btn primary" id="adAssign" data-id="'+m.id+'">📍 Asignar a punto</button> ';
      html += '<button class="ad-btn ghost" id="adVerifyPay" data-id="'+m.id+'">💳 Verificar pago</button>';
      ADApp.modal(html);
      $('#adAssign').on('click',function(){ assignToPunto(m.id); });
      $('#adVerifyPay').on('click',function(){
        ADApp.api('pagos/verificar.php',{moto_id:m.id}).done(function(r2){
          alert(r2.verificado?'✅ Pago verificado':'⚠️ No verificado: '+(r2.stripe_status||'sin Stripe PI'));
        });
      });
    });
  }
  function assignToPunto(motoId){
    ADApp.api('puntos/listar.php').done(function(r){
      var html='<div class="ad-h2">Seleccionar punto</div>';
      (r.puntos||[]).forEach(function(p){
        html += '<div class="ad-card" style="cursor:pointer;padding:10px" data-pid="'+p.id+'">'+
          '<strong>'+p.nombre+'</strong> · '+p.ciudad+' · Inv: '+p.inventario_actual+
        '</div>';
      });
      html += '<div class="ad-h2">Fecha estimada llegada</div><input type="date" class="ad-input" id="adFechaEst">';
      ADApp.modal(html);
      $('[data-pid]').on('click',function(){
        var pid=$(this).data('pid'), fecha=$('#adFechaEst').val();
        ADApp.api('inventario/asignar-punto.php',{moto_id:motoId,punto_id:pid,fecha_estimada:fecha||null}).done(function(r2){
          if(r2.ok){ ADApp.closeModal(); alert('✅ Moto asignada'); load(); }
          else alert(r2.error);
        }).fail(function(x){ alert((x.responseJSON&&x.responseJSON.error)||'Error'); });
      });
    });
  }
  function showNewForm(){
    ADApp.modal(
      '<div class="ad-h2">Nueva moto</div>'+
      '<input class="ad-input" id="nmVin" placeholder="VIN" style="margin-bottom:8px">'+
      '<input class="ad-input" id="nmModelo" placeholder="Modelo" style="margin-bottom:8px">'+
      '<input class="ad-input" id="nmColor" placeholder="Color" style="margin-bottom:8px">'+
      '<input class="ad-input" id="nmAnio" placeholder="Año modelo" style="margin-bottom:8px">'+
      '<input class="ad-input" id="nmHecho" placeholder="Hecho en" style="margin-bottom:8px">'+
      '<textarea class="ad-input" id="nmNotas" placeholder="Notas" style="margin-bottom:8px"></textarea>'+
      '<button class="ad-btn primary" id="nmSave">Guardar</button>'
    );
    $('#nmSave').on('click',function(){
      ADApp.api('inventario/crear.php',{
        vin:$('#nmVin').val(),modelo:$('#nmModelo').val(),color:$('#nmColor').val(),
        anio_modelo:$('#nmAnio').val(),hecho_en:$('#nmHecho').val(),notas:$('#nmNotas').val()
      }).done(function(r){
        if(r.ok){ ADApp.closeModal(); load(); } else alert(r.error||'Error');
      });
    });
  }
  return { render:render };
})();
