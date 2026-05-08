# Test Suite — General_Corrections_EN.docx (2026-05-08)

Pruebas automatizadas para los 16 bugs y la pregunta del documento de simulación de entrega.

## Estructura

| Archivo | Cubre | Tipo |
|---------|-------|------|
| `01-syntax-check.sh` | Todos | Linter PHP/JS de los archivos tocados |
| `02-static-analysis.sh` | Todos | Búsqueda estática de claims (texto/etiquetas/lógica) |
| `03-bug-1-1-engine.php` | Bug 1.1 | Validación num_motor (mock PDO) |
| `04-bug-2-1-fechas.php` | Bug 2.1 | Validación ETA ≥ fecha_envio |
| `05-bug-5-2-otp.php` | Bug 5.2 | Email skipped para otp_entrega |
| `06-bug-3-recepcion.sh` | Bug 3.1-3.4 | UI fields + JOIN checklist_origen |
| `07-bug-4-1-photos.sh` | Bug 4.1 | Photo zone + upload endpoint |
| `08-bug-5-7-cincel.sh` | Bug 5.7 | Endpoints + flujo iframe |
| `09-bug-5-1-autosave.sh` | Bug 5.1 | Auto-save + 6h cron + no-exitosa |
| `10-bug-1-2-pdf.sh` | Bug 1.2 | Migrations + PDF fields |
| `11-bug-5-6-checklist.sh` | Bug 5.6 | 5-phase checklist en PoS |
| `12-bug-5-8-button.sh` | Bug 5.8 | Botón removed |
| `13-bug-5-3-5-4-ine.sh` | Bug 5.3+5.4 | Camera/file + reverso |
| `14-bug-2-2-tracking.sh` | Bug 2.2 | Tracking en marcar enviada |
| `run-all.sh` | Todos | Master runner |

## Ejecución

```bash
cd /home/ph/Client/Stripe-Project/tests/general-corrections
chmod +x *.sh
./run-all.sh
```

Salida: cada test imprime `[PASS]` o `[FAIL] motivo` y devuelve exit code != 0 si algo falla.

## No requiere

- Servidor web corriendo (todas las pruebas son estáticas o en CLI).
- Base de datos real — los tests con PDO usan el driver `sqlite::memory:`.
- Conexión a Cincel / SMSmasivos — los tests del flujo Cincel verifican estructura, no la API real.

## Cobertura

Cada test verifica:
1. Que los símbolos / strings / endpoints prometidos por el documento existen.
2. Que la lógica añadida no cambia el comportamiento de otros flujos (regresión negativa: las llamadas legacy aún devuelven 200).
3. Que las migraciones idempotentes no fallan en una segunda ejecución.
