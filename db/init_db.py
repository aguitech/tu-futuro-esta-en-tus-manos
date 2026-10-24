#!/usr/bin/env python3
"""
init_db.py — crea la base de datos de "Tu futuro está en tus manos" desde
schema.sql.

    python init_db.py           # crea si no existe (idempotente)
    python init_db.py --reset   # borra y recrea desde cero
    python init_db.py --db /ruta/otra.sqlite

El .db SIEMPRE se deriva de schema.sql. Nunca lo edites a mano.
"""
from __future__ import annotations

import argparse
import sqlite3
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SCHEMA = HERE / "schema.sql"
DEFAULT_DB = HERE / "portal.sqlite"

# Tablas que el schema declara, en orden de dependencia.
TABLAS = [
    "participantes",
    "etapas_visitadas",
    "controles_usados",
    "telemetria_manos",
    "postcards",
    "eventos",
]

VISTAS = [
    "v_resumen_participante",
    "v_funnel",
    "v_interes_por_meta",
    "v_continuidad",
    "v_control_uso",
]


def conectar(db: Path) -> sqlite3.Connection:
    db.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    # foreign_keys es OFF por default POR CONEXIÓN: hay que activarlo aquí
    # o los ON DELETE CASCADE no hacen nada.
    con.execute("PRAGMA foreign_keys = ON")
    con.execute("PRAGMA journal_mode = WAL")
    con.execute("PRAGMA synchronous = NORMAL")
    return con


def ejecutar_schema(con: sqlite3.Connection) -> None:
    sql = SCHEMA.read_text(encoding="utf-8")
    con.executescript(sql)
    con.commit()


def contar(con: sqlite3.Connection, tabla: str) -> int:
    return con.execute(f"SELECT COUNT(*) FROM {tabla}").fetchone()[0]


def main() -> int:
    ap = argparse.ArgumentParser(description="Inicializa la DB del portal.")
    ap.add_argument("--db", default=str(DEFAULT_DB), help="ruta del archivo .sqlite")
    ap.add_argument("--reset", action="store_true", help="borra el .db antes de crear")
    args = ap.parse_args()

    db = Path(args.db).resolve()

    if not SCHEMA.is_file():
        print(f"ERROR: no encuentro {SCHEMA}", file=sys.stderr)
        return 1

    if args.reset:
        for sufijo in ("", "-wal", "-shm"):
            p = Path(str(db) + sufijo)
            if p.exists():
                p.unlink()
        print(f"· reset: {db.name} eliminado")

    con = conectar(db)
    try:
        ejecutar_schema(con)

        # Verificación: ¿existen todas las tablas y vistas?
        existentes = {
            r["name"]
            for r in con.execute(
                "SELECT name FROM sqlite_master WHERE type IN ('table','view')"
            )
        }
        faltan = [t for t in TABLAS + VISTAS if t not in existentes]
        if faltan:
            print(f"ERROR: faltan objetos tras el schema: {faltan}", file=sys.stderr)
            return 1

        print(f"DB inicializada: {db}")
        print(f"  tablas: {len(TABLAS)}   vistas: {len(VISTAS)}")
        for t in TABLAS:
            print(f"    {t:24} {contar(con, t):>6} filas")
        for v in VISTAS:
            n = con.execute(f"SELECT COUNT(*) FROM {v}").fetchone()[0]
            print(f"    {v:24} {n:>6} filas (vista)")
    finally:
        con.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
