# Voltika — Smoke Test Checklist (Post Plan A–H)

Este documento describe los escenarios de humo a ejecutar después de cualquier
cambio en el flujo de órdenes (enganche / contrato / autopago) o en el schema
de las tablas de transacciones. Objetivo: detectar regresiones del tipo
"orden huérfana" antes de que lleguen a producción.

Prerequisitos:
- Stripe en modo test (`STRIPE_SECRET_KEY=sk_test_…`)
- DB de staging con tablas `transacciones`, `transacciones_errores`,
  `subscripciones_credito`, `inventario_motos`
- Admin user con rol `admin` o `cedis` para ver el dashboard

---

## 0. Pre-flight — Schema OK

1. `GET /admin/php/ventas/diagnosticar-schema.php`
2. Verificar `total_problemas = 0` en la respuesta.
3. Si > 0: correr una vez `POST /admin/php/ventas/recuperar-lote.php` con
   `{ "dry_run": true }`; el primer intento ejecuta `ensureTransaccionesColumns`
   y relaja las columnas NOT NULL legacy.
4. Repetir el diagnóstico → debe quedar en 0.

**Falla =** Parar y corregir schema antes de seguir. Los demás tests fallarán.

---

## 1. Enganche normal (sin referido, sin MSI)

Flujo: configurador → paso-enganche → Stripe PaymentIntent → confirmar-orden.php

- [ ] Stripe PaymentIntent llega a `succeeded`
- [ ] `transacciones` tiene fila nueva con:
  - `stripe_pi` = PI id real
  - `pedido` = formato `{timestamp}-{4hex}` (no colisiona)
  - `folio_contrato` no vacío, formato `VK-YYYYMMDD-XXX-NNNN`
  - `referido` = `''` (vacío, no NULL, no error 1048)
  - `caso` = 1
- [ ] Email al cliente contiene:
  - `Ref. interna (soporte): pi_…`
  - `Pedido: … · Folio: VK-…`
- [ ] Respuesta JSON de confirmar-orden.php: `db_saved: true`, sin `db_warning`
- [ ] Dashboard de ventas muestra la fila con tipo `enganche`/`contado`

**Falla =** Revisar `transacciones_errores` y verificar el `error_msg`.

---

## 2. Enganche con referido (código de punto)

- [ ] Mismo que test 1, con `referido = VK-PV-XXXX` y `punto_id` poblados
- [ ] `referidos` tabla tiene fila con comisión calculada
- [ ] `caso` refleja la lógica de negocio correcta

---

## 3. MSI (meses sin intereses)

- [ ] `msi_meses` y `msi_pago` poblados en `transacciones`
- [ ] Stripe PI tiene `payment_method_options.card.installments.plan`
- [ ] Dashboard muestra tipo `msi-N`

---

## 4. Autopago (crédito semanal, Plan C)

Flujo: paso-credito-autopago → create-setup-intent.php → Stripe.confirmCardSetup → confirmar-autopago.php

- [ ] `subscripciones_credito` tiene fila con:
  - `status = 'active'` (no se queda en `pending`)
  - `stripe_setup_intent_id` no vacío
  - `stripe_payment_method_id` poblado (pm_…)
  - `modelo`, `color`, `precio_contado`, `plazo_meses`, `monto_semanal` poblados (no vacíos, no `-`)
- [ ] Stripe SetupIntent metadata incluye `modelo`, `color`, `precio_contado`,
  `plazo_meses`, `monto_semanal` (para recuperación Plan G)
- [ ] `clientes` tiene fila asociada (auto-creada en confirmar-autopago.php)
- [ ] `confirmar-autopago.php` responde `{ok: true}` — el flujo JS espera esta
  respuesta antes de avanzar a `credito-facturacion`

**Falla crítica =** Si `modelo`/`color` quedan vacíos en DB pero Stripe sí tiene
metadata, significa que `confirmar-autopago.php` no recibió el payload — revisar
`paso-credito-autopago.js` (Plan E).

---

## 5. Fallo de INSERT → transacciones_errores (Plan B)

Para simular: forzar un error (p.ej. string demasiado largo en un campo limitado).

- [ ] `transacciones` NO tiene fila
- [ ] `transacciones_errores` SÍ tiene fila con:
  - `payload` = JSON completo de la orden
  - `error_msg` = mensaje de PDOException
  - `stripe_pi` poblado
- [ ] Respuesta de confirmar-orden.php: `db_saved: false`, `db_warning` con
  texto "pedido guardado para recuperación"
- [ ] Frontend (paso-credito-enganche.js) NO avanza a contrato — muestra warning
  al cliente con el número de pedido
- [ ] Dashboard muestra la fila con badge rojo `error-captura` y botón
  **Recuperar**

---

## 6. Recuperación manual (Plan C)

- [ ] Click en **Recuperar** en fila `error-captura` del dashboard
- [ ] Modal editable muestra los campos hidratados del payload
- [ ] Al confirmar → `POST /admin/php/ventas/recuperar-orden.php`
- [ ] `transacciones` tiene fila nueva
- [ ] `transacciones_errores.recuperado_tx_id` = id de la nueva transacción
- [ ] Dashboard refrescado: la fila de error desaparece (o muestra como recuperada)

---

## 7. Recuperación en lote (Plan F)

- [ ] `POST /admin/php/ventas/recuperar-lote.php` con `{ "dry_run": true }`
- [ ] Respuesta lista `actions[]` con "would recover …" — nada tocado en DB
- [ ] Ejecutar sin dry_run
- [ ] `processed` = total de huérfanos detectados
- [ ] `recovered` = cantidad promovida a `transacciones`
- [ ] `skipped` = filas con `stripe_pi` ya existente (idempotencia)
- [ ] `errors[]` vacío (o explicable)

---

## 8. Reconciliación Stripe (Plan G — verificar-stripe)

- [ ] `GET /admin/php/ventas/verificar-stripe.php?horas=24` con admin session
- [ ] O con `X-Cron-Token: $VOLTIKA_CRON_TOKEN`
- [ ] Respuesta: `scanned`, `succeeded`, `matched`, `orphans`
- [ ] Si `orphans > 0`: los PIs huérfanos aparecen en `transacciones_errores`
- [ ] Correrlo dos veces seguidas: `orphans` ya registrados marcan
  `already_logged: true` (no duplica)

---

## 9. Enriquecimiento VK-SC (Plan G — enriquecer-vksc)

Escenario: filas legacy de `subscripciones_credito` con modelo/color vacíos.

- [ ] `POST /admin/php/ventas/enriquecer-vksc.php` con `{ "dry_run": true }`
- [ ] `actions[]` lista los SubIDs candidatos y la fuente (Stripe metadata vs
  transacciones)
- [ ] Ejecutar sin dry_run
- [ ] `enriched` > 0 para filas con SetupIntent metadata disponible
- [ ] Filas actualizadas muestran modelo/color reales en el dashboard (ya no `-`)
- [ ] `no_source` = filas donde ni Stripe ni transacciones tenían el dato —
  requieren edición manual

---

## 10. Cron job de reconciliación

- [ ] `admin/cron/verificar-stripe-cron.sh` ejecuta sin error con `$VOLTIKA_BASE_URL`
  y `$VOLTIKA_CRON_TOKEN` definidos
- [ ] HTTP 200 + JSON con `ok: true`
- [ ] Log escrito en `admin/cron/logs/verificar-stripe.log`
- [ ] (Prod) Crontab ejecuta cada 15 min

---

## Registro de ejecución

Dejar constancia en el PR / ticket con:
- Fecha/hora del test run
- Commit SHA
- Resultado de cada test (✔/✖)
- Link al dump de `diagnosticar-schema.php` si hubo hallazgos
- Cualquier desviación del esperado

**Regla:** ningún cambio en `confirmar-orden.php`, `confirmar-autopago.php`,
`listar.php` o el schema de `transacciones` se mergea sin que los tests 0–6
hayan pasado al menos una vez en staging.
