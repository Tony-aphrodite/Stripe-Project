# Voltika — 3 Paneles: Análisis del documento voltika_functionsst3.pdf

## Resumen

El cliente solicita **3 paneles de gestión**, cada uno con URL independiente, conectados al sistema existente (configurador, Stripe, base de datos).

---

## 1. CEDIS (Centro de Distribución) — `voltika.mx/admin`

Panel de administración central (usuario maestro). Se integra al dashboard existente.

### Funciones:
- **Inventario**: crear/importar motos nuevas, llenar checklist de origen, asignar motos a Puntos Voltika
- **Pagos**: verificar órdenes y estado de pago (MSI, contado, enganche de crédito)
- **Envío**: cambiar estado de envío ("Lista para enviar" → "Enviada")
- **Visibilidad total**: CEDIS ve todo el inventario (propio + de cada punto)

---

## 2. PUNTO CONTROL (Panel de Puntos Voltika) — `voltika.mx/puntosvoltika`

Panel para cada punto de venta/entrega.

### Funciones:
- **Inventario dual**: inventario para entrega vs inventario listo para venta
- **Códigos de referido**: uno para venta directa en tienda, otro para ventas electrónicas
- **Asignación de motos**: asignar motos del inventario del punto a ventas por referido
- **Recepción de motos**: escanear QR/VIN + checklist de recepción + fotos → moto aparece en inventario del punto
- **Proceso de entrega al cliente**:
  1. Enviar OTP al teléfono del cliente y verificar
  2. Verificar rostro del cliente (face verification)
  3. Llenar checklist de entrega
  4. Tomar fotos: cliente, identificación, moto
  5. Verificar firma del ACTA DE ENTREGA

### Vista de motos:
- Modelo, color, VIN

### Vista de compras:
- Nombre, modelo, color, teléfono, email
- Botón para abrir modal con toda la información de la moto y la compra

---

## 3. CLIENT DELIVERY PANEL (Panel del Cliente) — `voltika.mx/clientes`

Panel para el cliente final durante la entrega de su moto. Se integra al portal de clientes ya desarrollado.

### Funciones:
- Solicitar OTP para validar entrega
- Revisar checklist de entrega
- Firmar ACTA DE ENTREGA
- Confirmar recepción de la moto

---

## Reglas de negocio importantes

| # | Regla | Detalle |
|---|-------|---------|
| 1 | Inventario ↔ Configurador | Si inventario CEDIS = 0 para un modelo, el configurador muestra fecha de entrega +2 meses |
| 2 | Visibilidad de inventario | CEDIS ve todo (maestro). Punto solo ve su inventario |
| 3 | Coincidencia exacta | Moto asignada a orden debe coincidir en modelo Y color |
| 4 | Checklist de origen obligatorio | No se puede asignar moto a punto si el checklist de origen no está completo |
| 5 | Notificaciones (email + WhatsApp) | Se envían cuando: punto asignado a compra, moto enviada al punto (con fecha), moto armada y lista para recoger |
| 6 | Verificación de pago | MSI/contado: verificar pago completo. Crédito: verificar pago del enganche |
| 7 | Modal de información | Cada moto/compra tiene botón que abre modal con toda la información |
| 8 | Punto como pending | Si el comprador elige buscar punto cercano en el configurador, la compra queda "pendiente de asignar" |
| 9 | Crear puntos desde panel | Desde admin se pueden crear Puntos Voltika que se muestran en el configurador |

---

## Estados de envío (CEDIS → Punto)

1. **Lista para enviar**
2. **Enviada**
3. *(Punto recibe, hace checklist + QR scan → aparece en inventario del punto)*

---

## URLs de despliegue

| Panel | URL | Estado |
|-------|-----|--------|
| Cliente | `voltika.mx/clientes` | ✅ Portal implementado (falta integrar funciones de entrega) |
| Puntos Voltika | `voltika.mx/puntosvoltika` | ❌ Por implementar |
| Admin / CEDIS | `voltika.mx/admin` | ❌ Por implementar |
