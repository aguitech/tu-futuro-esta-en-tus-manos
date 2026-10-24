#!/usr/bin/env python3
"""
queries.py — reportes legibles contra la DB del portal.

    python queries.py                       # todos los reportes
    python queries.py --participante 0002  # drill-down de uno
    python queries.py --db otra.sqlite

Sin dependencias: solo sqlite3 de la stdlib.
"""
from __future__ import annotations

import argparse
import sqlite3
from pathlib import Path

HERE = Path(__file__).resolve().parent
DEFAULT_DB = HERE / "portal.sqlite"


# ---------------------------------------------------------------
# helpers de formato
# ---------------------------------------------------------------
def conectar(db: Path) -> sqlite3.Connection:
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    return con


def titulo(t: str) -> None:
    print()
    print("=" * 74)
    print(f"  {t}")
    print("=" * 74)


def tabla(con: sqlite3.Connection, sql: str, params=()) -> None:
    """Imprime una tabla ASCII alineada."""
    cur = con.execute(sql, params)
    filas = cur.fetchall()
    if not filas:
        print("(sin datos)")
        return
    cols = [d[0] for d in cur.description]
    anchos = {c: len(c) for c in cols}
    for f in filas:
        for c in cols:
            anchos[c] = max(anchos[c], len(str(f[c] if f[c] is not None else "-")))
    sep = "  "
    print(sep.join(c.upper().ljust(anchos[c]) for c in cols))
    print(sep.join("-" * anchos[c] for c in cols))
    for f in filas:
        print(sep.join(str(f[c] if f[c] is not None else "-").ljust(anchos[c]) for c in cols))
    print(f"({len(filas)} filas)")


# ---------------------------------------------------------------
# reportes
# ---------------------------------------------------------------
def reporte_resumen(con: sqlite3.Connection) -> None:
    titulo("RESUMEN GENERAL")
    tabla(con, """
        SELECT COUNT(*)                              AS participantes,
               COUNT(DISTINCT sede)                  AS sedes,
               SUM(CASE WHEN estado='completado' THEN 1 ELSE 0 END) AS completados,
               SUM(CASE WHEN estado='parcial'    THEN 1 ELSE 0 END) AS parciales,
               SUM(CASE WHEN estado='abandono'   THEN 1 ELSE 0 END) AS abandonos,
               ROUND(AVG(duracion_ms)/1000.0, 1)    AS duracion_prom_s,
               MAX(duracion_ms)/1000                 AS duracion_max_s
        FROM participantes
    """)
    tabla(con, """
        SELECT COUNT(*) AS etapas_registradas,
               ROUND(AVG(segundos),1) AS seg_promedio,
               ROUND(MIN(segundos),1) AS seg_min,
               ROUND(MAX(segundos),1) AS seg_max
        FROM etapas_visitadas
    """)


def reporte_funnel(con: sqlite3.Connection) -> None:
    titulo("FUNIL POR ETAPA  (dónde se va la gente)")
    tabla(con, """
        SELECT etapa,
               etapa_num                                  AS num,
               participants,
               ROUND(segundos_promedio,1)                 AS seg_prom,
               ROUND(100.0*participants /
                     (SELECT participants FROM v_funnel
                      WHERE etapa_num=1), 1)               AS pct_del_inicio
        FROM v_funnel
        ORDER BY etapa_num
    """)


def reporte_etapas_tiempo(con: sqlite3.Connection) -> None:
    titulo("TIEMPO POR ETAPA  (dónde se traba el flujo)")
    tabla(con, """
        SELECT etapa,
               COUNT(*)                          AS visitas,
               ROUND(AVG(segundos),1)            AS seg_prom,
               ROUND(MIN(segundos),1)            AS seg_min,
               ROUND(MAX(segundos),1)            AS seg_max,
               SUM(CASE WHEN segundos IS NULL THEN 1 ELSE 0 END) AS sin_cerrar
        FROM etapas_visitadas
        GROUP BY etapa
        ORDER BY MIN(etapa_num)
    """)


def reporte_metas(con: sqlite3.Connection) -> None:
    titulo("INTERÉS POR META")
    tabla(con, """
        SELECT meta_id AS meta,
               SUM(participantes)   AS participantes,
               SUM(controles)     AS controles,
               ROUND(AVG(duracion_promedio_s),1) AS dur_prom_s
        FROM v_interes_por_meta
        GROUP BY meta_id
        ORDER BY participantes DESC, meta_id
    """)


def reporte_sedes(con: sqlite3.Connection) -> None:
    titulo("MULTI-SEDE")
    tabla(con, """
        SELECT sede,
               COUNT(*)                              AS participantes,
               SUM(CASE WHEN estado='completado' THEN 1 ELSE 0 END) AS completados,
               ROUND(100.0*SUM(CASE WHEN estado='completado' THEN 1 ELSE 0 END)
                     / COUNT(*), 1)                  AS pct_completado
        FROM participantes
        GROUP BY sede
        ORDER BY sede
    """)
    titulo("CONTINUIDAD  (descargas + asesor)")
    tabla(con, """
        SELECT sede, participantes, con_descarga, con_asesor, pct_descarga
        FROM v_continuidad
        ORDER BY sede
    """)


def reporte_controles(con: sqlite3.Connection) -> None:
    titulo("USO DE CADA CONTROL")
    tabla(con, """
        SELECT control, efecto, participantes, activaciones
        FROM v_control_uso
        ORDER BY participantes DESC, control
    """)


def reporte_telemetria(con: sqlite3.Connection) -> None:
    titulo("TELEMETRÍA DE MANOS  (etapa 03)")
    tabla(con, """
        SELECT COUNT(*)              AS muestras,
               ROUND(AVG(energia),3)  AS energia_prom,
               ROUND(MIN(energia),3)  AS energia_min,
               ROUND(MAX(energia),3)  AS energia_max,
               SUM(manos_juntas)      AS manos_juntas,
               SUM(manos_abiertas)    AS manos_abiertas
        FROM telemetria_manos
    """)


def reporte_postcards(con: sqlite3.Connection) -> None:
    titulo("POSTALS")
    tabla(con, """
        SELECT pc.token,
               pa.folio,
               pa.nombre,
               pc.meta_id AS meta,
               pc.escena,
               pc.descargada,
               pc.advisor
        FROM postcards pc
        JOIN participantes pa ON pa.id = pc.participante_id
        ORDER BY pc.creada_en
    """)


def reporte_participantes(con: sqlite3.Connection) -> None:
    titulo("PARTICIPANTES")
    tabla(con, """
        SELECT folio, nombre, sede, meta_id AS meta, estado,
               etapas_visitadas AS etapas, controles_usados AS ctrl,
               ROUND(duracion_ms/1000.0,1) AS dur_s,
               postal_descargada AS desc
        FROM v_resumen_participante
        ORDER BY folio
    """)


def drilldown(con: sqlite3.Connection, folio: str) -> None:
    row = con.execute(
        "SELECT * FROM participantes WHERE folio LIKE ?", (f"%{folio}%",)
    ).fetchone()
    if row is None:
        print(f"No encontré un participante con folio ~ '{folio}'")
        tabla(con, "SELECT folio, nombre FROM participantes ORDER BY folio")
        return

    titulo(f"PARTICIPANTE  {row['folio']} · {row['nombre'] or '(sin nombre)'}")
    for k in ("id", "sede", "meta_id", "estado", "mensaje", "compartir",
              "iniciada_en", "completada_en", "duracion_ms", "descargas", "asesor"):
        print(f"  {k:16} {row[k] if row[k] is not None else '-'}")

    print()
    print("  ETAPAS:")
    tabla(con, """
        SELECT etapa, etapa_num AS num, fx_disparado AS fx, segundos,
               iniciada_en, terminada_en
        FROM etapas_visitadas
        WHERE participante_id = ?
        ORDER BY etapa_num
    """, (row["id"],))

    print()
    print("  CONTROLES:")
    tabla(con, """
        SELECT control, efecto, orden, activado_en
        FROM controles_usados
        WHERE participante_id = ?
        ORDER BY orden
    """, (row["id"],))

    print()
    print("  TELEMETRÍA:")
    tabla(con, """
        SELECT etapa, energia, manos_juntas AS juntas, manos_abiertas AS abiertas,
               registrado_en
        FROM telemetria_manos
        WHERE participante_id = ?
        ORDER BY registrado_en
    """, (row["id"],))


def main() -> int:
    ap = argparse.ArgumentParser(description="Reportes de la DB del portal.")
    ap.add_argument("--db", default=str(DEFAULT_DB))
    ap.add_argument("--participante", help="drill-down por folio (ej. 0002)")
    args = ap.parse_args()

    db = Path(args.db).resolve()
    if not db.is_file():
        print(f"ERROR: {db} no existe. Ejecuta: python init_db.py --reset && python seed_demo.py")
        return 1

    con = conectar(db)
    try:
        if args.participante:
            drilldown(con, args.participante)
            return 0

        reporte_resumen(con)
        reporte_funnel(con)
        reporte_etapas_tiempo(con)
        reporte_metas(con)
        reporte_sedes(con)
        reporte_controles(con)
        reporte_telemetria(con)
        reporte_postcards(con)
        reporte_participantes(con)
    finally:
        con.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
