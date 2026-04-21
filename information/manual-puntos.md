# Manual de Uso — Puntos Voltika

**Versión:** Abril 2026
**Destinatarios:** Administradores Voltika + Operadores de Puntos

---

## Contenido

1. [Panel del Operador de Punto](#parte-1--panel-del-operador-de-punto) (Puntos Voltika)
2. [Crear un Nuevo Punto desde el Admin](#parte-2--crear-un-nuevo-punto-desde-el-admin)
3. [Administración Diaria del Punto](#parte-3--administración-diaria-del-punto)
4. [Preguntas Frecuentes](#parte-4--preguntas-frecuentes)

---

# Parte 1 — Panel del Operador de Punto

URL del panel: `https://voltika.mx/puntosvoltika`

## 1.1 Ingreso (Login)

1. Abre la URL desde cualquier navegador (celular o computadora).
2. Ingresa tu **correo electrónico** y **contraseña** (se te envían por WhatsApp/email al crear tu cuenta).
3. Si es tu primer ingreso: **cambia tu contraseña** de inmediato en el menú superior.

**Problemas comunes:**
- "Usuario o contraseña incorrectos" → solicita restablecimiento al administrador Voltika.
- "Usuario bloqueado" → contacta al administrador; tu cuenta puede estar desactivada.

---

## 1.2 Pantalla Principal — Inicio

Al ingresar verás:

- **Total de motos** asignadas a tu punto
- **Pendientes de entrega** (reservadas para clientes web)
- **Disponibles para venta** (inventario libre para walk-ins)
- **Pendientes de envío** (en camino desde CEDIS)
- **Códigos de referido** de tu punto (para venta directa y web)
- Botones de acción rápida: Recibir moto / Entregar al cliente / Venta por referido

---

## 1.3 Inventario

El menú **Inventario** muestra 4 pestañas:

### Pendientes de asignación
Órdenes web que aún no tienen una moto física asignada por CEDIS. Aquí no haces nada — es solo informativo.

### En tránsito
Motos que salieron del CEDIS y están llegando a tu punto.
- Cuando llega el camión, **revisa los VINs** en esta pestaña.
- Cada moto tiene un botón **"Recibir"** — al presionarlo pasa al siguiente estado.

### Para entrega
Motos reservadas para clientes web que ya pagaron. Cada una tiene:
- Nombre del cliente
- Modelo y color
- VIN
- Botón **"Iniciar entrega"** para abrir el flujo de entrega al cliente.

### Disponibles para venta
Inventario libre que puedes vender directamente en el punto (walk-ins) o asignar a órdenes referidas.

---

## 1.4 Recepción de motos (desde CEDIS)

Cuando llega el camión:

1. Entra al menú **Recepción**.
2. Verás la lista de envíos pendientes con su ETA.
3. Presiona **"Recibir"** en el envío correspondiente.
4. **Escanea o ingresa el VIN** de cada moto.
5. Completa la **checklist física** (4 ítems):
   - ✅ Condición general OK
   - ✅ Sin daños en carrocería
   - ✅ Componentes completos
   - ✅ Batería OK
6. Agrega **notas** si hay algo inusual (rayones, componentes faltantes, etc.).
7. Presiona **"Confirmar recepción"**.

La moto pasa automáticamente a inventario "Disponible" o "Para entrega" según corresponda.

**Importante:** Si el VIN no coincide con lo enviado, reporta inmediatamente al admin por WhatsApp.

---

## 1.5 Entrega a cliente (flujo de 5 pasos)

Esta es la parte más importante — **cualquier error aquí te hace responsable del valor total de la moto**. Sigue los 5 pasos en orden:

### Paso 1 — Enviar OTP al cliente
- Abre la pestaña **Para entrega** → selecciona la moto → **"Iniciar entrega"**
- El sistema envía automáticamente un código de 6 dígitos al celular registrado del cliente.

### Paso 2 — Verificar OTP
- Pide al cliente el código que recibió por SMS.
- Ingrésalo en el panel.
- Si falla 3 veces, el cliente debe solicitar uno nuevo.

### Paso 3 — Captura de foto del cliente + INE
- Toma una foto **clara del rostro del cliente** (con la cámara del celular).
- Toma una foto **clara de la INE/pasaporte** del cliente.
- El sistema compara automáticamente el rostro con la INE vía Truora.

### Paso 4 — Escaneo del VIN
- Usa el escáner integrado (cámara del celular).
- Apunta a la **etiqueta del VIN en el chasis** de la moto.
- Debe coincidir con el VIN asignado al cliente.

### Paso 5 — Fotos de entrega
- Foto del cliente recibiendo la moto.
- Foto del cliente firmando el acta.
- **Confirmar entrega** → la moto pasa a estado "Entregada".

El cliente recibirá inmediatamente un WhatsApp de confirmación con su acta de entrega en PDF.

---

## 1.6 Venta por referido

Dos modos:

### Orden online (cliente usó tu código)
1. Pestaña **Venta por referido** → **Órdenes pendientes**.
2. Seleccionas el pedido.
3. De tu inventario disponible, **asignas una moto** que coincida con el modelo/color solicitado.
4. La moto pasa a "Para entrega" — luego sigues el flujo de entrega normal (5 pasos).

### Venta directa (walk-in)
1. Pestaña **Venta por referido** → **Venta directa**.
2. Seleccionas la moto del inventario disponible.
3. Ingresas datos del cliente (nombre, email, teléfono, INE).
4. Registras el canal de pago.
5. El sistema genera el registro de venta + aplica comisión a tu punto.

---

# Parte 2 — Crear un Nuevo Punto desde el Admin

URL del admin: `https://voltika.mx/admin`
**Acceso:** solo administradores Voltika (rol `admin`).

## 2.1 Opción A — Creación manual (1 punto)

1. Ingresa al admin → menú lateral → **Puntos**.
2. Presiona **"+ Nuevo Punto"** (arriba a la derecha).
3. Completa el formulario:

### Información básica
| Campo | Descripción | Ejemplo |
|---|---|---|
| Nombre del punto | Razón comercial visible | Garage Mushu |
| Nombre del responsable | Persona a cargo | Daniel Hernández |
| Tipo de punto | Voltika Center / Distribuidor Certificado / Punto de Entrega | Distribuidor Certificado |
| Ubicación | Ciudad corta (para filtro) | Ecatepec |

### Dirección
| Campo | Ejemplo |
|---|---|
| Dirección completa | Av Ignacio Allende 114-58A, Fracc. Las Americas 55076 |
| Calle y número | Av Ignacio Allende 114-58A |
| Código Postal | 55076 |
| Colonia | Fraccionamiento Las Américas |
| Ciudad | Ecatepec de Morelos |
| Estado | Estado de México |

### Contacto
| Campo | Ejemplo |
|---|---|
| Email | garagemushu.admon@gmail.com |
| Teléfono / WhatsApp | +52 55 9104 8733 |

### Operación
| Campo | Ejemplo / Nota |
|---|---|
| Horario | Lunes-Viernes 9:30-18:00, Sábado 9:30-14:00 |
| Capacidad | Número máximo de motos en el punto |
| Orden de aparición | 1, 2, 3... (para mostrar en el configurador) |
| Comisión de entrega | Monto MXN por entrega |

### Servicios ofrecidos (activar SI / NO)
- Configurador (permite venta web)
- Entrega al cliente
- Exhibición y venta
- Servicio técnico
- Pruebas de manejo
- Refacciones

### Mapa
- Latitud y Longitud (para Google Maps)
- Obtén las coordenadas en Google Maps: clic derecho → copiar las coordenadas.

4. Presiona **"Guardar"**.
5. El punto aparece inmediatamente en la lista y está disponible en el configurador público.

---

## 2.2 Opción B — Importación masiva (múltiples puntos)

1. Admin → **Puntos** → botón **"Importar"**.
2. Descarga la plantilla Excel (botón **"Descargar plantilla"**).
3. Llena la plantilla — 32 columnas, una fila por punto.
4. Sube el archivo Excel/CSV.
5. Resultado: número de puntos creados / actualizados.

**Columnas obligatorias:** `Nombre del punto`, `Dirección`, `Ciudad`, `Estado`, `Email`, `Telefono/Whatsapp`, `Tipo de punto`.

**Comportamiento según columna "Acción":**
- `agregar` → crea nuevo punto
- `actualizar` → modifica punto existente (busca por Nombre + Código Postal)
- `eliminar` → desactiva el punto

---

## 2.3 Después de crear el punto — Generar credenciales del operador

Sin operador, el punto no puede iniciar sesión. Pasos:

1. En la lista de puntos, clic en el punto recién creado → **Ver detalle**.
2. Pestaña **Usuarios**.
3. Botón **"+ Nuevo usuario"**.
4. Completa:
   - Nombre del operador
   - Email (será el usuario de login)
   - Contraseña (usa el botón **"Generar"** para una aleatoria segura)
   - Rol: `dealer`
   - Punto: (se pre-selecciona)
   - ✅ **"Enviar credenciales por SMS/Email"** (marcar)
5. Presiona **"Crear usuario"**.

Se abre automáticamente un modal con el **mensaje listo para compartir**. Opciones:
- **Copiar** — para pegarlo en cualquier parte
- **Enviar por WhatsApp** — abre WhatsApp web/app con el texto pre-cargado

---

## 2.4 Configurar código de referido

Para que el punto reciba comisiones por ventas web:

1. Detalle del punto → pestaña **Códigos**.
2. Código para **Venta en Piso (PV)** — se usa en ventas walk-in.
3. Código para **Venta Web (PE)** — los clientes lo ingresan en el configurador para asignar la venta a tu punto.
4. Presiona **"Generar"** para crear aleatorios o edítalos manualmente.

Comparte estos códigos con el operador — aparecerán en su pantalla de Inicio.

---

## 2.5 Configurar comisiones por modelo

1. Detalle del punto → pestaña **Comisiones**.
2. Para cada modelo (M03, M05, MC10, etc.):
   - Comisión de venta (MXN fijo)
   - Comisión de entrega (% del precio)
3. Guardar.

Las comisiones se calculan automáticamente con cada venta.

---

# Parte 3 — Administración Diaria del Punto

## 3.1 Ver desempeño del punto
Admin → **Rendimiento** (o **Puntos** → detalle → pestaña **Estadísticas**):
- Motos vendidas este mes
- Motos entregadas
- Comisiones pendientes de pago
- Tiempo promedio de ensamble

## 3.2 Bloquear un usuario operador
Detalle del punto → **Usuarios** → clic en el usuario → **"Bloquear"**. El usuario no podrá ingresar al panel hasta que se desbloquee.

## 3.3 Restablecer contraseña de un operador
Detalle del punto → **Usuarios** → **"Restablecer contraseña"** → genera nueva o define manualmente → **"Enviar por WhatsApp/Email"**.

## 3.4 Desactivar un punto temporalmente
Edita el punto → desmarca **"Activo"** → Guardar. El punto desaparece del configurador público pero los datos se conservan.

---

# Parte 4 — Preguntas Frecuentes

**¿Qué pasa si el OTP no llega al cliente durante la entrega?**
- Verifica que el número esté correcto (código de país +52).
- Solicita reenvío desde el panel.
- Si persiste, contacta al admin — puede enviar el código manualmente.

**¿Puedo vender una moto sin completar el flujo de los 5 pasos?**
- **No.** Saltar cualquier paso (especialmente OTP + foto + INE) te hace responsable del valor total de la moto.

**¿Cómo reporto un daño o VIN faltante?**
- Al recibir: usa el campo "Notas" + marca los ítems de checklist que fallen.
- Urgente: WhatsApp al admin con foto.

**¿Puedo crear más de un operador por punto?**
- Sí. Un punto puede tener 2-5 operadores. Cada uno con su propio login.

**¿Cuándo se paga la comisión?**
- Mensualmente. El admin genera la liquidación en el panel de **Cobranza**.

---

## Contacto de soporte

- **Administrador Voltika:** soporte@voltika.mx
- **WhatsApp soporte:** +52 55 1341 6370
- **Horario:** Lunes a Viernes 9:00 - 18:00 hrs

---
*Documento generado Abril 2026. Última actualización: con el lanzamiento del panel v2.*
