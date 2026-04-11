# Voltika Dashboard Diagram Analysis

Source file: [information/dashboards_diagrams.pdf](information/dashboards_diagrams.pdf)

This document analyzes the business-process flow diagrams provided by the customer for the Voltika (electric motorcycle) sales and delivery system. The system is composed of 5 panels (Purchase, Point, Inventory, Shipping, Client) together with the state transitions and notification rules that connect them.

---

## 0. PDF Diagram Inventory (5 total)

The PDF contains the following 5 flow diagrams:

| # | Diagram | Role |
|---|---------|------|
| 1 | **CASE 1** — Purchase without CODIGO REFERIDO | Standard purchase flow with no referral (contains an internal YES/NO branch for point selection) |
| 2 | **CASE 3** — Purchase with CODIGO REFERIDO (General sale) | Referral-coded general sale → `PUNTO VENTA GENERAL` |
| 3 | **CASE 4** — Purchase with CODIGO REFERIDO (Showroom sale) | Direct sale out of the point's showroom inventory |
| 4 | **Delivery process** | Pickup-time delivery flow (OTP → face check → checklist → ACTA → deliver) |
| 5 | **Shipping motorcycle without a purchase order** | Moving a moto to a point with no linked order (showroom vs. for-delivery split) |

> ⚠️ **There is no CASE 2.** The `Assign point selected YES/NO` branch inside the CASE 1 diagram decides whether the user picked a point in the configurador; both sub-flows are part of CASE 1.

---

## 1. Purchase Cases

The diagrams define **three purchase scenarios (CASE 1 / 3 / 4)**.

### CASE 1 — Purchase without CODIGO REFERIDO
Splits into two sub-flows:

- **CASE 1-A — No point selected (`Assign point selected = NO`)**
  - Purchase made in the configurador without choosing a point
  - Purchase Panel state: **"Purchases waiting point assignation"**
  - CEDIS assigns a point manually later on

- **CASE 1-B — Point selected (`Assign point selected = YES`)**
  - The user picks a point inside the configurador
  - Purchase Panel state: **"Purchases with point assigned"**
  - Once payment succeeds, a motorcycle is assigned from inventory immediately

Both sub-flows continue the same way: payment success → assign moto from inventory → create shipping → point receives → assembly → `LISTA PARA ENTREGA`.

### CASE 3 — CODIGO REFERIDO, general sale
- Purchase made with a Voltika point code or an influencer code
- A new order is created as `PUNTO VENTA GENERAL`
- The order is auto-assigned to the owner of the referral code → appears in the Point Panel as "completed sale via referral, pending motorcycle assignment"
- Continues with moto assignment → shipping → reception → assembly → delivery

### CASE 4 — CODIGO REFERIDO, showroom sale
- Direct sale out of the point's showroom inventory
- Appears in the Point Panel as a "direct purchase"
- Assigned from a motorcycle in `INVENTORY FOR SHOWROOM SALE`
- The model/color must match the order, and only motos in `free` state can be assigned
- Because the bike is physically in the showroom there is **no shipping step** — it goes straight to delivery prep

---

## 2. Panel Structure

### Purchase Panel
- Receives new orders (with or without CODIGO REFERIDO)
- Order states: awaiting point assignment / awaiting motorcycle assignment
- Allocates a moto from inventory on successful payment

### Point Panel (Punto Voltika)
- **Inventario por Entrega**: motorcycles pending delivery (PENDIENTE DE ENVIO)
- **Inventario Showroom**: stock for display / showroom sale
- Receives a new motorcycle → fills reception checklist → scans QR → assembly
- After assembly, transitions status to `LISTA PARA ENTREGA`
- Sets a pickup date and notifies the client

### Inventory Panel — motorcycle state machine
```
PENDIENTE DE ENSAMBLE
   ↓
PENDIENTE DE ENVIO / INVENTARIO POR ENTREGA
   ↓
LISTA PARA ENTREGA
   ↓
EN INVENTARIO (showroom) / ENTREGADA (delivered)
```

### Shipping Panel
Two options when creating a shipment:
- **Shipping for an order** — linked to an existing order (CASE 1 or 3). At shipment-creation time the client is notified of the point address, contact info and the Skydrop ETA.
- **Shipping without order (diagram 5)** — move a moto to a point with no linked order. Point staff must pick a **"type of assignment"**:
  - **For showroom sale** → appears in the Point Panel as `"pending arrival"` in showroom inventory → after reception it enters `INVENTARIO` and can be sold with the point's referral code
  - **Only for delivery** → appears in the Point Panel as `"pending arrival"` in for-delivery inventory → after reception it enters stock but waits until CEDIS assigns it to a specific order

Common behavior:
- Skydrop API is called for the ETA (`estimate arrive date`)
- Shipping data auto-updates in the Point Panel

### Client Panel
- Point-assigned notification (address + contact)
- Shipment-started notification (includes arrival ETA)
- Ready-for-pickup notification (includes pickup date)

---

## 3. Shipping & Reception Process

1. **SHIPPING** created — either linked to an existing order or with no order
2. **POINT VOLTIKA PANEL** — shipment info received, motorcycle expected
3. **RECEPTION PROCESS** — point scans the QR, pulls moto info, fills the reception checklist (package photos required)
4. **Assembly** — assembly completed
5. **Change status to LISTA PARA ENTREGA** — point sets the pickup date and notifies the client

---

## 4. Delivery Process

1. **Point Panel**: review orders pending delivery
2. **Start Delivery** — verifies payment is complete (delivery blocked otherwise)
3. **SMS OTP** — sent to the phone registered during the purchase and validated on the spot
4. **User Verification**:
   - **CREDITO purchase**: the system compares the credit-application selfie against the pickup photo → same-person check
   - **MSI / CONTADO**: capture a pickup photo and an ID photo
5. **Delivery Checklist**:
   - Motorcycle photos (front, side, etc.)
   - Pickup-person photo
   - ID photo
6. **ACTA DE ENTREGA** signed — system notifies the Point Panel that the acta is signed
7. **Deliver motorcycle** — delivery complete

---

## 5. Notification Rules

| Trigger | Recipient | Content |
|---------|-----------|---------|
| Point assigned | Client | Point address and contact info |
| Shipment created | Client | Destination point + Skydrop arrival ETA |
| Shipment created | Point Panel | Auto-updated shipment info |
| Status → LISTA PARA ENTREGA | Client | Pickup date |
| OTP issued | Client | 6-digit SMS code |
| ACTA signed | Point Panel | Signature confirmation |

---

## 6. Current Implementation vs. Diagram

The table below summarizes whether each feature is implemented, partially implemented, or missing from the current codebase.

| Feature | Status | Location / Notes |
|---------|--------|------------------|
| **Purchase Panel** (order management) | Implemented | [admin/js/modules/admin-ventas.js](admin/js/modules/admin-ventas.js), [admin/php/ventas/](admin/php/ventas/) — order list, point-assignment queue, moto assignment |
| **Motorcycle state machine** | Implemented | [admin/js/modules/admin-inventario.js](admin/js/modules/admin-inventario.js) — 8 tracked states (`por_llegar`, `en_ensamble`, `lista_para_entrega`, `entregada`, …) |
| **Point Panel inventory** | Implemented | [puntosvoltika/](puntosvoltika/) — `punto-inventario.js` splits "Para entrega" vs. "Disponible para venta" |
| **Point Panel delivery process** | Implemented | `punto-entrega.js` — 5 steps: OTP → face check → checklist → photos → ACTA |
| **Point Panel reception** | Implemented | `punto-recepcion.js` — shipment reception handling |
| **Point Panel referral sale** | Implemented | `punto-venta.js` — direct sale out of showroom stock |
| **Shipping Panel** | Implemented | [admin/js/modules/admin-envios.js](admin/js/modules/admin-envios.js) — creates shipments for orders or stock |
| **Skydrop API integration** | Implemented | [admin/php/skydropx.php](admin/php/skydropx.php) — automatic quote + arrival ETA |
| **CODIGO REFERIDO generation** | Implemented | Per-point `codigo_venta` (offline) + `codigo_electronico` (online) |
| **SMS OTP delivery verification** | Implemented | [admin/php/checklists/enviar-otp.php](admin/php/checklists/enviar-otp.php) — SMSMasivos API, 6 digits, 15-minute expiry |
| **Face-photo verification** | Implemented | [admin/php/checklists/face-compare.php](admin/php/checklists/face-compare.php) — returns `face_score` |
| **Delivery checklist + photos** | Implemented | 4 checks + 3 photos (front / side / rear) |
| **ACTA DE ENTREGA signing** | Implemented | [clientes/](clientes/) — digital signature, name confirmation + acceptance checkbox |
| **Client Panel delivery tracking** | Implemented | `entrega.js` — step-by-step stepper UI |
| **Configurator 4-step flow** | Implemented | [configurador_prueba/](configurador_prueba/) — model · color · delivery · payment |
| **CASE 1-A** (no referral · no point selected) | Implemented | Stored with `punto_id='centro-cercano'`, waits for assignment |
| **CASE 1-B** (no referral · point picked in configurador) | Implemented | `paso3-delivery.js` — `_renderCentros()` / `_selectCentro()` provide the point picker (initial analysis was wrong to call this missing) |
| **CASE 3** (referral, general sale) | Implemented | `validar-referido.php` + `confirmar-orden.php` `caso=3` branch + `ventas_count` increment |
| **CASE 4** (referral, showroom sale) | Implemented | `puntosvoltika/php/asignar/referido.php` — `tipo_asignacion='consignacion'`, `ventas_log.tipo='venta_showroom'` |
| **Shipping without order (diagram 5)** | Partial | `admin-envios.js` supports "sin orden" shipments, but the `type of assignment` picker (showroom vs. for-delivery) is missing |
| **CODIGO REFERIDO validation** | Implemented | `configurador_prueba/php/validar-referido.php` + debounced check in `paso2-color.js` |
| **Point Panel assembly UI** | Implemented | `punto-inventario.js` — "🔧 Iniciar ensamble" / "✅ Marcar lista para entrega" buttons added |
| **LISTA PARA ENTREGA transition UI** | Implemented | `puntosvoltika/php/inventario/cambiar-estado.php` — state transition + date input + notification |
| **Pickup-date input** | Implemented | `cambiar-estado.php` requires `fecha_entrega_estimada` and embeds it in the `lista_para_recoger` notification |
| **CREDITO-specific face comparison** | Implemented | `verificar-rostro.php` detects CREDITO via `transacciones.tpago`, then compares `verificaciones_identidad.selfie_path` against the pickup photo via Truora |
| **Client Panel notification history** | Implemented | `clientes/php/cliente/notificaciones.php` + `notificaciones.js` (opened from the bell icon) |
| **Client Panel shipping ETA display** | Implemented | `entrega.js` Envío card + `inicio.js` dashboard surface `envio.fecha_estimada_llegada` |
| **Configurator point picker (CASE 1-B)** | Implemented | `paso3-delivery.js` `_renderCentros()` — initial gap analysis incorrectly flagged this as missing |

---

## 7. Gap Summary — Completion Status

### ✅ Completed items (9)

| # | Item | Implementation |
|---|------|----------------|
| 1 | Configurator point picker (CASE 1-B) | `paso3-delivery.js` — initially mis-classified as missing; already implemented |
| 2 | CODIGO REFERIDO validation | `validar-referido.php` + `paso2-color.js` |
| 3 | Pass `codigoReferido` into `confirmar-orden` and persist it | `confirmar-orden.php` determines `caso`, increments `referidos.ventas_count` |
| 4 | Pickup-date input on LISTA PARA ENTREGA transition | `puntosvoltika/php/inventario/cambiar-estado.php` |
| 5 | Point Panel assembly-completion UI | `punto-inventario.js` action buttons + status badges |
| 6 | CASE 3 vs. CASE 4 flow split | `asignar/referido.php` — `tipo_asignacion='consignacion'` + `venta_showroom` log |
| 7 | CREDITO-specific face comparison rule | `verificar-rostro.php` — Truora compares `verificaciones_identidad.selfie_path` against the pickup photo |
| 8 | Client Panel notification history | `cliente/notificaciones.php` + `notificaciones.js` (bell icon) |
| 9 | Client Panel Skydrop ETA exposure | `cliente/estado.php` + `entrega/estado.php` expose `envios.fecha_estimada_llegada` → rendered in `entrega.js` Envío card and `inicio.js` dashboard |

### 🟡 Remaining improvements (optional)

| Item | Notes |
|------|-------|
| **Shipping without order — "type of assignment" picker** | The `showroom sale` vs. `only for delivery` choice defined in diagram 5 is not yet exposed in `admin-envios.js`. Today `cotizar-envio.php` / `crear.php` work, but the assignment type is still managed manually. |

> The item above is out of scope for this round and can be tackled as follow-up work if the customer wants it.
