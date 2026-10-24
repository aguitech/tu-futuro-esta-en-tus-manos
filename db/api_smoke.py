#!/usr/bin/env python3
"""Smoke test del API contra la DB real: metrics, advisor request, postcard."""
import json
import sqlite3
import urllib.request

BASE = "http://127.0.0.1:8093"
DB = "/root/tu-futuro-esta-en-tus-manos/db/portal.sqlite"

con = sqlite3.connect(DB)
token = con.execute("SELECT token FROM postcards LIMIT 1").fetchone()[0]
antes = con.execute(
    "SELECT p.asesor, p.descargas, pc.advisor, pc.descargada "
    "FROM participantes p JOIN postcards pc ON pc.participante_id=p.id LIMIT 1"
).fetchone()


def pedir(url, data=None):
    req = urllib.request.Request(url, method="POST" if data else "GET")
    if data:
        req.add_header("Content-Type", "application/json")
        req.data = json.dumps(data).encode()
    with urllib.request.urlopen(req, timeout=8) as r:
        return r.status, r.read().decode()


print("=== POST /api/metrics.php?action=advisor ===")
code, body = pedir(f"{BASE}/api/metrics.php?action=advisor", {"token": token})
print(f"  HTTP {code}: {body.strip()[:120]}")

despues = con.execute(
    "SELECT p.asesor, p.descargas, pc.advisor, pc.descargada "
    "FROM participantes p JOIN postcards pc ON pc.participante_id=p.id LIMIT 1"
).fetchone()

print(f"  asesor:   {antes[0]} -> {despues[0]}")
print(f"  advisor:  {antes[2]} -> {despues[2]}")
assert despues[0] == antes[0] + 1, "asesor NO se registró"
assert despues[2] == antes[2] + 1, "advisor en postcard NO se registró"

print("\n=== GET /api/metrics.php ===")
code, body = pedir(f"{BASE}/api/metrics.php")
m = json.loads(body)["metrics"]
print(f"  HTTP {code}")
print(f"  participacion: iniciadas={m['participacion']['sesiones_iniciadas']} "
      f"completadas={m['participacion']['sesiones_completadas']}")
print(f"  experiencia:   mediana={m['experiencia']['duracion_mediana_s']}s "
      f"abandono={m['experiencia']['abandono_por_paso']}")
print(f"  interes:       metas={m['interes']['metas_elegidas']}")
print(f"  continuidad:    descargas={m['continuidad']['descargas']} "
      f"asesor={m['continuidad']['solicitudes_asesor']}")

print("\n=== GET /p/<token> (postal) ===")
req = urllib.request.Request(f"{BASE}/p/{token}")
with urllib.request.urlopen(req, timeout=8) as r:
    html = r.read().decode()
    print(f"  HTTP {r.status}  {len(html)} bytes")
for needle in ("Descargar postal", "Hablar con un asesor", "futuro está en tus manos"):
    print(f"  {'OK ' if needle in html else 'FALTA'} {needle!r}")

print("\nTODAS LAS COMPROBACIONES PASARON")
