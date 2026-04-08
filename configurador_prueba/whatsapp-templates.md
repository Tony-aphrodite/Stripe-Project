# Voltika — WhatsApp Message Templates (Meta Business Platform)

These are the 3 WhatsApp message templates that must be submitted to Meta
for approval before notifications can be sent to customers via WhatsApp.

## Submission instructions

1. Log in to **Meta Business Manager** → WhatsApp Manager
2. Go to **Account Tools → Message Templates**
3. Click **Create Template**
4. For each template below, use:
   - **Category:** `UTILITY` (transactional notifications, lower cost)
   - **Language:** `Spanish (MEX)` — `es_MX`
   - **Name:** as listed below (exact, lowercase, underscores)
5. Copy the **Body** text exactly. Variables `{{1}}`, `{{2}}`, ... are
   replaced at send time by our backend.
6. Add the **Sample values** so Meta's review can preview the result.
7. Submit. Approval typically takes a few minutes to a few hours.

Once all 3 are approved, send the approved template names back so we can
wire them into the WhatsApp provider integration.

---

## Template 1 — `voltika_punto_asignado`

**Trigger:** Punto Voltika has been assigned to the customer
**Category:** UTILITY
**Language:** es_MX
**Header:** None (text-only)

### Body

```
Hola {{1}} 👋

Tu Voltika ya tiene punto de entrega:

📍 {{2}}
👉 {{3}}

🧾 {{4}}
Pedido: {{5}}

Va en camino. Te avisamos cuando esté lista.
```

### Variables (sample values for Meta review)

| # | Meaning              | Sample value                                  |
|---|----------------------|-----------------------------------------------|
| 1 | Customer first name  | `Carlos`                                      |
| 2 | Punto name           | `Godike Motors`                               |
| 3 | Google Maps link     | `https://maps.google.com/?q=Av.+Ermita+...`  |
| 4 | Modelo – Color       | `Voltika One – Negro`                         |
| 5 | Order number         | `VLT-2026-0042`                               |

### Footer (optional)

```
Voltika México · Movilidad eléctrica
```

---

## Template 2 — `voltika_en_camino`

**Trigger:** Moto is shipping from CEDIS to the punto
**Category:** UTILITY
**Language:** es_MX
**Header:** None (text-only)

### Body

```
Hola {{1}} 👋

Tu Voltika ya va en camino 🚚

📍 {{2}}
📅 Llega aprox: {{3}}

🧾 {{4}}

Te avisamos cuando esté lista 🙌
```

### Variables

| # | Meaning              | Sample value             |
|---|----------------------|--------------------------|
| 1 | Customer first name  | `Carlos`                 |
| 2 | Punto name           | `Godike Motors`          |
| 3 | Estimated arrival    | `15/04/2026`             |
| 4 | Modelo – Color       | `Voltika One – Negro`    |

### Footer (optional)

```
Voltika México · Movilidad eléctrica
```

---

## Template 3 — `voltika_lista_entrega`

**Trigger:** Punto has finished assembly — ready for pickup
**Category:** UTILITY
**Language:** es_MX
**Header:** None (text-only)

### Body

```
Hola {{1}} 👋

Tu Voltika ya está lista 🎉

📍 {{2}}
🕒 {{3}}
👉 {{4}}

🧾 {{5}}

Para recoger:
✔ INE
✔ mismo número
✔ código de seguridad al momento

Te esperamos.
```

### Variables

| # | Meaning              | Sample value                                          |
|---|----------------------|-------------------------------------------------------|
| 1 | Customer first name  | `Carlos`                                              |
| 2 | Punto name           | `Godike Motors`                                       |
| 3 | Punto opening hours  | `Lun-Vie 10:00 a 18:30 · Sáb 11:00 a 14:00`           |
| 4 | Google Maps link     | `https://maps.google.com/?q=Av.+Ermita+...`           |
| 5 | Modelo – Color       | `Voltika One – Negro`                                 |

### Footer (optional)

```
Voltika México · Movilidad eléctrica
```

---

## After approval

Once Meta approves these 3 templates, send us:

1. The exact **template names** (should match `voltika_punto_asignado`,
   `voltika_en_camino`, `voltika_lista_entrega` if you used the names above)
2. Your **WhatsApp Business Account ID** (WABA ID)
3. Your **Phone Number ID** (from the Meta Business Manager)
4. The **API access token** (System User token, never expires)

We will then complete the integration in `configurador/php/whatsapp-api.php`
and the notifications will start firing automatically on each status change.

## Provider notes

- **Recommended provider:** Meta Cloud API (direct, lowest cost ~$0.01-0.04 per UTILITY message in Mexico)
- **Alternative:** Twilio WhatsApp API (easier SDK, slightly higher cost)
- **Alternative:** Gupshup, 360dialog, WATI (popular in LATAM, full BSP service)

The PHP integration will work with any of the above — just confirm which
provider you choose so we can use the correct API endpoints and auth headers.
