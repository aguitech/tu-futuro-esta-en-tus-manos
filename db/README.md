# DB — "Tu futuro está en tus manos"

Base de datos SQLite del portal. **La fuente de verdad es `schema.sql`** —
el `.sqlite` siempre se deriva de ahí, nunca se edita a mano.

## Quickstart

```bash
cd db
python3 init_db.py --reset    # crea portal.sqlite desde schema.sql
python3 seed_demo.py          # 8 participantes de demostración
python3 queries.py            # todos los reportes
python3 queries.py --participante 0001   # drill-down de uno
python3 api_smoke.py          # prueba del API contra la DB real
```

Sin dependencias: solo `sqlite3` de la stdlib de Python.

## Modelo

El flujo es un viaje de **4 etapas**, así que se usa el patrón de *event log*:
cada etapa visitada es una **fila**, no un puntero. Eso permite medir tiempo
por etapa, punto de abandono y funil sin instrumentar nada extra.

```
participantes ──┬──< etapas_visitadas      (una fila por etapa)
                ├──< controles_usados      (los 4 botones del tablero)
                ├──< telemetria_manos      (energía de la cámara)
                └──< postcards              (la postal detrás del QR)

eventos                              (bitácora cruda del sistema)
```

| Tabla | Qué guarda |
|---|---|
| `participantes` | La persona, su meta, su mensaje, estado, tiempos |
| `etapas_visitadas` | **Event log**: una fila por etapa (`enter`→`choose`→`create`→`take`) |
| `controles_usados` | Los 4 controles: `save`→seed, `plan`→constellation, `know`→wave, `goal`→goal |
| `telemetria_manos` | Muestras de energía de la cámara (etapa 03) |
| `postcards` | La postal digital: token, escena, descargas, asesor |
| `eventos` | Bitácora del sistema para diagnóstico |

### Decisiones de diseño

- **`etapas_visitadas` es event log, no estado.** Con `UNIQUE (participante_id, etapa)`
  el schema garantiza una fila por etapa, y `terminada_en` / `segundos` dan el dwell time.
- **`controles_usados` tiene `UNIQUE (participante_id, control)`** — un control por
  persona; la aserción vive en el schema, no en el código PHP.
- **`CHECK` en vez de validación en PHP.** `estado IN ('activo','completado','parcial','abandono')`,
  `control IN ('save','plan','know','goal')`, `compartir IN (0,1)`.
- **`sede` es columna de primera clase** (GDL / CDMX) y entra en `UNIQUE (nombre, sede)`:
  la misma persona puede asistir en dos ciudades y son dos registros.
- **`meta_json` es el escape hatch** para campos que lleguen tarde.
- **`postcards` guarda un *path*, no un blob.** El QR apunta a `/p/<token>`.

### Vistas

| Vista | Responde |
|---|---|
| `v_resumen_participante` | Todo de una persona en una fila |
| `v_funnel` | Cuántos llegaron a cada etapa (dónde se cae) |
| `v_interes_por_meta` | Metas × sede, con controles y duración |
| `v_continuidad` | Descargas y solicitudes de asesor por sede |
| `v_control_uso` | Qué botón gusta más |

## Integración con el runtime PHP

`server/lib/SessionStore.php` escribe en este schema. La ruta por defecto es
`db/portal.sqlite`; se puede sobreescribir con la variable de entorno
`TF1_DB` o el flag `--db` del servidor.

```bash
php server/server.php --db=/ruta/otra.sqlite
```

**La telemetría se persiste con throttle de 2 segundos**, no en cada frame: la
cámara transmite a ~20Hz y escribir cada frame destrozaría la DB en vivo.

## Migraciones

El story de migración completo es:

```bash
python3 init_db.py --reset
```

Para cambiar el schema: edita `schema.sql` → `--reset` → `seed_demo.py` → `queries.py`.

## Verificación

`integrity_check: ok` · `foreign_key_check: sin violaciones` · `journal_mode: wal` ·
15 índices · 36/36 E2E del WebSocket · API smoke verde contra la DB real.
