# Tu futuro está en tus manos

Portal interactivo de decisiones para **Afore Coppel — Guadalajara**.

Dos pantallas sincronizadas en vivo por **WebSocket**, con backend **PHP** sin
dependencias externas (sin Composer, sin Node, sin npm).

- **Pantalla principal 65"** (`public/stage.html`) — green screen real (`#00B140`),
  cámara + detección de manos, partículas, las 4 etapas y la postal digital con QR.
- **Tablero touch 32"** (`public/board.html`) — 4 controles con respuesta luminosa,
  las 3 metas, mensaje personal, opt-in de compartir y controles de operador.

---

## Arranque rápido

```bash
# 1. Servidor WebSocket (puerto 8790)
php server/server.php

# 2. Servidor HTTP (puerto 8093)
php -S 127.0.0.1:8093 router.php
```

| URL | Qué es |
|---|---|
| `http://localhost:8093/stage.html` | Pantalla 65" (green screen) |
| `http://localhost:8093/board.html` | Tablero touch 32" |
| `http://localhost:8093/health` | Health check |
| `ws://localhost:8790/ws` | WebSocket |

En producción la URL del WS se deriva sola del host. Para forzar una:
`window.TF1_WS_URL = 'wss://host/ws'`.

---

## Las 4 etapas

| # | Etapa | Qué hace |
|---|---|---|
| 01 | **Entra** | El participante se identifica, la pantalla lo saluda por su nombre |
| 02 | **Elige** | Elige su meta: viajar / aprender / disfrutar |
| 03 | **Crea** | Junta las manos frente a la cámara; la energía dirige los efectos |
| 04 | **Llévatelo** | Su postal digital con QR + mensaje personal + opt-in de compartir |

## Los 4 controles → 4 efectos

| Control | Efecto en la pantalla 65" |
|---|---|
| **AHORRAR** | `seed` — brotar una semilla |
| **PLANEAR** | `constellation` — constelación de 12 puntos |
| **CONOCER** | `wave` — onda con dato de marca |
| **MI META** | `goal` — estallido de la meta elegida |

---

## Arquitectura

```
server/
  server.php          entrypoint: event loop + routing de verbos
  lib/Frame.php       framing RFC 6455 (masking, opcodes, close)
  lib/WsConnection.php handshake + buffers
  lib/WsServer.php    accept/select/broadcast
  lib/PortalState.php estado del portal + snapshot a las pantallas
  lib/PortalEngine.php máquina de estados + arbitraje de taps
  lib/SessionStore.php SQLite: sesiones, eventos, postcards
public/
  stage.html          pantalla 65"
  board.html          tablero touch
  assets/portal.js    cliente WS (reconexión + backoff) + generador de QR
  assets/fx.js        motor de efectos + tracking de manos
  assets/stage.js     lógica de la pantalla principal
  assets/board.js     lógica del tablero
api/
  metrics.php         métricas agregadas (participación/experiencia/interés/continuidad)
  postcard.php        postal renderizada server-side (funciona sin JS)
router.php            front controller
tools/
  e2e.py              suite E2E contra el WS real
  live.py             driver de sesión en vivo
```

### Protocolo

WebSocket RFC 6455 implementado a mano sobre `sockets`/`stream_select`.
Rol `stage` **no puede** comandar la experiencia — solo `board` y `op` pueden.

**Board → Server:** `enter`, `goal`, `press`, `message`, `share`, `create`,
`take`, `complete`, `skip`, `explode`, `setting`, `state`, `ping`

**Server → todos:** `welcome`, `state`, `presence`, `effect`, `event`

---

## Verificación

```bash
python3 tools/e2e.py      # 36/36 comprobaciones contra el WS real
php -l <cada .php>
node --check <cada .js>   # como ESM
```

---

## Base de datos

SQLite, schema propio, **sin dependencias**. El `.sqlite` se deriva de
`db/schema.sql` — nunca se edita a mano.

```bash
cd db
python3 init_db.py --reset   # crea la DB desde schema.sql
python3 seed_demo.py         # 8 participantes de demostración
python3 queries.py           # reportes
```

Detalle completo del modelo en **[db/README.md](db/README.md)**.

| Tabla | Qué guarda |
|---|---|
| `participantes` | Persona, meta, mensaje, estado, tiempos |
| `etapas_visitadas` | **Event log**: una fila por etapa del recorrido |
| `controles_usados` | Los 4 botones del tablero |
| `telemetria_manos` | Energía de la cámara (etapa 03) |
| `postcards` | La postal detrás del QR |
| `eventos` | Bitácora del sistema |

El runtime PHP (`server/lib/SessionStore.php`) escribe en este schema.
Ruta por defecto `db/portal.sqlite`; sobreescribible con `TF1_DB` o `--db`.

---

## Notas de diseño

- **Green screen** `#00B140` por defecto. `?bg=dark` para probar en monitor.
- **Paleta extraída de los píxeles reales del PDF**, no inventada:
  `#010D27` navy profundo · `#011538` navy · `#FFD52A` amarillo Afore ·
  `#F6F8FC` blanco roto · `#4DA3FF` sky · `#FF8A5B` coral.
- **QR generado a mano** — sin librerías externas.
- El **canvas de efectos se redimensiona con `ResizeObserver`**: si no, mide
  1px de alto antes del primer layout y los efectos no se ven en la 65".
- `.hidden { display:none !important }` — sin esto todas las etapas se apilan.

---

**AGUITECH** · Ingeniería + Diseño + Sistemas
