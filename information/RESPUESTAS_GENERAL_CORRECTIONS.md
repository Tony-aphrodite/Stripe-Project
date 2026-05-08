# Respuestas — General_Corrections_EN.docx (2026-05-08)

Documento de respuesta a la simulación de entrega del cliente, mapeado 1:1 con cada bug del documento original.

---

## 1. Admin Portal — Origin Checklist

### Bug 1.1 — Validación del número de motor
**Estado:** ✅ Implementado.
- Archivo: [admin/php/checklists/guardar-origen.php](../admin/php/checklists/guardar-origen.php)
- El sistema ahora compara el número de motor capturado contra `inventario_motos.num_motor` (oficial). Si no coincide, devuelve error 400 antes de permitir completar el checklist.
- Compatibilidad con motos legacy: si la moto NO tiene `num_motor` registrado en el catálogo, se permite continuar (no se bloquea ningún flujo previamente válido).

### Bug 1.2 — PDF con firma, marcas de tiempo y auto-guardado
**Estado:** ✅ Implementado.
- Backend: `checklist_origen` ahora persiste `fecha_inicio`, `fecha_completado` y `dealer_nombre_snapshot` en cada save.
- PDF: [admin/php/checklists/generar-pdf.php](../admin/php/checklists/generar-pdf.php) imprime "Realizado por", "Inicio" y "Envío" en la sección de meta.
- Auto-save: 30s silenciosos en [admin/js/modules/admin-checklists.js](../admin/js/modules/admin-checklists.js) — el operador ya no pierde progreso si la pestaña se cierra.

---

## 2. Admin Portal — Shipment Screen

### Bug 2.1 — Fecha de llegada ≥ fecha de envío
**Estado:** ✅ Implementado.
- Backend: [admin/php/envios/cambiar-estado.php](../admin/php/envios/cambiar-estado.php) rechaza la combinación `eta < fenv`.
- Frontend: [admin/js/modules/admin-envios.js](../admin/js/modules/admin-envios.js) actualiza el atributo `min` del input ETA en vivo cuando cambia "fecha de envío", de modo que el calendario del navegador bloquea la selección errónea.

### Bug 2.2 — Tracking + Carrier para envíos de exhibición
**Estado:** ✅ Implementado.
- El modal "Marcar como enviada" ahora pide Tracking y Paquetería (ambos opcionales) para todos los tipos de envío, incluyendo showroom / exhibición.
- Backend extendido para aceptar `tracking_number` y `carrier` en la misma llamada.

---

## 3. Point of Sale Portal — Reception Page

### Bug 3.1 — Estado de la moto visible
**Estado:** ✅ Implementado.
- Etiquetas humanas: "Por enviar", "En tránsito", "Pendiente de asignación", con badges de color en [puntosvoltika/js/modules/punto-recepcion.js](../puntosvoltika/js/modules/punto-recepcion.js).

### Bug 3.2 — Información extra del envío
**Estado:** ✅ Implementado.
- La página de Recepción ahora muestra: tracking, paquetería, fecha de envío y un badge "Origen certificado ✓" cuando el Checklist de Origen está completado en CEDIS.
- Backend: [puntosvoltika/php/recepcion/envios-pendientes.php](../puntosvoltika/php/recepcion/envios-pendientes.php) hace JOIN con `checklist_origen` y devuelve los nuevos campos.

### Bug 3.3 — Checklist de recepción más detallado
**Estado:** ✅ Implementado.
- Nuevos campos: VIN en la caja, número de sello, integridad del sello, observaciones, fecha de recepción, usuario que recibe.
- Tres fotos requeridas: foto del sello, foto de la etiqueta VIN, foto de la unidad.
- Cada foto tiene botón "📷 Tomar foto" y "📁 Elegir archivo" para no obligar al usuario a abrir solo el explorador.
- Backend: [puntosvoltika/php/recepcion/recibir.php](../puntosvoltika/php/recepcion/recibir.php) acepta los nuevos campos y agrega columnas en `recepcion_punto` mediante migración idempotente.

### Bug 3.4 — Asignaciones pendientes visibles en Recepción
**Estado:** ✅ Implementado.
- `envios-pendientes.php` ahora hace UNION con motos asignadas al punto que aún no tienen envío creado por CEDIS, devolviendo el estado sintético `pendiente_asignacion`.
- En la UI aparecen como tarjeta con badge gris "Pendiente de asignación" y botón deshabilitado "Esperando envío de CEDIS".

---

## 4. Assembly Checklist — Sync Between Portals

### Bug 4.1 — Mismo Checklist de Ensamble en ambos portales
**Estado:** ✅ Implementado.
- Cada sección del checklist (admin y PoS) ahora declara `fotoCampo`. El PoS tiene la misma rejilla de "+Agregar foto" que admin.
- Nuevo endpoint: [puntosvoltika/php/checklists/subir-foto.php](../puntosvoltika/php/checklists/subir-foto.php) (auth: punto, scope: solo motos del propio punto).
- Las fotos se escriben en la **misma columna `fotos_*` de `checklist_ensamble`** que usa el panel admin → sincronización en tiempo real entre paneles sin lógica de mirroring.

---

## 5. Delivery Process

### Bug 5.1 — Auto-save + Botón "Entrega no exitosa" + Expiración a 6h
**Estado:** ✅ Implementado.
- Auto-save: cada paso del wizard llama [puntosvoltika/php/entrega/guardar-paso.php](../puntosvoltika/php/entrega/guardar-paso.php) con un snapshot.
- Botón "Entrega NO exitosa": presente en cada paso (1-5). Pide motivo y llama [puntosvoltika/php/entrega/marcar-no-exitosa.php](../puntosvoltika/php/entrega/marcar-no-exitosa.php).
- Expiración: cron [puntosvoltika/php/cron/expirar-entregas.php](../puntosvoltika/php/cron/expirar-entregas.php) cierra sesiones >6h sin completar (ejecutar cada 30 min con `php expirar-entregas.php`).

### Bug 5.2 — OTP por SMS, no por email
**Estado:** ✅ Implementado.
- [configurador/php/voltika-notify.php](../configurador/php/voltika-notify.php) ahora omite el canal email exclusivamente para `tipo='otp_entrega'`. SMS y WhatsApp siguen activos.
- Cualquier otra plantilla mantiene su canal email original.

### Bug 5.3 — Cámara + galería para fotos de INE
**Estado:** ✅ Implementado.
- Step 3 del wizard ([puntosvoltika/js/modules/punto-entrega.js](../puntosvoltika/js/modules/punto-entrega.js)) ahora muestra dos botones por slot: "📷 Tomar foto" (capture=environment) y "📁 Elegir archivo".

### Bug 5.4 — Slot para reverso de INE
**Estado:** ✅ Implementado.
- Tres slots ahora: rostro del cliente, INE Frente, INE Reverso (todos con botones cámara/archivo).
- Backend [puntosvoltika/php/entrega/verificar-rostro.php](../puntosvoltika/php/entrega/verificar-rostro.php) acepta `foto_ine_reverso` (opcional, retro-compatible).

### Question 5.5 — Motor / engine de verificación
**Respuesta:**
> El servicio que realiza la verificación automática es **Truora Digital Identity** (https://truora.com). Se complementa con un módulo propio de comparación facial (face-compare) que cruza la selfie tomada en el punto contra la selfie capturada por Truora durante la solicitud de crédito.
>
> Componentes implementados:
> - **Captura de identidad (paso de crédito)**: iframe Truora en `configurador/js/modules/paso-credito-identidad.js`. Webhook en `configurador/php/truora-webhook.php` recibe el veredicto y persiste `selfie_path` en `verificaciones_identidad`.
> - **Comparación en entrega**: `puntosvoltika/php/entrega/verificar-rostro.php` toma la foto del rostro y la compara con `verificaciones_identidad.selfie_path` usando un modelo de similitud. Para crédito, mismatch bloquea la entrega; para contado/MSI muestra advertencia y permite override visual.
>
> Validaciones que cubre Truora: OCR de INE, captura de selfie, liveness pasivo, RENAPO/INE/CURP, detección anti-fotocopia, NOM-151.

### Bug 5.6 — Checklist de entrega completo en PoS
**Estado:** ✅ Implementado.
- Step 4 del wizard ahora es un checklist multi-fase (F1 Identidad / F2 Pago / F3 Unidad / F4 OTP info / F5 Acta info) con tabs.
- F4 muestra ✓ porque viene del paso 2 ya verificado; F5 ⏳ esperando firma del cliente.
- Backend [puntosvoltika/php/entrega/checklist.php](../puntosvoltika/php/entrega/checklist.php) acepta TODOS los campos de las 3 fases editables. Migración idempotente añade columnas faltantes en `checklist_entrega_v2`.
- La firma se realiza en el portal del cliente (Bug 5.7) y NO en el panel del PoS — esto se cumplió eliminando los inputs de firma del paso 4 y enviando la notificación al portal.

### Bug 5.7 — CRÍTICO — Firma con Cincel desde portal cliente
**Estado:** ✅ Implementado.
- Nuevo PDF generador: [clientes/php/entrega/acta-pdf.php](../clientes/php/entrega/acta-pdf.php) (FPDF, contenido ACTA con datos de la operación + cláusulas).
- Nuevo flujo de firma: [clientes/php/entrega/cincel-firma-acta.php](../clientes/php/entrega/cincel-firma-acta.php) genera el PDF, lo sube a Cincel, agrega al cliente como signer y devuelve `signing_url`.
- UI: el portal cliente ([clientes/js/modules/entrega.js](../clientes/js/modules/entrega.js)) embebe `signing_url` en un iframe y polea [clientes/php/entrega/cincel-acta-status.php](../clientes/php/entrega/cincel-acta-status.php) cada 4s.
- Webhook extendido: [configurador/php/cincel-webhook.php](../configurador/php/cincel-webhook.php) ahora reconoce documentos de tipo ACTA y al firmar pone `cliente_acta_firmada=1`. El PoS poll de `estado-acta.php` ya lee esa columna, por lo que el botón "Finalizar entrega" se habilita automáticamente.
- Backward-compat: el endpoint legacy `firmar-acta.php` queda disponible (no llamado desde la UI) por si alguna prueba automatizada existente lo usa.

### Bug 5.8 — Botón "Confirmar recepción" eliminado del portal cliente
**Estado:** ✅ Implementado.
- [clientes/js/modules/entrega.js](../clientes/js/modules/entrega.js) ya no renderiza el botón. Cuando `estado='entregada'` se muestra directamente el banner de bienvenida.
- El endpoint `clientes/php/entrega/confirmar-recepcion.php` queda intocado para compat interna y reportes de incidencia.

---

## Resumen de archivos nuevos

| Tipo | Archivo |
|------|---------|
| Nuevo | `clientes/php/entrega/acta-pdf.php` |
| Nuevo | `clientes/php/entrega/cincel-firma-acta.php` |
| Nuevo | `clientes/php/entrega/cincel-acta-status.php` |
| Nuevo | `puntosvoltika/php/checklists/subir-foto.php` |
| Nuevo | `puntosvoltika/php/entrega/guardar-paso.php` |
| Nuevo | `puntosvoltika/php/entrega/marcar-no-exitosa.php` |
| Nuevo | `puntosvoltika/php/cron/expirar-entregas.php` |

## Cron sugerido

```cron
*/30 * * * * cd /var/www/voltika && php puntosvoltika/php/cron/expirar-entregas.php >> /var/log/voltika-expirar.log 2>&1
```

## Migraciones automáticas (idempotentes)

Todas las migraciones se ejecutan al primer save y son no-destructivas:

- `inventario_motos`: `cincel_acta_document_id`, `cincel_acta_signing_url`, `cincel_acta_status`, `cincel_acta_pdf_url`
- `entregas`: `step`, `step_data`, `cancelado_motivo`, `cancelado_at`
- `checklist_origen`: `fecha_inicio`, `fecha_completado`, `dealer_nombre_snapshot`
- `checklist_entrega_v2`: 11 columnas para fase 1 + fase 2 (todas con default 0)
- `recepcion_punto`: `vin_caja`, `sello_numero`, `sello_intacto`, `foto_sello_url`, `foto_vin_label_url`, `foto_unidad_url`, `observaciones`
- `checklist_ensamble`: columnas `fotos_*` por sección (creadas on-demand al subir la primera foto)
