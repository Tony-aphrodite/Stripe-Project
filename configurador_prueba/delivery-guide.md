# Voltika Dealer Panel — Guide

## Overview

The Dealer Panel is a web-based management system for Voltika authorized dealers to track motorcycles from arrival to delivery. It connects directly to the customer checkout flow — when a customer completes a purchase, the motorcycle automatically appears in the dealer panel ready for tracking.

**Access:** https://www.voltika.mx/configurador_prueba/dealer-panel.html

---

## 1. Login

- Enter your dealer email and password
- Session persists until you log out
- Each dealer sees only their assigned point's motorcycles

---

## 2. Dashboard

After login, the main dashboard shows:

### Stats Bar
- **Pendientes** — deliveries waiting
- **En piso** — units at the dealer location
- **Ensamble** — units currently being assembled

### Two Sections

**Voltika Entrega** — Motorcycles assigned by Voltika for delivery to a specific customer. These are NOT available for sale.

**Consignacion** — Motorcycles available for point-of-sale at the dealer location.

### Motorcycle Cards

Each motorcycle is displayed as a card showing:
- Customer name, model, color, VIN
- Current status (bold label)
- 7-step progress tracker (right side)
- Time spent in current step
- Payment status (Pagada / Pendiente)
- Action button for the next step

---

## 3. The 7-Step Tracking Flow

Every motorcycle follows this lifecycle:

| Step | Status | Description |
|------|--------|-------------|
| 1 | **Por Llegar** | Motorcycle is on its way to the dealer point |
| 2 | **Recibida** | Motorcycle arrived at the dealer — dealer confirms reception |
| 3 | **Por Ensamblar** | Queued for assembly |
| 4 | **En Ensamble** | Assembly in progress |
| 5 | **Lista para Entrega** | Assembly complete, ready for customer delivery |
| 6 | **Por Validar Entrega** | Delivery validation in progress (OTP + checklist) |
| 7 | **Entregada** | Motorcycle delivered to customer |

### How to advance each step

The dealer clicks the **action button** on each card:

- "Registrar llegada" → moves from *Por Llegar* to *Recibida*
- "Iniciar ensamble" → moves to *En Ensamble*
- "Terminar ensamble" → moves to *Lista para Entrega*
- "Iniciar validacion" → moves to *Por Validar Entrega*

Each action asks for confirmation and optional notes.

---

## 4. Pre-Delivery Checklist

When a motorcycle reaches **Lista para Entrega** or **Por Validar Entrega**, the dealer can open the **Pre-Delivery Checklist** (button on the card).

The checklist has 13 items across 4 sections:

**Physical Inspection**
- Revision fisica general
- Revision electrica
- Carga de bateria

**Functional Test**
- Luces OK
- Frenos OK
- Velocimetro OK

**Documentation**
- Documentos completos
- Llaves entregadas
- Manual entregado

**Verification**
- Identidad verificada
- Datos confirmados
- QR pedido OK
- QR moto OK

When all items are checked and the dealer clicks "Completar", the motorcycle advances to the delivery validation phase.

---

## 5. Identity Verification (Face Comparison)

When a motorcycle is in **Lista para Entrega** or **Por Validar Entrega**, the dealer sees a **"Verificar identidad"** button on the card.

Clicking it shows:
- The customer's **selfie photo** taken during the credit application (Truora verification)
- The customer's **INE front and back** images
- Verification status (approved / rejected)

The dealer uses this to **compare the person picking up the motorcycle** with the person who applied for credit, ensuring the same person collects the unit.

---

## 6. QR Scanner

The top navigation bar includes a **QR Scanner** button. It supports two types:

- **QR de Pedido** — Scans order QR codes (format: `VK-PEDIDO:number`)
- **QR de Moto** — Scans motorcycle QR codes (format: `VK-MOTO:vin`)

The scanner uses the device camera. There is also a manual input option for entering codes by hand.

---

## 7. Customer Delivery Confirmation (OTP)

The final delivery step involves the customer:

1. Customer visits the confirmation page (or scans a QR code)
2. Enters their order number
3. Receives a **6-digit OTP code via SMS** to their registered phone
4. Enters the code to confirm they received the motorcycle
5. The motorcycle status changes to **Entregada**

**Customer confirmation URL:** https://www.voltika.mx/configurador_prueba/confirmar-entrega.html

---

## 8. Inventory Management

Accessible via the **"Ver inventario completo"** link on the dashboard, or directly at:

https://www.voltika.mx/configurador_prueba/inventario.html

### Features:

**Lista tab**
- View all motorcycles with filters (status, type, model, search)
- Click any motorcycle to see full details and status history
- Add new motorcycles manually
- Edit existing motorcycle information

**Ventas tab**
- Sales log with type, customer, amount, date
- Summary statistics by sale type

**Referidos tab**
- Manage referral contacts
- Each referral gets a unique code
- Track referral sales and commissions

---

## 9. Hold / Release

Any motorcycle (except already delivered ones) can be put **on hold**:

- Click **"Retener"** on the card → motorcycle is frozen, no further actions until released
- Click **"Liberar"** to resume the normal flow

This is useful when there's an issue that needs resolution before proceeding.

---

## 10. Automatic Integration with Customer Checkout

When a customer completes a purchase through the Voltika configurator:

1. Payment is confirmed via Stripe
2. The order is saved in the transactions database
3. **A new motorcycle record is automatically created** in the dealer panel with:
   - Status: *Por Llegar*
   - Customer name, email, phone (from checkout)
   - Model and color selected
   - Order number and payment status
4. The motorcycle card appears on the dealer dashboard immediately

No manual data entry is needed — the dealer simply starts tracking from step 1.

---

## 11. Weekly Payment Reminders (Credit Customers)

For customers who purchased via credit, the system sends **weekly payment reminder emails** every Monday at 8:00 AM. The email includes:
- Customer name
- Weekly payment amount
- Order details

---

## Navigation Summary

| Page | URL | Purpose |
|------|-----|---------|
| Dealer Panel | `/dealer-panel.html` | Main dashboard + tracking |
| Inventory | `/inventario.html` | Full inventory management |
| Customer Delivery | `/confirmar-entrega.html` | Customer OTP confirmation |

---

## Test Mode

To view specific credit evaluation screens, add URL parameters:

| Screen | URL |
|--------|-----|
| Credit conditional (enganche increase) | `?test_credito=condicional` |
| Credit denied | `?test_credito=no_viable` |
| Truora identity fail | `?test_credito=truora_fail` |

Example: `https://www.voltika.mx/configurador_prueba/?test_credito=condicional`
