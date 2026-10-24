-- ============================================================
--   "Tu futuro está en tus manos" · Schema de base de datos
--   Afore Coppel — Guadalajara
--
--   Fuente de verdad. NUNCA editar el .db a mano.
--   Cambios aquí →  python init_db.py --reset
--
--   El flujo es un viaje de 4 ETAPAS (multi-stage), así que se
--   usa el patrón de event-log: cada etapa visitada es una FILA
--   (etapas_visitadas), no un puntero. Eso permite medir tiempo
--   por etapa, punto de abandono y funil.
-- ============================================================

PRAGMA foreign_keys = ON;      -- OFF por default en SQLite: sin esto no hay CASCADE
PRAGMA journal_mode = WAL;     -- lecturas concurrentes mientras se escribe
PRAGMA synchronous = NORMAL;

-- ------------------------------------------------------------
-- 1. participante — la persona que recorre el portal
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS participantes (
    id             TEXT PRIMARY KEY,                    -- 'S260923-A1B2C3'
    folio          TEXT UNIQUE NOT NULL,                -- 'TF1-260923-0001' (lo que ve el operador)
    nombre         TEXT,
    sede           TEXT NOT NULL DEFAULT 'GDL',          -- multi-sede
    meta_id        TEXT,                                -- 'viajar' | 'aprender' | 'disfrutar'
    mensaje        TEXT,                                -- mensaje personal de la postal
    compartir      INTEGER NOT NULL DEFAULT 0 CHECK (compartir IN (0,1)),
    estado         TEXT NOT NULL DEFAULT 'activo'
                        CHECK (estado IN ('activo','completado','parcial','abandono')),
    iniciada_en    TEXT NOT NULL DEFAULT (datetime('now')),
    completada_en  TEXT,
    duracion_ms    INTEGER NOT NULL DEFAULT 0,
    descargas      INTEGER NOT NULL DEFAULT 0,
    asesor         INTEGER NOT NULL DEFAULT 0,          -- solicitud de asesor
    meta_json      TEXT,                                -- escape hatch para campos tardíos
    UNIQUE (nombre, sede)
);

CREATE INDEX IF NOT EXISTS idx_part_sede   ON participantes(sede);
CREATE INDEX IF NOT EXISTS idx_part_meta   ON participantes(meta_id);
CREATE INDEX IF NOT EXISTS idx_part_estado ON participantes(estado);
CREATE INDEX IF NOT EXISTS idx_part_ini    ON participantes(iniciada_en);

-- ------------------------------------------------------------
-- 2. etapas_visitadas — EVENT LOG, no columna de estado
--    Una fila por etapa que el participante recorrió.
--    Con esto sale gratis: funil, tiempo por etapa y abandono.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etapas_visitadas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    participante_id TEXT NOT NULL REFERENCES participantes(id) ON DELETE CASCADE,
    etapa          TEXT NOT NULL,                       -- 'enter'|'choose'|'create'|'take'|'done'
    etapa_num      INTEGER NOT NULL,                    -- 1..4 para ordenar
    fx_disparado   TEXT,                                -- 'seed'|'constellation'|'wave'|'goal'
    iniciada_en    TEXT NOT NULL DEFAULT (datetime('now')),
    terminada_en   TEXT,
    segundos       INTEGER,
    UNIQUE (participante_id, etapa)
);

CREATE INDEX IF NOT EXISTS idx_etapa_part    ON etapas_visitadas(participante_id);
CREATE INDEX IF NOT EXISTS idx_etapa_nombre  ON etapas_visitadas(etapa);
CREATE INDEX IF NOT EXISTS idx_etapa_seg     ON etapas_visitadas(segundos);

-- ------------------------------------------------------------
-- 3. controles_usados — los 4 botones del tablero touch
--    UNIQUE(participante_id, control) = un control por persona
--    (el motor ya lo garantiza, pero el schema lo asienta)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS controles_usados (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    participante_id TEXT NOT NULL REFERENCES participantes(id) ON DELETE CASCADE,
    control         TEXT NOT NULL
                     CHECK (control IN ('save','plan','know','goal')),
    efecto          TEXT NOT NULL,                      -- 'seed'|'constellation'|'wave'|'goal'
    orden           INTEGER NOT NULL DEFAULT 0,
    activado_en     TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (participante_id, control)
);

CREATE INDEX IF NOT EXISTS idx_ctrl_part   ON controles_usados(participante_id);
CREATE INDEX IF NOT EXISTS idx_ctrl_nombre ON controles_usados(control);

-- ------------------------------------------------------------
-- 4. telemetria_manos — la cámara de la pantalla 65"
--    Muestreo de energía (manos juntas / abiertas) por etapa
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telemetria_manos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    participante_id TEXT REFERENCES participantes(id) ON DELETE CASCADE,
    etapa           TEXT,
    energia         REAL NOT NULL DEFAULT 0,
    manos_juntas    INTEGER NOT NULL DEFAULT 0 CHECK (manos_juntas IN (0,1)),
    manos_abiertas  INTEGER NOT NULL DEFAULT 0 CHECK (manos_abiertas IN (0,1)),
    registrado_en   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tele_part ON telemetria_manos(participante_id);
CREATE INDEX IF NOT EXISTS idx_tele_etap ON telemetria_manos(etapa);

-- ------------------------------------------------------------
-- 5. postcards — la postal digital con QR
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS postcards (
    token          TEXT PRIMARY KEY,                    -- 'TF' + 16 hex
    participante_id TEXT NOT NULL REFERENCES participantes(id) ON DELETE CASCADE,
    meta_id        TEXT,
    mensaje        TEXT,
    escena         TEXT,                               -- 'travel'|'learn'|'enjoy'
    creada_en      TEXT NOT NULL DEFAULT (datetime('now')),
    descargada     INTEGER NOT NULL DEFAULT 0,
    advisor        INTEGER NOT NULL DEFAULT 0,
    UNIQUE (participante_id)
);

CREATE INDEX IF NOT EXISTS idx_post_part ON postcards(participante_id);
CREATE INDEX IF NOT EXISTS idx_post_meta ON postcards(meta_id);

-- ------------------------------------------------------------
-- 6. eventos — bitácora cruda del sistema (diagnóstico)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    participante_id TEXT,
    tipo            TEXT NOT NULL,                      -- 'session_started','control_plan',…
    detalle         TEXT,
    en              TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ev_part ON eventos(participante_id);
CREATE INDEX IF NOT EXISTS idx_ev_tipo ON eventos(tipo);
CREATE INDEX IF NOT EXISTS idx_ev_en   ON eventos(en);

-- ------------------------------------------------------------
-- VISTAS — un query por reporte
-- ------------------------------------------------------------

-- Resumen por participante (el drill-down de todo)
CREATE VIEW IF NOT EXISTS v_resumen_participante AS
SELECT
    p.id,
    p.folio,
    p.nombre,
    p.sede,
    p.meta_id,
    p.estado,
    p.iniciada_en,
    p.duracion_ms,
    COUNT(DISTINCT e.id)        AS etapas_visitadas,
    COUNT(DISTINCT c.id)        AS controles_usados,
    COUNT(DISTINCT pc.token)    AS postal,
    MAX(pc.descargada)          AS postal_descargada,
    (SELECT e2.etapa FROM etapas_visitadas e2
      WHERE e2.participante_id = p.id ORDER BY e2.etapa_num DESC LIMIT 1) AS ultima_etapa
FROM participantes p
LEFT JOIN etapas_visitadas e  ON e.participante_id = p.id
LEFT JOIN controles_usados c  ON c.participante_id = p.id
LEFT JOIN postcards pc         ON pc.participante_id = p.id
GROUP BY p.id;

-- Funil: cuántos llegaron a cada etapa (el punto de abandono salta a la vista)
CREATE VIEW IF NOT EXISTS v_funnel AS
SELECT
    etapa,
    MIN(etapa_num)                    AS etapa_num,
    COUNT(DISTINCT participante_id)    AS participants,
    ROUND(AVG(segundos), 1)           AS segundos_promedio
FROM etapas_visitadas
GROUP BY etapa;

-- Las 4 metas × sede (reporte de interés)
CREATE VIEW IF NOT EXISTS v_interes_por_meta AS
SELECT
    p.meta_id,
    p.sede,
    COUNT(DISTINCT p.id)              AS participantes,
    COUNT(DISTINCT c.id)              AS controles,
    ROUND(AVG(p.duracion_ms) / 1000.0, 1) AS duracion_promedio_s
FROM participantes p
LEFT JOIN controles_usados c ON c.participante_id = p.id
WHERE p.meta_id IS NOT NULL
GROUP BY p.meta_id, p.sede;

-- Continuidad: descargas y solicitudes de asesor
CREATE VIEW IF NOT EXISTS v_continuidad AS
SELECT
    p.sede,
    COUNT(DISTINCT p.id)                       AS participantes,
    SUM(CASE WHEN pc.descargada > 0 THEN 1 ELSE 0 END) AS con_descarga,
    SUM(CASE WHEN p.asesor > 0 THEN 1 ELSE 0 END)      AS con_asesor,
    ROUND(100.0 * SUM(CASE WHEN pc.descargada > 0 THEN 1 ELSE 0 END)
          / NULLIF(COUNT(DISTINCT p.id), 0), 1)  AS pct_descarga
FROM participantes p
LEFT JOIN postcards pc ON pc.participante_id = p.id
GROUP BY p.sede;

-- Uso de cada control (qué botón gusta más)
CREATE VIEW IF NOT EXISTS v_control_uso AS
SELECT
    control,
    efecto,
    COUNT(DISTINCT participante_id) AS participantes,
    COUNT(*)                        AS activaciones
FROM controles_usados
GROUP BY control, efecto;
