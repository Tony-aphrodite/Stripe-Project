# Voltika — Incomplete Features Guide
**Last updated:** 2026-04-15
**Launch date:** 2026-04-16

---

## 1. Cincel NOM-151 Digital Signature (5% complete)
**Priority:** CRITICAL | **Estimated effort:** 5-6 hours

### Current State
- OTP-based signature only (canvas capture)
- TODO block at `configurador_prueba/js/modules/paso-credito-contrato.js:657`
- Cincel API documented (sandbox.api.cincel.digital/v3)

### What Exists
- Canvas-based signature capture on contract page
- PDF contract generation with embedded signature image (`configurador_prueba/php/generar-contrato-pdf.php`)
- OTP validation flow for identity confirmation

### What Needs to Be Built
1. **Backend endpoint:** `configurador_prueba/php/cincel-firma.php`
   - Create document in Cincel API (POST /v3/documents)
   - Add signer (POST /v3/documents/{id}/signers)
   - Confirm signing (POST /v3/documents/{id}/confirm)
   - Store NOM-151 timestamp in DB
2. **Frontend integration:** Replace OTP submit with Cincel signing flow
   - After contract PDF is generated, send to Cincel
   - Redirect customer to Cincel signing page or embed iframe
   - On callback, store timestamp and mark contract as signed
3. **DB changes:** Add columns to `contratos` or `transacciones`:
   - `cincel_document_id VARCHAR(100)`
   - `cincel_timestamp TEXT`
   - `cincel_signed_at DATETIME`
4. **Webhook:** Receive Cincel confirmation callback
5. **Environment variables needed:**
   - `CINCEL_API_KEY`
   - `CINCEL_API_SECRET`
   - `CINCEL_WEBHOOK_URL`

### Relevant Files
- `configurador_prueba/js/modules/paso-credito-contrato.js` (lines 656-712)
- `configurador_prueba/php/generar-contrato-pdf.php`

### Legal Note
Without NOM-151, credit contracts may not be legally enforceable in Mexico. Use OTP-only as interim with documented legal risk.

---

## 2. WhatsApp Notifications (25% complete)
**Priority:** HIGH | **Estimated effort:** 2-3 hours

### Current State
- Email notifications: FULLY WORKING (PHPMailer, branded HTML templates)
- SMS notifications: WORKING (SMSMasivos API)
- WhatsApp: Stub function only, not sending

### What Exists
- 3 Meta-approved template names defined in `configurador_prueba/whatsapp-templates.md`:
  - `voltika_punto_asignado` (punto assigned to customer)
  - `voltika_en_camino` (moto in transit)
  - `voltika_lista_entrega` (ready for pickup)
- Phone normalization: `normalizarTelefonoMx()` in `configurador_prueba/php/notificaciones.php`
- Idempotency columns in DB: `notif_*_wa_sent_at`
- Stub function at `notificaciones.php:276`

### What Needs to Be Built
1. **Implement `enviarWhatsAppReal()`** in `configurador_prueba/php/notificaciones.php`
   - Use Meta Cloud API (graph.facebook.com/v18.0/{phone_id}/messages)
   - Or use SMSMasivos WhatsApp API if supported
   - Send template messages with variable substitution
2. **Environment variables needed:**
   - `WHATSAPP_PHONE_ID`
   - `WHATSAPP_API_TOKEN`
   - `WHATSAPP_WABA_ID`
3. **Error handling:** Log failures, retry logic, fallback to SMS

### Relevant Files
- `configurador_prueba/php/notificaciones.php` (lines 262-281)
- `configurador_prueba/whatsapp-templates.md`

---

## 3. Sales Analytics & Reports (75% complete)
**Priority:** MEDIUM | **Estimated effort:** 3 hours

### Current State
- Admin UI modules exist and are functional
- 4 backend report endpoints working
- Basic KPIs and charts rendering

### What Exists
- `admin/js/modules/admin-analytics.js` — KPIs, trend chart, sales by model/payment/punto
- `admin/js/modules/admin-reportes.js` — Ventas, Financiero, Cartera, Inventario reports
- Backend endpoints:
  - `admin/php/reportes/ventas.php`
  - `admin/php/reportes/financiero.php`
  - `admin/php/reportes/cartera.php`
  - `admin/php/reportes/inventario.php`
- CSV export for all reports

### What Needs to Be Built
1. **Period-over-period comparison** — Compare current week/month vs previous
2. **Dashboard drill-down** — Click KPI metric to filter detail view
3. **Advanced date range filters** — Custom start/end dates
4. **Predictive analytics** — Simple sales forecasting based on trends
5. **Chart improvements** — Line charts for trends, pie charts for distribution

### Relevant Files
- `admin/js/modules/admin-analytics.js`
- `admin/js/modules/admin-reportes.js`
- `admin/php/reportes/*.php`

---

## 4. Admin Models CRUD (90% complete)
**Priority:** LOW | **Estimated effort:** 1-2 hours

### Current State
- Full CRUD UI exists and works
- List, create, edit, toggle active/inactive implemented

### What Exists
- `admin/js/modules/admin-modelos.js` — Complete UI
- Backend:
  - `GET admin/php/modelos/listar.php`
  - `POST admin/php/modelos/guardar.php`
  - `POST admin/php/modelos/toggle.php`

### What Needs to Be Built
1. **Delete model endpoint** — Soft delete with inventory check warning
2. **Image upload** — Currently URL-only, needs file upload handler
3. **Sync with configurator** — Models in admin DB vs hardcoded in `configurador_prueba/js/data/productos.js` need synchronization. Either:
   - Option A: Configurator reads from DB API instead of hardcoded JS
   - Option B: Admin generates/exports `productos.js` on model save

### Relevant Files
- `admin/js/modules/admin-modelos.js`
- `admin/php/modelos/*.php`
- `configurador_prueba/js/data/productos.js` (hardcoded models)

---

## 5. Pricing Management (80% complete)
**Priority:** LOW | **Estimated effort:** 2 hours

### Current State
- Admin UI exists with price tier editing
- Promotion management included

### What Exists
- `admin/js/modules/admin-precios.js` — Full editing UI
- Backend:
  - `GET admin/php/precios/listar.php`
  - `POST admin/php/precios/guardar.php`
- Price fields: enganche min/max, weekly payment, interest rate, term weeks, MSI months
- Promotion fields: name, discount %, active flag

### What Needs to Be Built
1. **Delete pricing config endpoint** — `POST admin/php/precios/eliminar.php`
2. **Audit trail** — Log who changed prices and when
3. **Price history** — Version pricing configs, allow rollback
4. **Promo code validation** — Date ranges, usage limits, per-customer limits
5. **Sync with configurator** — Same issue as Models: prices hardcoded in `productos.js`

### Relevant Files
- `admin/js/modules/admin-precios.js`
- `admin/php/precios/*.php`
- `configurador_prueba/js/data/productos.js` (hardcoded prices)

---

## 6. Alerts Engine (60% complete)
**Priority:** MEDIUM | **Estimated effort:** 3-4 hours

### Current State
- Backend fully implemented with 6 alert types
- Frontend UI partially built

### What Exists
- `admin/php/alertas/listar.php` — Calculates alerts from live DB data:
  - Low inventory (<=3 units per model)
  - High demand (sales > stock)
  - Increasing delinquency (>5 overdue in 7 days)
  - Failed payment spike (>3 in 7 days)
  - Stuck units (>14 days in same state)
  - High-performing models (info-level)

### What Needs to Be Built
1. **Admin UI module** — `admin/js/modules/admin-alertas.js`
   - Display alerts list with priority badges (alta/media/info)
   - Acknowledge/dismiss alerts
   - Filter by type and priority
2. **Email/SMS triggers** — Send alerts to admin when thresholds are hit
3. **Configurable thresholds** — Admin-editable alert settings (currently hardcoded: 3 units, 5 delinquent, etc.)
4. **Alert history** — Store triggered alerts with timestamps
5. **Dashboard integration** — Show alert count badge in sidebar nav

### Relevant Files
- `admin/php/alertas/listar.php`
- `admin/js/modules/` (needs new `admin-alertas.js`)

---

## 7. Global Search (90% complete)
**Priority:** LOW | **Estimated effort:** 1-2 hours

### Current State
- Working search across 4 entity types

### What Exists
- `admin/js/modules/admin-buscar.js` — Search UI with grouped results
- `admin/php/buscar/global.php` — Searches customers, orders, inventory, subscriptions
- Minimum 2 chars, 50 results per type

### What Needs to Be Built
1. **Fuzzy matching** — Typo tolerance (e.g., "Volrika" matches "Voltika")
2. **Recent searches** — Store last 10 searches per user
3. **Advanced filters** — Date range, status, entity type toggles
4. **Search result actions** — Quick actions (view, edit) from search results

### Relevant Files
- `admin/js/modules/admin-buscar.js`
- `admin/php/buscar/global.php`

---

## 8. Collections / Cobranza (85% complete)
**Priority:** HIGH | **Estimated effort:** 3 hours

### Current State
- Full UI with KPIs and aging buckets
- 7 backend endpoints working

### What Exists
- `admin/js/modules/admin-cobranza.js` — Dashboard, payment list, action buttons
- Backend endpoints:
  - `admin/php/pagos/cobranza.php` — KPIs + aging buckets (1-7d, 8-30d, 30+d)
  - `admin/php/pagos/cobrar-ahora.php` — Charge card immediately
  - `admin/php/pagos/reintentar.php` — Retry failed payment
  - `admin/php/pagos/generar-link.php` — Send payment link
  - `admin/php/pagos/marcar-pagado.php` — Manual override
  - `admin/php/pagos/listar.php` — List payment cycles
  - `admin/php/pagos/detalle.php` — Single cycle details

### What Needs to Be Built
1. **Bulk retry** — `POST admin/php/pagos/bulk-reintentar.php`
   - Select multiple overdue cycles and retry all at once
2. **Auto-retry scheduling** — Cron-based automatic retry on failed payments
3. **Payment renegotiation** — `POST admin/php/pagos/renegociar.php`
   - Extend term, reduce amount, pause payments
4. **Charge-off logic** — `POST admin/php/pagos/dar-baja.php`
   - Write off after 60+ days, flag account
5. **Delinquency notification templates** — Auto-send SMS/email reminders at 1, 7, 15, 30 days
6. **Production testing needed** — All "Cobrar ahora" and "Reintentar" buttons must be tested with live Stripe

### Relevant Files
- `admin/js/modules/admin-cobranza.js`
- `admin/php/pagos/*.php`

---

## 9. Cron Jobs (40% complete)
**Priority:** HIGH | **Estimated effort:** 4 hours

### Current State
- Only 1 cron job exists: Stripe reconciliation

### What Exists
- `admin/cron/verificar-stripe-cron.sh` — Reconcile PaymentIntents every 15 min
- `admin/cron/README.md` — Setup documentation
- `admin/cron/TESTING.md` — Test instructions
- Token-based auth for cron security

### What Needs to Be Built
1. **Payment cycle generation** — Weekly cron to create new payment cycles for active subscriptions
2. **Delinquency reminders** — Daily cron to send SMS/email for overdue payments
3. **Auto-charge attempts** — Daily cron to retry charges on overdue subscriptions
4. **Daily report digest** — Email summary to admin (sales, payments, inventory)
5. **Stuck unit alerts** — Daily check for motos stuck >14 days in same estado
6. **Expired promo cleanup** — Remove/deactivate expired promotions

### Environment Variables Needed
```bash
VOLTIKA_BASE_URL=https://voltika.mx
VOLTIKA_CRON_TOKEN=<generate-random-32-hex>
VOLTIKA_CRON_HORAS=2
VOLTIKA_CRON_LOG_DIR=/var/log/voltika
```

### Crontab Setup
```bash
# Stripe reconciliation (every 15 min)
*/15 * * * * /path/to/admin/cron/verificar-stripe-cron.sh

# Payment cycle generation (every Monday 6am)
0 6 * * 1 curl -s -H "X-Cron-Token: $TOKEN" "$BASE_URL/admin/cron/generar-ciclos.php"

# Delinquency reminders (daily 9am)
0 9 * * * curl -s -H "X-Cron-Token: $TOKEN" "$BASE_URL/admin/cron/recordatorios.php"

# Auto-charge retry (daily 10am)
0 10 * * * curl -s -H "X-Cron-Token: $TOKEN" "$BASE_URL/admin/cron/auto-cobro.php"
```

### Relevant Files
- `admin/cron/verificar-stripe-cron.sh`
- `admin/cron/README.md`

---

## Implementation Priority Order

### Phase 1 — Before/During Launch Week
| # | Feature | Effort | Impact |
|---|---------|--------|--------|
| 1 | Cron jobs (payment cycles + Stripe reconciliation) | 4h | Payment collection fails without this |
| 2 | WhatsApp notifications | 2-3h | Customer communication gap |
| 3 | Collections production testing | 2h | Payment actions may fail |

### Phase 2 — Week 2
| # | Feature | Effort | Impact |
|---|---------|--------|--------|
| 4 | Cincel NOM-151 digital signature | 5-6h | Legal compliance for credit sales |
| 5 | Alerts engine UI | 3-4h | Admin can't see inventory/payment warnings |
| 6 | Delinquency auto-reminders (cron) | 2h | Manual follow-up required without this |

### Phase 3 — Week 3+
| # | Feature | Effort | Impact |
|---|---------|--------|--------|
| 7 | Analytics drill-down & comparisons | 3h | Better business insights |
| 8 | Models/Pricing sync with configurator | 3h | Currently requires code changes to update |
| 9 | Collections bulk operations | 2h | One-by-one retry is slow at scale |
| 10 | Global search improvements | 1-2h | Nice to have |
