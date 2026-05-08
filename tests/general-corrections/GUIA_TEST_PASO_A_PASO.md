# 🧪 Guía de prueba paso a paso — General Corrections

Esta guía cubre TODOS los 16 bugs corregidos. Cada test indica:
1. **URL / pantalla** donde reproducir
2. **Acción** que debe hacer el tester
3. **Resultado esperado** (qué cuenta como PASS)

---

## 0. Setup (una sola vez)

1. Abre en tu navegador:
   ```
   https://voltika.mx/tests/general-corrections/seed-test-data.php
   ```
2. Verifica el "PREVIEW" — confirma que se van a crear: 1 cliente, 1 punto, 1 dealer y 7 motos.
3. Click en **▶ Ejecutar Run** para sembrar los datos.
4. Verifica que TODAS las líneas del log empiezan con ✓ (verde).

**Datos de acceso después del seed:**

| Acceso | URL | Credenciales |
|---|---|---|
| Cliente Portal (móvil) | `https://voltika.mx/clientes/` | Tel: `5500000099` |
| Punto Voltika (PoS) | `https://voltika.mx/configurador/dealer-panel.html` | `gc-punto@voltika.mx` / `GcTest1234` |
| Admin | `https://voltika.mx/admin/` | (tus credenciales admin) |

---

## 🟢 GRUPO A — Tests rápidos en Cliente Portal (móvil)

### TEST 1 — Bug 5.8: Botón "Confirmar recepción" eliminado

1. Abre `https://voltika.mx/clientes/` en tu **teléfono de prueba**.
2. Login con teléfono `5500000099`. Solicita el OTP — chequea el log del servidor para obtener el código (o usa el test_code que sale en la respuesta del backend).
3. Una vez dentro, ve a **Mi Voltika** o **Entrega** y selecciona la moto **GCTESTVIN0000001** (M03 negro).

**✅ PASS:**
- Aparece banner verde: **"¡Bienvenido a la familia Voltika! Tu moto fue recibida correctamente."**
- **NO** aparece el botón "Confirmar recepción".
- **NO** aparece el botón "Reportar incidencia".

**❌ FAIL:**
- Si ves cualquiera de los dos botones → reportar.

---

### TEST 2 — Bug 5.7 (CRITICAL): Firma con Cincel del ACTA

1. En el portal cliente (móvil), entra a la moto **GCTESTVIN0000002** (M05 gris, estado checklist_ok).
2. Debe aparecer una tarjeta con título **"Firma del ACTA DE ENTREGA"** y un botón **"Ver y firmar ACTA"**.
3. Click en **Ver y firmar ACTA** → verás:
   - Una pantalla con la moto (Modelo + Color + VIN).
   - Botón **"📄 Ver el contenido del acta"** (modal con texto legal).
   - Botón **"Iniciar firma con Cincel"**.

4. Click en **Iniciar firma con Cincel**.

**✅ PASS:**
- El sistema genera un PDF y muestra **un iframe** con la interfaz de Cincel embebida.
- El estado abajo dice "Esperando confirmación de Cincel…".
- Hay un botón "Abrir en pestaña nueva" como alternativa.

5. Completa el flujo de firma en Cincel (Cincel pedirá un OTP — usa los datos que envíe a tu email/teléfono de Cincel).

**✅ PASS final:**
- Al terminar la firma en Cincel, en máximo 4 segundos la pantalla del portal muestra **"¡ACTA firmada correctamente!"** y vuelve a la vista de entrega.
- El backend marca la moto como `cliente_acta_firmada=1`.
- Si abres el panel del PoS (en otra pestaña), verás que el botón "Finalizar entrega" del paso 5 se habilita.

**❌ FAIL:**
- Si ves un simple checkbox + input de nombre (la versión vieja) → bug.
- Si Cincel no responde / el iframe está vacío → revisar configuración Cincel en `configurador/php/config.php`.

---

## 🟡 GRUPO B — Tests en PoS (Punto Voltika) — Computadora o tablet

### TEST 3 — Bug 5.2: OTP solo por SMS, no por email

1. Login en **dealer-panel.html** con `gc-punto@voltika.mx` / `GcTest1234`.
2. Ve a **Entregar al cliente** en el menú.
3. Selecciona la moto **GCTESTVIN0000003** (Pesgo Plus rojo).
4. Click **Iniciar entrega** → modal del paso 1 muestra "Enviar código por SMS".
5. Click **Enviar código por SMS**.

**✅ PASS:**
- En el log del servidor (admin/cron logs o la respuesta del endpoint) verás:
  - `sms_sent: true` (o `whatsapp_sent: true`)
  - `email_skipped_reason: 'otp_phone_only'` (NUEVO — confirma que email NO se envió)
- El SMS llega al teléfono `5500000099` (o al test_code).

**❌ FAIL:**
- Si en los logs ves email_sent=true para el tipo otp_entrega → bug.

---

### TEST 4 — Bug 5.3 + 5.4: INE con cámara/archivo y reverso

1. Continuando del Test 3: pasa al **Paso 2** introduciendo el OTP.
2. Avanza al **Paso 3** (Foto del cliente e INE).

**✅ PASS:**
- Verás **3 secciones** (no 2 como antes):
  - 📸 Foto rostro del cliente
  - 🪪 INE — Frente
  - 🪪 INE — Reverso  ← **NUEVO (Bug 5.4)**
- Cada una tiene **2 botones** lado a lado: **📷 Tomar foto** y **📁 Elegir archivo**  ← **(Bug 5.3)**
- Click en "📷 Tomar foto" para INE → debe abrir la **cámara**, NO el explorador de archivos.
- Click en "📁 Elegir archivo" para INE → debe abrir el explorador de archivos.

**❌ FAIL:**
- Si solo hay 2 slots (rostro + INE) o si falta el botón cámara → bug.

---

### TEST 5 — Bug 5.6: Full delivery checklist (5 fases)

1. Continuando: completa el Paso 3 (rostro + INE frente + INE reverso) → llega al **Paso 4**.

**✅ PASS:**
- En lugar de ver 4 checkboxes simples + 3 fotos, ahora verás:
  - **Tabs**: F1 — Identidad / F2 — Pago / F3 — Unidad / F4 — OTP ✓ (verde, info-only) / F5 — Acta ⏳ (amarillo, info-only)
  - F1 tiene 7 checkboxes (INE presentada, nombre coincide, etc.)
  - F2 tiene 4 checkboxes (pago confirmado, enganche, etc.)
  - F3 tiene 5 checkboxes + 3 fotos (frente/lateral/trasera)
  - Botón al final: **"Guardar checklist y enviar al cliente"**
- Al guardar, los 3 fases se persisten en `checklist_entrega_v2` (verifica con la query):
  ```sql
  SELECT fase1_completada, fase2_completada, fase3_completada
  FROM checklist_entrega_v2
  WHERE moto_id = (SELECT id FROM inventario_motos WHERE vin='GCTESTVIN0000003');
  ```
  Debe devolver 1, 1, 1.

**❌ FAIL:**
- Si solo aparece el checklist viejo (4 checkboxes) → bug.

---

### TEST 6 — Bug 5.1: Auto-save + Botón "No exitosa"

#### 6a. Auto-save
1. Continuando: en cualquier paso del wizard, abre la consola del navegador (F12 → Network).
2. Avanza un paso (por ejemplo de 1 a 2).

**✅ PASS:**
- Verás una llamada **POST a `entrega/guardar-paso.php`** con `step` y `step_data`.
- La respuesta es `{"ok":true,"step":"step1","entrega_id":N}`.

#### 6b. Botón "Entrega NO exitosa"
1. En CUALQUIER paso (1-5), busca el botón con borde rojo punteado: **"✗ Entrega NO exitosa"** al final del modal.
2. Click → modal pide motivo (mínimo 5 caracteres).
3. Escribe "Cliente no se presentó al punto" → Confirmar cierre.

**✅ PASS:**
- Aparece toast "Entrega cerrada como NO exitosa".
- En la DB: `SELECT estado, cancelado_motivo FROM entregas WHERE moto_id = ...` devuelve `no_exitosa` y el motivo.

#### 6c. Cron de 6 horas
- Ejecuta manualmente (vía SSH):
  ```bash
  cd /var/www/voltika
  php puntosvoltika/php/cron/expirar-entregas.php --dry-run
  ```
**✅ PASS:**
- Output: `[expirar-entregas] N sesión(es) caducadas (dry-run)` — sin escribir nada.
- Si hay sesiones >6h se listan; si no, dice 0.
- Puedes correr sin `--dry-run` para que las cierre realmente.

---

### TEST 7 — Bug 4.1: Assembly Checklist con fotos (sync admin↔PoS)

1. En **dealer-panel.html**, ve a **Inventario** y busca **GCTESTVIN0000004** (Ukko-S verde, recibida).
2. Click en el icono de checklist → abre **Checklist de Ensamble**.

**✅ PASS:**
- En cada sección (Recepción, 2.1 Desembalaje, 2.2 Base, 2.3 Manubrio, 2.4 Llanta, 2.5 Espejos, 3.1 Frenos, etc.) hay:
  - Lista de checkboxes (igual que antes)
  - **Línea separadora** + texto "📷 Fotos de esta sección"
  - Botón **"+ Agregar foto"**

3. Click "+ Agregar foto" en la sección "2.2 Base y asiento" → captura una foto (cámara o archivo).
4. Verifica que aparece un thumbnail con botón ×.
5. Cierra el modal, abre el **panel admin** en otra pestaña y abre el mismo checklist.

**✅ PASS:**
- La foto aparece TAMBIÉN en el admin (sincronización vía `checklist_ensamble.fotos_base` en la misma DB).

**❌ FAIL:**
- Si en PoS no hay opción de subir fotos → bug.
- Si el admin no muestra la foto subida desde PoS → bug.

---

### TEST 8 — Bug 3.1, 3.2, 3.3, 3.4: Recepción

1. En **dealer-panel.html**, ve a **Recepción**.

**✅ PASS Bug 3.1 (estado visible):**
- Para **GCTESTVIN0000005** (M05 azul) verás un badge amarillo: **"En tránsito"** (no "enviada" raw).
- Para **GCTESTVIN0000006** (M03 plata) verás un badge gris: **"Pendiente de asignación"** ← Bug 3.4.

**✅ PASS Bug 3.2 (info adicional):**
- Para GCTESTVIN0000005 la tarjeta muestra:
  - Tracking: `GCTRK-5-MOTO`
  - Paquetería: `Estafeta`
  - Enviada: fecha de hace 2 días
  - ETA: fecha de mañana
  - Badge verde: **"Origen certificado ✓"** (porque sembré checklist_origen completado)

**✅ PASS Bug 3.4 (PENDIENTE DE ASIGNACIÓN):**
- GCTESTVIN0000006 aparece con un botón deshabilitado: **"Esperando envío de CEDIS"**.

2. Click "Recibir moto" en GCTESTVIN0000005 → abre el modal de recepción.

**✅ PASS Bug 3.3 (checklist detallado):**
- Verás campos NUEVOS:
  - VIN escrito en la caja
  - Número de sello aplicado
  - Verificaciones: "Sello aplicado y SIN violar" + 4 checkboxes de antes
  - **3 fotos** (sello / etiqueta VIN / unidad), cada una con botones cámara + archivo
  - Observaciones (textarea)
  - Fecha de recepción (date picker)
  - Recibido por (text)
- Llena todo y click "Confirmar recepción".

**✅ PASS final:**
- En la DB:
  ```sql
  SELECT vin_caja, sello_numero, sello_intacto, foto_sello_url, observaciones
  FROM recepcion_punto WHERE moto_id = (SELECT id FROM inventario_motos WHERE vin='GCTESTVIN0000005');
  ```
  Devuelve los nuevos campos llenos.

---

## 🔴 GRUPO C — Tests en Admin

### TEST 9 — Bug 1.1: Engine number validation

1. Abre **admin** → Inventario → busca **GCTESTVIN0000007** (MC10 naranja).
2. Abre el **Checklist de Origen**.
3. En "Identificación de unidad" → campo **Número de motor**.
4. Escribe un número INCORRECTO: `ENGINE-WRONG-99999`.
5. Marca todos los items, sube las fotos, intenta **Completar checklist**.

**✅ PASS:**
- El sistema rechaza con error: **"El número de motor capturado no coincide con el de la unidad oficial. Verifica que estés trabajando con la moto correcta."**
- Te muestra un hint: `GCM***007` (primeros 3 + últimos 3 caracteres).

6. Cambia el número a `GCMOTOR0000007` (el correcto, sin importar mayúsculas/espacios). Intenta de nuevo.

**✅ PASS:**
- Acepta y completa el checklist.

**Bonus:** prueba con `gc motor 0000007` (con espacios y minúsculas) → DEBE aceptar.

---

### TEST 10 — Bug 1.2: PDF firma + tiempos + autosave 30s

#### 10a. Autosave
1. En el **Checklist de Origen** abierto, abre la consola del navegador → Network.
2. Espera **30 segundos** sin hacer nada.

**✅ PASS:**
- Aparece automáticamente un POST a `checklists/guardar-origen.php` con `__silent: 1`.
- No aparece ningún alert al usuario.

#### 10b. PDF con firma + tiempos
1. Completa y cierra el checklist (con el num_motor correcto).
2. Click en **Ver PDF / Imprimir**.
3. En el PDF generado:

**✅ PASS:**
- En la sección de meta (arriba) ahora aparecen 3 nuevas líneas:
  - **Realizado por:** [GC-TEST] Operador Punto (o el dealer real)
  - **Inicio:** fecha/hora cuando se creó el draft
  - **Envío:** fecha/hora cuando se completó

**❌ FAIL:**
- Si el PDF no muestra las 3 nuevas líneas → bug.

---

### TEST 11 — Bug 2.1: Fecha llegada ≥ fecha envío

1. Ve a **admin → Envíos**.
2. Busca el envío de **GCTESTVIN0000007** (estado `lista_para_enviar`).
3. Click en el botón "Enviar" o "Marcar como enviada" → abre el modal "Marcar como enviada".

**✅ PASS:**
- Modal muestra:
  - **Fecha de envío:** today
  - **ETA:** today + 7 días
- Cambia "Fecha de envío" a `2026-05-20` → el campo ETA actualiza su atributo `min` a `2026-05-20` automáticamente (UI live).
- Intenta poner ETA = `2026-05-15` (anterior a fenv).

**✅ PASS:**
- El navegador no permite seleccionar una fecha anterior (atributo `min`).
- Si forzaras enviar igual (vía DevTools), el server devuelve error: **"La fecha estimada de llegada no puede ser anterior a la fecha de envío."**

---

### TEST 12 — Bug 2.2: Tracking + Carrier en "Marcar como enviada"

1. Mismo modal del Test 11.

**✅ PASS:**
- Debajo de las fechas hay 2 NUEVOS campos:
  - **Número de tracking** (opcional): caja de texto
  - **Paquetería / Carrier** (opcional): caja de texto
2. Llena tracking = `GCTRK-NEW-7` y carrier = `DHL`.
3. Click "Confirmar envío".

**✅ PASS:**
- En la DB:
  ```sql
  SELECT tracking_number, carrier, fecha_envio, fecha_estimada_llegada, estado
  FROM envios WHERE moto_id = (SELECT id FROM inventario_motos WHERE vin='GCTESTVIN0000007');
  ```
  Devuelve los nuevos valores Y `estado='enviada'`.

---

## 📋 Resumen de PASS/FAIL

Después de cada test, marca el resultado:

| Test | Bug | Resultado |
|---|---|---|
| 1 | 5.8 — Botón removido | ⬜ PASS / ⬜ FAIL |
| 2 | **5.7 — Cincel ACTA** | ⬜ PASS / ⬜ FAIL |
| 3 | 5.2 — OTP por SMS | ⬜ PASS / ⬜ FAIL |
| 4 | 5.3 + 5.4 — INE | ⬜ PASS / ⬜ FAIL |
| 5 | 5.6 — Full checklist | ⬜ PASS / ⬜ FAIL |
| 6a | 5.1 — Auto-save | ⬜ PASS / ⬜ FAIL |
| 6b | 5.1 — No exitosa | ⬜ PASS / ⬜ FAIL |
| 6c | 5.1 — Cron 6h | ⬜ PASS / ⬜ FAIL |
| 7 | 4.1 — Ensamble fotos | ⬜ PASS / ⬜ FAIL |
| 8 | 3.1+3.2+3.3+3.4 — Recepción | ⬜ PASS / ⬜ FAIL |
| 9 | 1.1 — Engine valid | ⬜ PASS / ⬜ FAIL |
| 10 | 1.2 — PDF + autosave | ⬜ PASS / ⬜ FAIL |
| 11 | 2.1 — Fecha valid | ⬜ PASS / ⬜ FAIL |
| 12 | 2.2 — Tracking | ⬜ PASS / ⬜ FAIL |

---

## 🧹 Limpieza después de pruebas

Para eliminar TODOS los datos de prueba:
```
https://voltika.mx/tests/general-corrections/seed-test-data.php?reset=1&run=1
```

O elimina los archivos del seed:
- `tests/general-corrections/seed-test-data.php`
- `tests/general-corrections/*` (toda la carpeta si quieres)

---

## ⚠️ Si algo falla

Reporta:
1. **Número del test** (ej. "Test 5 falló").
2. **Captura de pantalla** del problema.
3. **Console del navegador** (F12 → Console + Network).
4. **Error del servidor** si aplica (chequear logs PHP).

Lo arreglamos en el momento sin romper los demás flujos.
