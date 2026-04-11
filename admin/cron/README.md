# Voltika — Cron setup (Plan G: Stripe reconciliation)

Este directorio contiene el runner y la configuración del cron que reconcilia
Stripe PaymentIntents contra la tabla `transacciones` cada 15 minutos.

## ¿Qué hace?

`verificar-stripe-cron.sh` llama al endpoint
`admin/php/ventas/verificar-stripe.php`, que:

1. Lista los `payment_intents` de Stripe con `status=succeeded` de las últimas N horas.
2. Para cada PI busca una fila coincidente en `transacciones.stripe_pi`.
3. Si no hay fila → escribe una entrada en `transacciones_errores` para que el
   admin la vea y pueda recuperarla con el botón **Recuperar** del dashboard
   de ventas (`admin/js/modules/admin-ventas.js` → `showRecuperar`).

## 1) Configurar variables de entorno

Añade a tu `.env` (o a `/etc/environment` del servidor):

```env
VOLTIKA_BASE_URL=https://voltika.mx
VOLTIKA_CRON_TOKEN=<generar con: openssl rand -hex 32>
VOLTIKA_CRON_HORAS=2
```

> El token debe coincidir con el que lee
> `admin/php/ventas/verificar-stripe.php` (via `getenv('VOLTIKA_CRON_TOKEN')`).

## 2) Permisos

```bash
chmod +x admin/cron/verificar-stripe-cron.sh
```

## 3) Registrar en crontab (Linux)

```bash
crontab -e
```

Añade la línea:

```
*/15 * * * * VOLTIKA_BASE_URL=https://voltika.mx VOLTIKA_CRON_TOKEN=xxxxx /ruta/al/proyecto/admin/cron/verificar-stripe-cron.sh
```

O, si prefieres cargar las variables desde `/etc/environment`:

```
*/15 * * * * . /etc/environment; /ruta/al/proyecto/admin/cron/verificar-stripe-cron.sh
```

## 3b) Alternativa: cron-job.org (hosting compartido sin crontab)

Si el hosting no permite crontab, usa un servicio externo tipo
[cron-job.org](https://cron-job.org) apuntando directo al endpoint:

- **URL**: `https://voltika.mx/admin/php/ventas/verificar-stripe.php?horas=2&token=<VOLTIKA_CRON_TOKEN>`
- **Método**: GET
- **Frecuencia**: cada 15 minutos
- **Timeout**: 60 s

> **Importante**: no uses el token por query string si hay logs públicos.
> Prefiere el header `X-Cron-Token` vía un cron con `curl` real.

## 4) Verificación manual

Prueba que funciona:

```bash
curl -H "X-Cron-Token: $VOLTIKA_CRON_TOKEN" \
     "$VOLTIKA_BASE_URL/admin/php/ventas/verificar-stripe.php?horas=24"
```

Respuesta esperada:

```json
{
  "ok": true,
  "horas": 24,
  "scanned": 15,
  "succeeded": 15,
  "matched": 14,
  "orphans": 1,
  "detalle": [{"stripe_pi": "pi_xxx", "monto": 12065, "created": "..."}],
  "checked_at": "2026-04-12T..."
}
```

## 5) Logs

El runner escribe en:

- `$VOLTIKA_CRON_LOG_DIR/verificar-stripe.log` (default: `/var/log/voltika/verificar-stripe.log`)
- Fallback: `/tmp/verificar-stripe.log`

Rotación recomendada: añade a `/etc/logrotate.d/voltika`:

```
/var/log/voltika/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
```

## 6) Windows (desarrollo local)

Para probar en Windows, usa Git Bash o WSL:

```bash
export VOLTIKA_BASE_URL=http://localhost
export VOLTIKA_CRON_TOKEN=test-token-dev
bash admin/cron/verificar-stripe-cron.sh
```

O registra como tarea en el Programador de Tareas con
`powershell.exe -Command "bash admin/cron/verificar-stripe-cron.sh"`.

## Troubleshooting

| Síntoma | Causa | Solución |
|---|---|---|
| `401 No autorizado` | Token incorrecto o no seteado en .env del servidor | Verifica `echo $VOLTIKA_CRON_TOKEN` en el shell del cron y `getenv('VOLTIKA_CRON_TOKEN')` en PHP |
| `502 Stripe API` | Red / API key inválida | Revisa `STRIPE_SECRET_KEY` en `configurador_prueba/.env` |
| `orphans=0` siempre | El endpoint sí funciona pero no hay lagunas (esperado en estado sano) | OK |
| `orphans > 0` repetido | Hay un bug persistente en `confirmar-orden.php` | Abrir cada fila en el dashboard con **Recuperar** y revisar root cause |
