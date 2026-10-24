#!/usr/bin/env python3
"""
seed_demo.py — datos de demostración deterministas para el portal.

    python seed_demo.py            # siembra la DB por defecto
    python seed_demo.py --db otra.sqlite

Determinista: los mismos datos en cada corrida (los timestamps se derivan de
un ancla fija), así los diffs entre corridas son legibles.

Los 8 participantes NO son todos iguales a propósito — cubren rutas distintas
para que los reportes de abandono, funil y metas tengan algo que mostrar:
  · completo   → recorrió las 4 etapas
  · parcial    → se quedó en la etapa 02
  · abandono   → salió en la etapa 01
  · otra sede  → prueba los reportes multi-sede
"""
from __future__ import annotations

import argparse
import json
import sqlite3
from datetime import datetime, timedelta
from pathlib import Path

HERE = Path(__file__).resolve().parent
DEFAULT_DB = HERE / "portal.sqlite"

# Un solo reloj para todo el seed (UTC), nunca mezclar con datetime.now() local.
ANCLA = datetime(2026, 9, 23, 16, 0, 0)

ETAPAS = {
    "enter": 1,
    "choose": 2,
    "create": 3,
    "take": 4,
    "done": 5,
}

METAS = {
    "viajar": {"escena": "travel", "color": "#4DA3FF"},
    "aprender": {"escena": "learn", "color": "#FFD52A"},
    "disfrutar": {"escena": "enjoy", "color": "#FF8A5B"},
}

CONTROL_EFECTO = {
    "save": "seed",
    "plan": "constellation",
    "know": "wave",
    "goal": "goal",
}

# ---------------------------------------------------------------
# Los 8 participantes: rutas deliberadamente distintas
# ---------------------------------------------------------------
PARTICIPANTES = [
    # (nombre, sede, meta, estado, ruta, duracion_s, descargar, asesor)
    ("Ana Ramírez",      "GDL", "viajar",    "completado", ["enter","choose","create","take"], 118, 1, 0),
    ("Luis Hernández",   "GDL", "aprender",  "completado", ["enter","choose","create","take"],  96, 1, 1),
    ("Sofía Delgado",    "GDL", "disfrutar", "completado", ["enter","choose","create","take"], 143, 0, 0),
    ("Carlos Mendoza",   "GDL", "viajar",    "completado", ["enter","choose","create","take"],  87, 1, 0),
    ("Fernanda Ruiz",    "GDL", "aprender",  "parcial",    ["enter","choose"],                34, 0, 0),
    ("Miguel Torres",    "GDL", None,        "abandono",   ["enter"],                          12, 0, 0),
    ("Renata Bautista",  "CDMX","disfrutar", "completado", ["enter","choose","create","take"], 127, 1, 0),
    ("Diego Fuentes",    "CDMX","viajar",    "parcial",    ["enter","choose"],                41, 0, 0),
]

# Qué controles tocó cada participante (en orden).
CONTROLES_POR_PARTICIPANTE = {
    "Ana Ramírez":     ["save", "plan", "know", "goal"],
    "Luis Hernández":  ["save", "know"],
    "Sofía Delgado":   ["plan", "goal", "know", "save"],
    "Carlos Mendoza":  ["goal", "save"],
    "Fernanda Ruiz":   ["save"],
    "Miguel Torres":   [],
    "Renata Bautista": ["save", "plan", "know"],
    "Diego Fuentes":   ["know"],
}

MENSAJES = {
    "Ana Ramírez": "Hoy comienzo a construir mi futuro",
    "Luis Hernández": "Voy a aprender algo nuevo cada semana",
    "Sofía Delgado": "Quiero disfrutar más tiempo con mi familia",
    "Carlos Mendoza": "Este año voy a conocer otro país",
    "Fernanda Ruiz": "Mi futuro empieza hoy",
    "Renata Bautista": "Afore me acompaña en cada paso",
}


def _ts(base: datetime, segundos: int) -> str:
    """Timestamp ISO a partir del ancla, desplazado N segundos."""
    return (base + timedelta(seconds=segundos)).strftime("%Y-%m-%d %H:%M:%S")


def conectar(db: Path) -> sqlite3.Connection:
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    con.execute("PRAGMA journal_mode = WAL")
    return con


def limpiar(con: sqlite3.Connection) -> None:
    # Orden inverso al de dependencia (CASCADE borraría los hijos igual,
    # pero ser explícito evita surprises).
    for t in ("eventos", "postcards", "telemetria_manos",
              "controles_usados", "etapas_visitadas", "participantes"):
        con.execute(f"DELETE FROM {t}")
    con.commit()


def main() -> int:
    ap = argparse.ArgumentParser(description="Siembra datos de demostración.")
    ap.add_argument("--db", default=str(DEFAULT_DB))
    args = ap.parse_args()

    db = Path(args.db).resolve()
    if not db.is_file():
        print(f"ERROR: {db} no existe. Ejecuta primero: python init_db.py --reset")
        return 1

    con = conectar(db)
    try:
        limpiar(con)

        for i, (nombre, sede, meta, estado, ruta, dur_s, descarga, asesor) in enumerate(
            PARTICIPANTES, start=1
        ):
            folio = f"TF1-260923-{i:04d}"
            pid = f"S260923-{i:04d}"
            inicio = ANCLA + timedelta(minutes=i * 7)   # llega cada 7 minutos
            inicio_en = _ts(inicio, 0)
            completada_en = _ts(inicio, dur_s) if estado == "completado" else None

            con.execute(
                """INSERT INTO participantes
                   (id, folio, nombre, sede, meta_id, mensaje, compartir,
                    estado, iniciada_en, completada_en, duracion_ms,
                    descargas, asesor, meta_json)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    pid, folio, nombre, sede, meta,
                    MENSAJES.get(nombre), descarga,
                    estado, inicio_en, completada_en, dur_s * 1000,
                    descarga, asesor,
                    json.dumps({"seed": True, "puesto": i}, ensure_ascii=False),
                ),
            )

            # --- etapas_visitadas: el event log -------------------------
            # Reparto del tiempo total entre las etapas recorridas.
            n = len(ruta)
            por_etapa = max(1, dur_s // n) if estado == "completado" else max(1, dur_s // 2)
            t = 0
            for idx, etapa in enumerate(ruta):
                fx = None
                if etapa == "create":
                    fx = "constellation"
                elif etapa == "take":
                    fx = "goal"
                elif etapa == "choose":
                    fx = "seed"
                dur = por_etapa
                iniciada = _ts(inicio, t)
                terminada = _ts(inicio, t + dur)
                con.execute(
                    """INSERT INTO etapas_visitadas
                       (participante_id, etapa, etapa_num, fx_disparado,
                        iniciada_en, terminada_en, segundos)
                       VALUES (?,?,?,?,?,?,?)""",
                    (pid, etapa, ETAPAS[etapa], fx, iniciada, terminada, dur),
                )
                t += dur

            # --- controles_usados --------------------------------------
            for orden, control in enumerate(CONTROLES_POR_PARTICIPANTE.get(nombre, [])):
                con.execute(
                    """INSERT INTO controles_usados
                       (participante_id, control, efecto, orden, activado_en)
                       VALUES (?,?,?,?,?)""",
                    (pid, control, CONTROL_EFECTO[control], orden, _ts(inicio, t + orden * 2)),
                )

            # --- telemetria_manos --------------------------------------
            # Solo tiene sentido en la etapa de creación.
            if "create" in ruta:
                for k in range(6):
                    energia = round(0.18 + 0.11 * ((i + k) % 7), 3)
                    con.execute(
                        """INSERT INTO telemetria_manos
                           (participante_id, etapa, energia, manos_juntas,
                            manos_abiertas, registrado_en)
                           VALUES (?,?,?,?,?,?)""",
                        (
                            pid, "create", energia,
                            1 if energia > 0.55 else 0,
                            1 if energia < 0.35 else 0,
                            _ts(inicio, t + k * 5),
                        ),
                    )

            # --- postcards ---------------------------------------------
            if estado == "completado":
                token = f"TF{i:02d}A1B2C3D4E5F60718"
                escena = METAS[meta]["escena"] if meta else None
                con.execute(
                    """INSERT INTO postcards
                       (token, participante_id, meta_id, mensaje, escena,
                        creada_en, descargada, advisor)
                       VALUES (?,?,?,?,?,?,?,?)""",
                    (
                        token, pid, meta, MENSAJES.get(nombre), escena,
                        completada_en, descarga, asesor,
                    ),
                )

            # --- eventos ------------------------------------------------
            con.execute(
                "INSERT INTO eventos (participante_id, tipo, detalle, en) VALUES (?,?,?,?)",
                (pid, "session_started", json.dumps({"nombre": nombre}), inicio_en),
            )
            for etapa in ruta:
                con.execute(
                    "INSERT INTO eventos (participante_id, tipo, detalle, en) VALUES (?,?,?,?)",
                    (pid, f"step_{etapa}", None, inicio_en),
                )
            if estado == "completado":
                con.execute(
                    "INSERT INTO eventos (participante_id, tipo, detalle, en) VALUES (?,?,?,?)",
                    (pid, "session_completed", json.dumps({"meta": meta}), completada_en),
                )

        con.commit()

        print("Seed aplicado:")
        for t in ("participantes", "etapas_visitadas", "controles_usados",
                  "telemetria_manos", "postcards", "eventos"):
            n = con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
            print(f"  {t:22} {n:>4} filas")

        print("\nRutas cubiertas:")
        for nombre, sede, meta, estado, ruta, *_ in PARTICIPANTES:
            print(f"  {nombre:20} {sede:5} {str(meta):11} {estado:11} {'→'.join(ruta)}")
    finally:
        con.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
