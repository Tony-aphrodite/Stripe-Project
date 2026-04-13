# VOLTIKA Master Dashboard System - Implementation Analysis

**Document:** Main Dashboard directives april 9 2026.docx
**Analysis Date:** April 13, 2026
**Status:** Implementation Gap Analysis

---

## Document Summary

The client document defines a **real-time web dashboard** ("Master Dashboard System") with 13 modules designed to control the entire Voltika operation from a single interface. Voltika is a commercial company selling motorcycles with payment facilities (NOT a financial institution). All UI must be in Spanish; all system logic in English.

**Core Principle:** The system must show business status in 10 seconds, allow charging in 1-2 clicks, prevent operational errors, scale with minimal staff, and run in real time. This is NOT a passive analytics tool but an active **control center**.

---

## Module-by-Module Analysis

### MODULE 1: Executive Real-Time View (TOP DASHBOARD)

**Required KPIs:**
| KPI (EN) | UI Text (ES) | Status |
|----------|-------------|--------|
| Sales today | Ventas hoy | IMPLEMENTED |
| Sales this week | Ventas semana | IMPLEMENTED |
| Cash collected today | Cobrado hoy | IMPLEMENTED |
| Expected cash flow | Flujo esperado | IMPLEMENTED |
| Current portfolio (on-time) | Cartera al corriente | IMPLEMENTED |
| Overdue portfolio | Cartera vencida | IMPLEMENTED |
| Units available | Inventario disponible | IMPLEMENTED |
| Units reserved | Unidades apartadas | IMPLEMENTED |
| Units in transit | En transito | IMPLEMENTED |
| Pending deliveries | Entregas pendientes | IMPLEMENTED |

**Current Implementation:**
- File: `admin/php/dashboard/kpis.php` - All 10 KPIs are queried and returned
- File: `admin/js/admin-dashboard.js` - Frontend renders KPI cards
- Data sources: `transacciones`, `ciclos_pago`, `inventario_motos`, `subscripciones_credito` tables

**Status: IMPLEMENTED** - All 10 KPIs exist and display in the admin dashboard.

**Gaps:**
- Real-time refresh mechanism (auto-polling or WebSocket) needs verification
- Spanish labels need to be verified against exact document requirements

---

### MODULE 2: Sales Module

**Required Features:**

| Feature | Status |
|---------|--------|
| Filter by day/week/month | PARTIAL |
| Filter by model | PARTIAL |
| Filter by city | NOT IMPLEMENTED |
| Filter by sales channel (web, point, referral) | NOT IMPLEMENTED |
| Filter by payment type (cash, MSI, financed) | PARTIAL |
| Units sold per model | NOT IMPLEMENTED |
| Revenue per model | NOT IMPLEMENTED |
| Average ticket | NOT IMPLEMENTED |
| Estimated margin per model | NOT IMPLEMENTED |
| Comparison vs previous periods | NOT IMPLEMENTED |
| Top selling models | NOT IMPLEMENTED |
| Sales per advisor/point | NOT IMPLEMENTED |

**Current Implementation:**
- File: `admin/js/admin-ventas.js` - Sales list with bike assignment
- File: `admin/php/ventas/listar.php` - Basic sales listing
- Focuses on **order management** (assigning bikes to orders), NOT sales analytics

**Status: PARTIALLY IMPLEMENTED** - Basic sales listing exists but lacks analytics, filters, and metrics.

**Required Work:**
1. Create sales analytics API endpoints with aggregation queries
2. Build filter UI (day/week/month, model, city, channel, payment type)
3. Add chart visualizations (units per model, revenue, comparison)
4. Add calculated fields: ticket promedio, margin estimates
5. Create `admin/php/ventas/analytics.php` endpoint
6. Create `admin/php/ventas/top-models.php` endpoint
7. Add city field to transactions or derive from punto data

---

### MODULE 3: Inventory Module

**Required Features:**

| Feature | Status |
|---------|--------|
| Total inventory | IMPLEMENTED |
| Available units | IMPLEMENTED |
| Reserved units | IMPLEMENTED |
| Sold units | IMPLEMENTED |
| Delivered units | IMPLEMENTED |
| In transit units | IMPLEMENTED |
| Inventory per warehouse (CEDIS) | IMPLEMENTED |
| Inventory per point | IMPLEMENTED |
| Inventory in assembly | IMPLEMENTED |
| Blocked units | IMPLEMENTED |
| Search by VIN | IMPLEMENTED |
| Search by model and color | PARTIAL |
| Unit movement history | IMPLEMENTED (log_estados JSON) |
| Low stock alerts | NOT IMPLEMENTED |
| Stuck inventory alerts | NOT IMPLEMENTED |
| Mismatch alerts | NOT IMPLEMENTED |

**Current Implementation:**
- File: `admin/js/admin-inventario.js` - Full inventory management UI
- File: `admin/php/inventario/listar.php` - Inventory list with state filters
- File: `admin/php/inventario/detalle.php` - Unit detail with history
- Database: `inventario_motos` table with states: por_llegar, recibida, por_ensamblar, en_ensamble, lista_para_entrega, entregada, retenida
- `log_estados` JSON field tracks movement history

**Status: MOSTLY IMPLEMENTED** - Core inventory tracking is solid. Missing alert system.

**Required Work:**
1. Add alert engine for low stock (by model threshold)
2. Add stuck inventory detection (days in same state > threshold)
3. Add mismatch alert (expected vs actual counts)
4. Add model+color combined search filter
5. Create `admin/php/inventario/alertas.php` endpoint

---

### MODULE 4: Master Inputs (Admin Panel)

#### 4.1 Models Management

| Feature | Status |
|---------|--------|
| Name | PARTIAL (in configurador) |
| Category | PARTIAL |
| Price (cash) | PARTIAL |
| Price (financed) | PARTIAL |
| Cost | NOT IMPLEMENTED |
| Battery configuration | PARTIAL |
| Specs (speed, range, torque) | PARTIAL |
| Images | PARTIAL |
| Status (active/inactive) | NOT IMPLEMENTED |

**Current Implementation:**
- Model data is hardcoded in the configurador checkout flow
- No dedicated admin CRUD for models management
- File: `admin/php/ventas/modelos-colores.php` - Returns available models/colors (read-only)

**Status: NOT IMPLEMENTED** - No admin UI to manage models. Data lives in configurador code.

**Required Work:**
1. Create `modelos` database table (name, category, precio_contado, precio_financiado, costo, bateria, velocidad, autonomia, torque, imagenes, activo)
2. Create admin CRUD API: `admin/php/modelos/listar.php`, `guardar.php`, `toggle.php`
3. Create admin UI module: `admin/js/admin-modelos.js`
4. Migrate hardcoded model data to database
5. Update configurador to read from models table

#### 4.2 Pricing & Conditions

| Feature | Status |
|---------|--------|
| Update prices | NOT IMPLEMENTED (admin panel) |
| Update down payment | NOT IMPLEMENTED (admin panel) |
| Update weekly payments | NOT IMPLEMENTED (admin panel) |
| Update internal rates | NOT IMPLEMENTED |
| Promotions | NOT IMPLEMENTED |
| MSI options | PARTIAL (hardcoded) |

**Status: NOT IMPLEMENTED** - Pricing is managed in code, not through admin panel.

**Required Work:**
1. Create `precios_condiciones` table (modelo_id, enganche_min, enganche_max, pago_semanal, tasa_interna, msi_opciones JSON, promocion_activa)
2. Admin CRUD API + UI for pricing management
3. Create promotions engine

#### 4.3 Delivery Configuration

| Feature | Status |
|---------|--------|
| Delivery times per model | NOT IMPLEMENTED |
| Delivery times per city | NOT IMPLEMENTED |
| Availability display | PARTIAL |

**Status: NOT IMPLEMENTED** - No configurable delivery time management.

**Required Work:**
1. Create `tiempos_entrega` table (modelo_id, ciudad, dias_estimados, disponible_inmediato)
2. Admin CRUD API + UI
3. Surface data in configurador checkout

#### 4.4 Points & CEDIS Management

| Feature | Status |
|---------|--------|
| Create/edit points | IMPLEMENTED |
| Assign capacity | IMPLEMENTED |
| Activate/deactivate | IMPLEMENTED |

**Current Implementation:**
- File: `admin/js/admin-puntos.js` - Point management UI
- File: `admin/php/puntos/listar.php`, `guardar.php` - CRUD endpoints
- Database: `puntos_voltika` table with capacidad, activo, tipo

**Status: IMPLEMENTED**

---

### MODULE 5: Collections Module (CRITICAL)

**Required Features:**

| Feature | Status |
|---------|--------|
| Collected today | IMPLEMENTED (KPI) |
| Pending today | IMPLEMENTED |
| Overdue customers | IMPLEMENTED |
| Overdue buckets (1-7, 8-30, 30+ days) | NOT IMPLEMENTED |
| Failed payments | NOT IMPLEMENTED |
| Customers without active card | NOT IMPLEMENTED |
| Pending OXXO payments | NOT IMPLEMENTED |
| Pending transfers | NOT IMPLEMENTED |
| Action: Charge now | NOT IMPLEMENTED |
| Action: Generate payment link | NOT IMPLEMENTED |
| Action: Retry charge | NOT IMPLEMENTED |
| Action: Mark as paid | PARTIAL |
| Action: Change card | NOT IMPLEMENTED (admin side) |

**Current Implementation:**
- File: `admin/js/admin-pagos.js` - Payment listing and detail view
- File: `admin/php/pagos/listar.php` - List payment cycles
- File: `admin/php/pagos/detalle.php` - Cycle detail
- File: `admin/php/pagos/verificar.php` - Verify Stripe payment
- Database: `ciclos_pago` table with states (pending, paid_manual, paid_auto, overdue, skipped)
- Cron: `clientes/php/cron/mark-overdue.php` - Auto-mark overdue cycles

**Status: PARTIALLY IMPLEMENTED** - Basic payment tracking exists. Missing critical collection actions and segmentation.

**Required Work (HIGH PRIORITY):**
1. Add overdue bucketing query (1-7, 8-30, 30+ days based on fecha_vencimiento)
2. Create `admin/php/pagos/cobrar-ahora.php` - Charge customer's card via Stripe API
3. Create `admin/php/pagos/generar-link.php` - Generate payment link
4. Create `admin/php/pagos/reintentar.php` - Retry failed charge
5. Create `admin/php/pagos/marcar-pagado.php` - Manual mark as paid
6. Create `admin/php/pagos/cambiar-tarjeta.php` - Update payment method
7. Add filters: failed payments, no active card, pending OXXO, pending transfer
8. Build collections action buttons in UI ("Cobrar ahora", "Enviar link", "Reintentar", "Marcar como pagado")
9. Add real-time refresh to collections view

---

### MODULE 6: Alerts & Decision Engine

**Required Alerts:**

| Alert | Status |
|-------|--------|
| Low inventory | NOT IMPLEMENTED |
| High demand models | NOT IMPLEMENTED |
| Increasing delinquency | NOT IMPLEMENTED |
| Failed payments spike | NOT IMPLEMENTED |
| Slow-selling points | NOT IMPLEMENTED |
| Stuck units | NOT IMPLEMENTED |
| High-performing models | NOT IMPLEMENTED |

**Current Implementation:** No alerts or decision engine exists.

**Status: NOT IMPLEMENTED**

**Required Work:**
1. Create `alertas` table (tipo, mensaje, prioridad, fecha, resuelta)
2. Create alert calculation engine: `admin/php/alertas/generar.php`
3. Define thresholds per alert type (configurable via admin)
4. Create `admin/php/alertas/listar.php` - Get active alerts
5. Create `admin/js/admin-alertas.js` - Alerts panel UI
6. Add alert badge/counter to dashboard header
7. Implement cron job or real-time trigger for alert generation

---

### MODULE 7: Notifications Module

**Required Channels:**

| Channel | Status |
|---------|--------|
| Email | IMPLEMENTED |
| WhatsApp | NOT IMPLEMENTED |

**Required Triggers:**

| Trigger | Status |
|---------|--------|
| Upcoming payment | IMPLEMENTED (cron reminders) |
| Due payment | IMPLEMENTED |
| Missed payment | IMPLEMENTED |
| Payment confirmed | PARTIAL |
| Unit assigned | IMPLEMENTED |
| Unit in transit | IMPLEMENTED |
| Ready for pickup | IMPLEMENTED |
| Documents available | NOT IMPLEMENTED |
| Card update required | NOT IMPLEMENTED |

**Current Implementation:**
- File: `clientes/php/cron/reminders.php` - Payment reminders via SMS/email
- Database: `portal_recordatorios_log`, `portal_preferencias`
- SMSMasivos API for SMS delivery
- PHPMailer for email delivery
- Template-based messages with placeholder substitution

**Status: PARTIALLY IMPLEMENTED** - SMS/Email notifications work for payments and logistics. WhatsApp is missing. Some triggers not connected.

**Required Work:**
1. Integrate WhatsApp Business API (or WhatsApp via SMSMasivos if supported)
2. Add triggers: documents_available, card_update_required
3. Create notification management UI in admin panel
4. Add notification history/log viewer in admin
5. Create WhatsApp message templates

---

### MODULE 8: Reports Module

**Required Reports:**

| Report | Status |
|--------|--------|
| Sales: daily/weekly/monthly | NOT IMPLEMENTED |
| Sales: by model | NOT IMPLEMENTED |
| Sales: by city | NOT IMPLEMENTED |
| Sales: by channel | NOT IMPLEMENTED |
| Financial: collected revenue | NOT IMPLEMENTED |
| Financial: projected revenue | NOT IMPLEMENTED |
| Financial: margins | NOT IMPLEMENTED |
| Portfolio: current vs overdue | NOT IMPLEMENTED |
| Portfolio: aging buckets | NOT IMPLEMENTED |
| Portfolio: recovery rate | NOT IMPLEMENTED |
| Inventory: stock by model | NOT IMPLEMENTED |
| Inventory: stock by location | NOT IMPLEMENTED |
| Inventory: turnover | NOT IMPLEMENTED |

**Current Implementation:**
- `admin/php/buro/exportar.php` exists for credit bureau data export
- No general reporting system

**Status: NOT IMPLEMENTED**

**Required Work:**
1. Create report generation engine
2. Create API endpoints per report type:
   - `admin/php/reportes/ventas.php`
   - `admin/php/reportes/financiero.php`
   - `admin/php/reportes/cartera.php`
   - `admin/php/reportes/inventario.php`
3. Add date range filters, model/city/channel filters
4. Add PDF/Excel export capability
5. Create `admin/js/admin-reportes.js` UI module
6. Add chart visualizations (line charts, bar charts, pie charts)
7. Add route to admin panel navigation

---

### MODULE 9: Document Module

**Required Features:**

| Feature | Status |
|---------|--------|
| Store contract | IMPLEMENTED |
| Store delivery act | IMPLEMENTED |
| Store invoice (factura) | IMPLEMENTED |
| Store carta factura | PARTIAL |
| Store insurance | NOT IMPLEMENTED |
| Store ID (INE) | IMPLEMENTED |
| Store promissory note (pagare) | NOT IMPLEMENTED |
| Upload documents | PARTIAL |
| View documents | IMPLEMENTED |
| Download documents | IMPLEMENTED |
| Log upload activity | IMPLEMENTED |

**Current Implementation:**
- File: `clientes/php/documentos/lista.php` - List documents per customer
- File: `clientes/php/documentos/descargar.php` - Download with audit log
- Database: `portal_descargas_log` for tracking
- Digital signatures via Cincel (NOM-151): `firmas_contratos` table
- Checklist photos stored and managed

**Status: PARTIALLY IMPLEMENTED** - Core document viewing/downloading works from customer portal. Missing admin-side document management, insurance tracking, and pagare storage.

**Required Work:**
1. Create admin document management view per customer
2. Add document type: insurance (seguro)
3. Add document type: promissory note (pagare)
4. Create admin upload interface for each document type
5. Create `admin/php/documentos/` endpoint set
6. Create `admin/js/admin-documentos.js` module
7. Add bulk document status tracking (which customers have all docs complete)

---

### MODULE 10: Points Management

**Required Features:**

| Feature | Status |
|---------|--------|
| Sales per point | PARTIAL |
| Inventory per point | IMPLEMENTED |
| Pending deliveries per point | IMPLEMENTED |
| Performance metrics | NOT IMPLEMENTED |
| Incidents tracking | NOT IMPLEMENTED |

**Current Implementation:**
- File: `admin/js/admin-puntos.js` - Punto management
- File: `admin/php/puntos/listar.php` - List with basic data
- Punto portal (`/puntosvoltika/`) has its own dashboard

**Status: PARTIALLY IMPLEMENTED** - Basic punto management exists. Missing performance analytics and incident tracking.

**Required Work:**
1. Add performance metrics per punto (sales velocity, delivery time, customer satisfaction)
2. Create incidents tracking table and UI
3. Add comparative punto performance view
4. Create `admin/php/puntos/performance.php` endpoint
5. Create `admin/php/puntos/incidencias.php` CRUD endpoints

---

### MODULE 11: Security & Roles

**Required Roles:**

| Role | Status |
|------|--------|
| admin | IMPLEMENTED |
| sales | NOT IMPLEMENTED (has "dealer") |
| collections | NOT IMPLEMENTED |
| logistics | NOT IMPLEMENTED (has "cedis") |
| documents | NOT IMPLEMENTED |

**Required Logging:**

| Action | Status |
|--------|--------|
| Price changes | NOT IMPLEMENTED |
| Payments | PARTIAL |
| Document uploads | IMPLEMENTED |
| Inventory movements | IMPLEMENTED |

**Current Implementation:**
- File: `admin/php/bootstrap.php` - Session and role checking
- Database: `dealer_usuarios` with roles: admin, dealer, cedis, operador
- Database: `admin_log` for action audit trail
- Role-based route restriction exists but is basic

**Status: PARTIALLY IMPLEMENTED** - Basic role system exists with different names. Need to map/expand roles and add granular permissions.

**Required Work:**
1. Map existing roles to required roles: dealer→sales, cedis→logistics
2. Add new roles: collections, documents
3. Implement granular permission matrix per module
4. Add price change logging to admin_log
5. Add payment action logging
6. Create role management UI in admin panel

---

### MODULE 12: Global Search

**Required Search Fields:**

| Field | Status |
|-------|--------|
| Name | NOT IMPLEMENTED |
| Phone | NOT IMPLEMENTED |
| Email | NOT IMPLEMENTED |
| Order | NOT IMPLEMENTED |
| VIN | NOT IMPLEMENTED (only within inventory module) |

**Current Implementation:** No global search exists. VIN search is limited to inventory module.

**Status: NOT IMPLEMENTED**

**Required Work:**
1. Create `admin/php/buscar/global.php` - Cross-table search endpoint
2. Search across: clientes (nombre, telefono, email), transacciones (order ID), inventario_motos (VIN)
3. Add search bar to admin header/navbar
4. Return grouped results by type (customers, orders, units)
5. Add keyboard shortcut for quick search (Ctrl+K or /)

---

### MODULE 13: System Principle (Non-Functional Requirements)

| Requirement | Status |
|-------------|--------|
| Show business status in 10 seconds | NEEDS VERIFICATION |
| Allow charging in 1-2 clicks | NOT IMPLEMENTED |
| Prevent operational errors | PARTIAL |
| Scale with minimal staff | PARTIAL |
| Run in real time | PARTIAL |

---

## Implementation Priority Matrix

### PHASE 1 - CRITICAL (Immediate)
1. **Collections Module Actions** (Module 5) - "Cobrar ahora", retry, payment links
2. **Global Search** (Module 12) - Cross-system search
3. **Overdue Bucketing** (Module 5) - 1-7, 8-30, 30+ day segments

### PHASE 2 - HIGH PRIORITY (Next Sprint)
4. **Sales Analytics** (Module 2) - Filters, metrics, charts
5. **Alerts Engine** (Module 6) - Automated alert generation
6. **Reports Module** (Module 8) - Sales, financial, portfolio, inventory reports
7. **Inventory Alerts** (Module 3) - Low stock, stuck units, mismatches

### PHASE 3 - MEDIUM PRIORITY
8. **Models Admin** (Module 4.1) - CRUD for motorcycle models
9. **Pricing Admin** (Module 4.2) - Configurable pricing/promotions
10. **Document Management** (Module 9) - Admin-side doc management, new doc types
11. **Role Expansion** (Module 11) - New roles, permission matrix

### PHASE 4 - LOWER PRIORITY
12. **WhatsApp Integration** (Module 7) - WhatsApp Business API
13. **Delivery Configuration** (Module 4.3) - Configurable delivery times
14. **Points Performance** (Module 10) - Performance metrics, incidents
15. **Remaining Notification Triggers** (Module 7) - documents_available, card_update

---

## Summary Scorecard

| Module | Status | Completion |
|--------|--------|------------|
| 1. Executive Dashboard | IMPLEMENTED | 90% |
| 2. Sales Module | PARTIAL | 25% |
| 3. Inventory Module | MOSTLY DONE | 80% |
| 4. Master Inputs | PARTIAL | 30% |
| 4.1 Models | NOT DONE | 10% |
| 4.2 Pricing | NOT DONE | 5% |
| 4.3 Delivery | NOT DONE | 0% |
| 4.4 Points/CEDIS | DONE | 95% |
| 5. Collections | PARTIAL | 30% |
| 6. Alerts Engine | NOT DONE | 0% |
| 7. Notifications | PARTIAL | 50% |
| 8. Reports | NOT DONE | 5% |
| 9. Documents | PARTIAL | 45% |
| 10. Points Mgmt | PARTIAL | 40% |
| 11. Security/Roles | PARTIAL | 50% |
| 12. Global Search | NOT DONE | 0% |
| 13. System Principle | PARTIAL | 40% |
| **OVERALL** | | **~35%** |

---

## Technical Architecture Notes

**Current Stack:** PHP 7.4+ / MySQL / Vanilla JS (jQuery) / Stripe API
**External Services:** Stripe, SMSMasivos, Truora, CDC, Skydropx, Cincel Digital

**Key Files for Implementation:**
- Admin routes: `admin/js/admin-app.js` (add new routes here)
- Admin bootstrap: `admin/php/bootstrap.php` (session/role logic)
- Database schema: `configurador_prueba/php/master-bootstrap.php`
- Config: `configurador_prueba/php/config.php`
- KPIs reference: `admin/php/dashboard/kpis.php`

**For each new module, create:**
1. PHP API endpoint(s) in `admin/php/{module}/`
2. JS frontend module in `admin/js/admin-{module}.js`
3. Route entry in `admin/js/admin-app.js`
4. Database table(s) via `master-bootstrap.php` or migration script
