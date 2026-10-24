#!/usr/bin/env python3
"""Live driver: connects as participant + board over WS, then holds the
session open so the browser stage can be watched reacting in real time."""
import base64, json, os, socket, struct, sys, time

HOST, PORT = "127.0.0.1", 8790


def connect():
    s = socket.create_connection((HOST, PORT), timeout=10)
    key = base64.b64encode(os.urandom(16)).decode()
    s.sendall((
        "GET /ws HTTP/1.1\r\n"
        f"Host: {HOST}:{PORT}\r\n"
        "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
    ).encode())
    buf = b""
    while b"\r\n\r\n" not in buf:
        buf += s.recv(4096)
    assert b"101" in buf.split(b"\r\n")[0], buf[:120]
    return s, buf.split(b"\r\n\r\n", 1)[1]


def send(s, obj):
    data = json.dumps(obj).encode()
    hdr = bytearray([0x81])
    n = len(data)
    if n < 126:
        hdr.append(0x80 | n)
    elif n < 65536:
        hdr.append(0x80 | 126)
        hdr += struct.pack("!H", n)
    else:
        hdr.append(0x80 | 127)
        hdr += struct.pack("!Q", n)
    mask = os.urandom(4)
    hdr += mask
    s.sendall(bytes(hdr) + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))


def recv(s, buf=b"", timeout=3.0):
    s.settimeout(timeout)
    out = []
    while True:
        try:
            while len(buf) < 2:
                buf += s.recv(4096)
            b1, b2 = buf[0], buf[1]
            ln = b2 & 0x7F
            off = 2
            if ln == 126:
                while len(buf) < 4:
                    buf += s.recv(4096)
                ln = struct.unpack("!H", buf[2:4])[0]
                off = 4
            elif ln == 127:
                while len(buf) < 10:
                    buf += s.recv(4096)
                ln = struct.unpack("!Q", buf[2:10])[0]
                off = 10
            while len(buf) < off + ln:
                buf += s.recv(4096)
            payload = buf[off:off + ln]
            buf = buf[off + ln:]
            if b1 & 0x0F == 1:
                out.append(json.loads(payload.decode()))
        except (socket.timeout, TimeoutError):
            break
        except Exception as e:
            print("recv err", e)
            break
    return out, buf


def main():
    dur = float(sys.argv[1]) if len(sys.argv) > 1 else 60
    # participant / board client
    b_sock, buf = connect()
    send(b_sock, {"type": "hello", "role": "board", "label": "tablero"})
    msgs, buf = recv(b_sock, buf, 2)
    print("[board] conectado:", [m.get("type") for m in msgs])

    send(b_sock, {"type": "enter", "name": "Hector"})
    msgs, buf = recv(b_sock, buf, 2)
    print("[board] tras enter:", [m.get("type") for m in msgs])

    send(b_sock, {"type": "goal", "goal": "viajar"})
    msgs, buf = recv(b_sock, buf, 2)
    print("[board] tras goal:", [m.get("type") for m in msgs])

    send(b_sock, {"type": "press", "control": "plan"})
    msgs, buf = recv(b_sock, buf, 2)
    for m in msgs:
        if m.get("type") == "state":
            print("[board] efectos:", m.get("effects") or m.get("activated"))
    print("[board] tras press:", [m.get("type") for m in msgs])

    print(f"[board] session viva {dur}s — mira la stage en el navegador")
    end = time.time() + dur
    while time.time() < end:
        send(b_sock, {"type": "ping", "t": time.time()})
        msgs, buf = recv(b_sock, buf, 1)
        time.sleep(0.5)
    print("[board] cerrado")


if __name__ == "__main__":
    main()
