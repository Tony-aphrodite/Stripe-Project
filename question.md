## Preguntas para el cliente — Asignar a punto + Skydropx

### 1. Skydropx API Key
- Tenemos email/password (dm@mtechmexico.mx), pero necesitamos el **API Key (token)**.
- Se obtiene en: https://app.skydropx.com → sección API → copiar token.
- Sin el API key no podemos hacer cotizaciones automáticas.

### 2. Dirección del CEDIS (origen de envío)
- Para calcular la fecha estimada de entrega con Skydropx, necesitamos la **dirección completa del CEDIS** (calle, ciudad, estado, código postal).
- Esta dirección se usará como origen en todas las cotizaciones.

### 3. Dimensiones y peso de la moto (para cotización)
- Skydropx requiere peso (kg), alto, ancho y largo (cm) del paquete.
- Podemos usar valores fijos para todas las motos. Ejemplo: 150kg, 200x80x120 cm.
- Confirmar las medidas aproximadas del embalaje.

### 4. Skydropx: solo cotización o también crear guía?
- Opción A: Solo consultar fecha estimada de entrega (cotización).
- Opción B: También generar la guía de envío (etiqueta + tracking) desde el panel.
- Por ahora implementamos solo cotización (Opción A). Confirmar si necesitan Opción B también.

### 5. Carrier preferido
- Skydropx devuelve múltiples opciones de paquetería (Estafeta, FedEx, DHL, etc.).
- Hay algún carrier preferido, o tomamos el más económico / más rápido automáticamente?
