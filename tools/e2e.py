#!/usr/bin/env python3
"""
"Tu futuro está en tus manos" · end-to-end WebSocket smoke test.

RFC 6455 client written with the Python stdlib only (no pip needed).
Verifies the real 4-step portal flow plus the permission model.

Usage:  python3 tools/e2e.py [--host 127.0.0.1] [--port 8790]
Exit code 0 = all checks passed.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import socket
import struct
import sys
import time

PASS, FAIL = "\033[92mPASS\033[0m", "\033[91mFAIL\033[0m"
results: list[tuple[bool, str]] = []


def check(ok: bool, label: str, detail: str = "") -> bool:
    results.append((ok, label))
    line = f"  [{PASS if ok else FAIL}] {label}"
    if detail and not ok:
        line += f"\n         → {detail}"
    print(line)
    return ok


class WS:
    """Minimal RFC 6455 client. Text frames only; the server never masks."""

    def __init__(self, host: str, port: int, path: str = "/ws", timeout: float = 5.0):
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.sock.settimeout(timeout)
        self.buf = b""
        self._handshake(host, port, path)

    def _handshake(self, host: str, port: int, path: str) -> None:
        key = base64.b64encode(os.urandom(16)).decode()
        req = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host}:{port}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "\r\n"
        )
        self.sock.sendall(req.encode())

        while b"\r\n\r\n" not in self.buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise RuntimeError("handshake: server cerró la conexión")
            self.buf += chunk

        head, _, rest = self.buf.partition(b"\r\n\r\n")
        self.buf = rest
        status = head.split(b"\r\n")[0].decode()
        if "101" not in status:
            raise RuntimeError(f"handshake falló: {status}")

        expected = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
        ).decode()
        if expected.lower() not in head.decode().lower():
            raise RuntimeError("handshake: Sec-WebSocket-Accept inválido")

    def send(self, obj: dict) -> None:
        payload = json.dumps(obj).encode()
        mask = os.urandom(4)
        n = len(payload)
        frame = bytearray([0x81])
        if n < 126:
            frame.append(0x80 | n)
        elif n < 65536:
            frame.append(0x80 | 126)
            frame += struct.pack(">H", n)
        else:
            frame.append(0x80 | 127)
            frame += struct.pack(">Q", n)
        frame += mask
        frame += bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(bytes(frame))

    def _read(self, n: int) -> bytes:
        while len(self.buf) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError("conexión cerrada por el servidor")
            self.buf += chunk
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def recv(self, timeout: float = 3.0) -> dict:
        self.sock.settimeout(timeout)
        while True:
            b0, b1 = self._read(2)
            opcode = b0 & 0x0F
            length = b1 & 0x7F

            if length == 126:
                length = struct.unpack(">H", self._read(2))[0]
            elif length == 127:
                length = struct.unpack(">Q", self._read(8))[0]

            payload = self._read(length) if length else b""

            if opcode == 0x8:
                raise RuntimeError("servidor cerró el socket")
            if opcode == 0x9:
                continue
            if opcode in (0x1, 0x2):
                return json.loads(payload.decode())

    def wait_for(self, pred, timeout: float = 4.0, what: str = "mensaje"):
        deadline = time.time() + timeout
        seen = []
        while time.time() < deadline:
            try:
                msg = self.recv(timeout=max(0.05, deadline - time.time()))
            except (socket.timeout, TimeoutError):
                continue
            seen.append(msg.get("type"))
            if pred(msg):
                return msg
        raise TimeoutError(f"timeout esperando {what} (vistos: {set(seen)})")

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8790)
    args = ap.parse_args()

    print(f"\n\033[1mTu futuro está en tus manos · E2E WebSocket\033[0m  ({args.host}:{args.port})\n")

    stage = board = None

    try:
        # -- 1. handshakes ------------------------------------------------
        try:
            stage = WS(args.host, args.port)
            board = WS(args.host, args.port)
            check(True, "handshake RFC 6455 x2 (pantalla 65\" + tablero)")
        except Exception as e:
            check(False, "handshake RFC 6455 x2", str(e))
            return 1

        # -- 2. roles -----------------------------------------------------
        stage.send({"type": "hello", "role": "stage", "label": "pantalla-65"})
        w = stage.wait_for(lambda m: m["type"] == "welcome", what="welcome stage")
        check("stage" in w and "board" in w, "welcome con stage + board", json.dumps(w)[:160])

        board.send({"type": "hello", "role": "board", "label": "tablero"})
        bw = board.wait_for(lambda m: m["type"] == "welcome", what="welcome board")
        check(True, "roles stage y board registrados")

        # -- 3. the 4 controls exist -------------------------------------
        # The welcome frame already carries the full board payload.
        controls = (bw.get("board") or {}).get("controls") or []
        names = [c["name"] for c in controls]
        check(names == ["AHORRAR", "PLANEAR", "CONOCER", "MI META"],
              "los 4 controles del PDF existen", json.dumps(names))

        goals = (bw.get("board") or {}).get("goals") or []
        check([g["id"] for g in goals] == ["viajar", "aprender", "disfrutar"],
              "las 3 metas existen", json.dumps([g["id"] for g in goals]))

        # -- 4. STEP 1 · Entra -------------------------------------------
        board.send({"type": "enter", "opts": {"name": "Lucía"}})
        st = board.wait_for(lambda m: m.get("event") == "enter", what="evento enter")
        check(st["stage"]["step"] == "enter", "etapa 01 Entra activada", st["stage"]["step"])
        check(st["stage"]["participant"]["name"] == "Lucía", "el participante tiene nombre")
        check(st["stage"]["qrToken"] is not None, "se genera el token QR de la postal")
        check(st["stage"]["deadline"] > time.time(), "el temporizador de la etapa corre")

        # the stage screen sees the same state
        sst = stage.wait_for(lambda m: m.get("event") == "enter", what="enter en stage")
        check(sst["stage"]["participant"]["name"] == "Lucía",
              "la pantalla 65\" ve al participante")

        # -- 5. STEP 2 · Elige -------------------------------------------
        board.send({"type": "goal", "goal": "viajar"})
        st = board.wait_for(lambda m: m.get("event") == "choose", what="evento choose")
        check(st["stage"]["step"] == "choose", "etapa 02 Elige activada", st["stage"]["step"])
        check(st["stage"]["goal"] == "viajar", "la meta quedó registrada")
        check(st["stage"]["goalData"]["name"] == "VIAJAR", "llega el detalle de la meta")

        # -- 6. the 4 board controls → effects ---------------------------
        fx_seen = []
        for ctl, expect in [("save", "seed"), ("plan", "constellation"),
                            ("know", "wave"), ("goal", "goal")]:
            board.send({"type": "press", "control": ctl})
            st = board.wait_for(lambda m: m.get("event") == "press", what=f"press {ctl}")
            fx = st["data"]["fx"]
            fx_seen.append(fx)
            check(fx == expect, f"control {ctl} → efecto {expect}", fx)

        check(len(set(fx_seen)) == 4, "los 4 efectos son distintos", json.dumps(fx_seen))
        check(set(st["stage"]["activated"]) == {"save", "plan", "know", "goal"},
              "los controles activados quedan registrados",
              json.dumps(list(st["stage"]["activated"])))

        # the wave carries a brand fact
        board.send({"type": "press", "control": "know"})
        st = board.wait_for(lambda m: m.get("event") == "press", what="press know 2")
        fact = (st["data"].get("data") or {}).get("fact")
        check(bool(fact) and "Afore" in fact, "la onda CONOCER trae un dato de marca", str(fact))

        # the constellation has points
        board.send({"type": "press", "control": "plan"})
        st = board.wait_for(lambda m: m.get("event") == "press", what="press plan 2")
        pts = (st["data"].get("data") or {}).get("points") or []
        check(len(pts) >= 8, f"la constelación tiene {len(pts)} puntos")

        # -- 7. camera telemetry -----------------------------------------
        board.send({"type": "track", "opts": {"energy": 0.82, "joined": True, "open": True}})
        tr = board.wait_for(lambda m: m["type"] == "track", timeout=3.0, what="frame de tracking")
        check(tr["energy"] == 0.82, "la telemetría de la cámara llega al tablero", str(tr.get("energy")))
        check(tr["joined"] is True and tr["open"] is True, "el estado de manos se propaga")

        # -- 8. explosion --------------------------------------------------
        board.send({"type": "explode"})
        st = board.wait_for(lambda m: m.get("event") == "explode", what="evento explode")
        check(st["stage"]["effect"] == "explosion", "la explosión se activa", str(st["stage"]["effect"]))

        # -- 9. STEP 3 · Crea ---------------------------------------------
        board.send({"type": "create"})
        st = board.wait_for(lambda m: m.get("event") == "create", what="evento create")
        check(st["stage"]["step"] == "create", "etapa 03 Crea activada", st["stage"]["step"])

        # -- 10. STEP 4 · Llévatelo ----------------------------------------
        board.send({"type": "message", "message": "Hoy comienzo a construir mi futuro"})
        board.wait_for(lambda m: m.get("event") == "message", what="evento message")

        board.send({"type": "take"})
        st = board.wait_for(lambda m: m.get("event") == "take", what="evento take")
        check(st["stage"]["step"] == "take", "etapa 04 Llévatelo activada", st["stage"]["step"])
        check(st["stage"]["message"] == "Hoy comienzo a construir mi futuro",
              "el mensaje personal llega a la pantalla", st["stage"]["message"])

        # -- 11. cold load mid-experience ---------------------------------
        cold = WS(args.host, args.port)
        cold.send({"type": "hello", "role": "stage"})
        cw = cold.wait_for(lambda m: m["type"] == "welcome", what="welcome cold")
        check(cw["stage"]["step"] == "take",
              "COLD LOAD: una pantalla que reinicia reconstruye la etapa",
              str(cw["stage"]["step"]))
        check(cw["stage"]["qrToken"] is not None, "COLD LOAD: conserva el token QR")
        cold.close()

        # -- 12. share opt-in ----------------------------------------------
        board.send({"type": "share", "optIn": True})
        st = board.wait_for(lambda m: m.get("event") == "share", what="evento share")
        check(st["board"]["shareOptIn"] is True, "el opt-in de compartir queda registrado")

        # -- 13. permissions -----------------------------------------------
        stage.send({"type": "enter", "opts": {"name": "Intruso"}})
        err = stage.wait_for(lambda m: m.get("type") == "error", timeout=2.5, what="error de permisos")
        check("no puede" in err.get("error", "").lower(),
              "la pantalla 65\" NO puede comandar la experiencia",
              json.dumps(err))

        # -- 14. operator settings -----------------------------------------
        board.send({"type": "setting", "key": "intensity", "value": 1.8})
        st = board.wait_for(lambda m: m.get("event") == "settings", what="evento settings")
        check(st["stage"]["settings"]["intensity"] == 1.8,
              "el operador puede ajustar la intensidad visual",
              str(st["stage"]["settings"].get("intensity")))

        # -- 15. complete resets the portal ---------------------------------
        board.send({"type": "complete"})
        st = board.wait_for(lambda m: m.get("event") == "complete", what="evento complete")
        check(st["stage"]["step"] == "idle", "tras completar, el portal vuelve a espera",
              st["stage"]["step"])
        check(st["stage"]["participant"] is None or st["stage"]["participant"]["name"] is None,
              "se limpia el participante")
        check(st["stage"]["goal"] is None, "se limpia la meta")

        # -- 16. ping / clock sync ------------------------------------------
        board.send({"type": "ping", "t": time.time()})
        p = board.wait_for(lambda m: m["type"] == "pong", timeout=2.0, what="pong")
        check(abs(p["serverNow"] - time.time()) < 5, "ping/pong para sincronía de reloj")

        print()
        passed = sum(1 for ok, _ in results if ok)
        total = len(results)
        if passed == total:
            print(f"\033[92m\033[1m{passed}/{total} comprobaciones OK\033[0m")
            return 0
        print(f"\033[91m\033[1m{passed}/{total} comprobaciones OK\033[0m")
        for ok, label in results:
            if not ok:
                print(f"  falló: {label}")
        return 1

    finally:
        for c in (stage, board):
            if c:
                c.close()


if __name__ == "__main__":
    sys.exit(main())
