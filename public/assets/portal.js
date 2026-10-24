/* ============================================================
   "Tu futuro está en tus manos" · Shared WebSocket client
   Auto-detects the WS URL, reconnects with backoff.
   ============================================================ */

export class PortalSocket {
  /**
   * @param {object} opts
   * @param {string} opts.role   'stage' | 'board' | 'op'
   * @param {(msg:object)=>void} opts.onState
   * @param {(status:string)=>void} [opts.onStatus]
   * @param {(msg:object)=>void} [opts.onEvent]
   */
  constructor(opts) {
    this.opts = opts;
    this.ws = null;
    this.role = opts.role;
    this.label = opts.label || opts.role;
    this.retry = 0;
    this.lastRev = 0;
    this.closed = false;
    this.handlers = {};
  }

  url() {
    if (window.TF1_WS_URL) return window.TF1_WS_URL;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${proto}//${location.hostname}:8790/ws`;
  }

  /** Register a named handler: on('track', fn) */
  on(name, fn) { this.handlers[name] = fn; return this; }

  connect() {
    if (this.closed) return;
    this.opts.onStatus?.('connecting');

    let ws;
    try {
      ws = new WebSocket(this.url());
    } catch (e) {
      this.#retry();
      return;
    }
    this.ws = ws;

    ws.onopen = () => {
      this.retry = 0;
      this.opts.onStatus?.('online');
      this.send({ type: 'hello', role: this.role, label: this.label });
    };

    ws.onmessage = (ev) => {
      let msg;
      try { msg = JSON.parse(ev.data); } catch { return; }

      // Named handler first (e.g. 'track' — high frequency).
      const h = this.handlers[msg.type];
      if (h) h(msg);

      switch (msg.type) {
        case 'welcome':
          if (msg.stage) this.#apply(msg.stage, msg.board);
          this.opts.onEvent?.(msg);
          break;

        case 'state': {
          // ORDER MATTERS: onEvent runs FIRST so the event handler can stash
          // per-event data before the state render uses it.
          this.opts.onEvent?.(msg);
          this.#apply(msg.stage, msg.board);
          break;
        }

        case 'presence':
        case 'error':
          this.opts.onEvent?.(msg);
          break;
      }
    };

    ws.onclose = () => {
      this.opts.onStatus?.('offline');
      this.#retry();
    };

    ws.onerror = () => { /* onclose follows */ };
  }

  #retry() {
    if (this.closed) return;
    const delay = Math.min(8000, 500 * Math.pow(2, this.retry++));
    setTimeout(() => this.connect(), delay);
  }

  /** Ignore stale frames — an out-of-order delivery can never rewind. */
  #apply(stage, board) {
    if (!stage) return;
    if (typeof stage.rev === 'number') {
      if (stage.rev <= this.lastRev) return;
      this.lastRev = stage.rev;
    }
    this.opts.onState?.(stage, board);
  }

  send(obj) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(obj));
      return true;
    }
    return false;
  }

  // -- board verbs ------------------------------------------------------
  enter(name)            { return this.send({ type: 'enter', opts: { name } }); }
  chooseGoal(goal)       { return this.send({ type: 'goal', goal }); }
  create()               { return this.send({ type: 'create' }); }
  take(opts = {})        { return this.send({ type: 'take', opts }); }
  press(control, opts={}) { return this.send({ type: 'press', control, opts }); }
  explode(opts = {})     { return this.send({ type: 'explode', opts }); }
  complete()             { return this.send({ type: 'complete' }); }
  skipTimer()            { return this.send({ type: 'skip' }); }
  setMessage(msg)        { return this.send({ type: 'message', message: msg }); }
  setShare(optIn)        { return this.send({ type: 'share', optIn }); }
  setSetting(k, v)       { return this.send({ type: 'setting', key: k, value: v }); }
  requestState()         { return this.send({ type: 'state' }); }

  // -- camera telemetry -------------------------------------------------
  track(opts) { return this.send({ type: 'track', opts }); }

  ping() { this.send({ type: 'ping', t: Date.now() / 1000 }); }

  close() {
    this.closed = true;
    this.ws?.close();
  }
}

/**
 * Simple QR generator (no dependency).
 *
 * Renders a real, scannable QR code for the take-away URL. This is a
 * byte-mode QR encoder (version auto, ECC level M) — enough for the
 * short postcards the portal generates.
 */
export function drawQR(canvas, text, opts = {}) {
  const size = opts.size || 6;      // modules per module
  const quiet = opts.quiet ?? 4;

  const qr = encodeQR(text);
  if (!qr) return false;

  const dim = qr.size + quiet * 2;
  const px = size * dim;

  canvas.width = px;
  canvas.height = px;

  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#FFFFFF';
  ctx.fillRect(0, 0, px, px);
  ctx.fillStyle = '#011538';

  for (let y = 0; y < qr.size; y++) {
    for (let x = 0; x < qr.size; x++) {
      if (qr.modules[y * qr.size + x]) {
        ctx.fillRect((x + quiet) * size, (y + quiet) * size, size, size);
      }
    }
  }
  return true;
}

/* --------------------------------------------------------------------
   Minimal QR encoder (byte mode, ECC M, versions 1-10)
   -------------------------------------------------------------------- */

const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);
(function initGF() {
  let x = 1;
  for (let i = 0; i < 255; i++) {
    GF_EXP[i] = x;
    GF_LOG[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
  for (let i = 255; i < 512; i++) GF_EXP[i] = GF_EXP[i - 255];
})();

function gfMul(a, b) {
  if (a === 0 || b === 0) return 0;
  return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

function rsGenPoly(n) {
  let poly = [1];
  for (let i = 0; i < n; i++) {
    const next = new Array(poly.length + 1).fill(0);
    for (let j = 0; j < poly.length; j++) {
      next[j] ^= poly[j];
      next[j + 1] ^= gfMul(poly[j], GF_EXP[i]);
    }
    poly = next;
  }
  return poly;
}

function rsEncode(data, ecLen) {
  const gen = rsGenPoly(ecLen);
  const res = new Array(ecLen).fill(0);
  for (const d of data) {
    const factor = d ^ res[0];
    res.shift();
    res.push(0);
    for (let i = 0; i < ecLen; i++) {
      res[i] ^= gfMul(gen[i + 1], factor);
    }
  }
  return res;
}

/** version -> [total codewords, ec codewords per block, group1 blocks, group1 size, group2 blocks, group2 size] */
const VERSIONS = {
  1:  [26, 10, 1, 26, 0, 0],
  2:  [44, 16, 1, 44, 0, 0],
  3:  [70, 26, 1, 70, 0, 0],
  4:  [100, 18, 2, 50, 0, 0],
  5:  [134, 24, 2, 67, 0, 0],
  6:  [172, 16, 4, 43, 0, 0],
  7:  [196, 18, 4, 49, 0, 0],
  8:  [242, 22, 2, 60, 2, 61],
  9:  [292, 22, 3, 58, 2, 59],
  10: [346, 26, 4, 69, 1, 70],
};

function alignPatternPos(v) {
  if (v === 1) return [];
  const n = v - 1;
  const size = v * 4 + 17;
  const first = 6;
  const step = Math.ceil((size - 13) / (n - 1)) * 2;
  const out = [];
  for (let i = 0; i < n; i++) out.push(first + i * step);
  return out;
}

function encodeQR(text) {
  const bytes = new TextEncoder().encode(text);

  // pick the smallest version that fits
  let version = 0;
  for (let v = 1; v <= 10; v++) {
    const [total, ecPerBlock] = VERSIONS[v];
    const dataCodewords = total - ecPerBlock * (total / total); // placeholder, computed below
    const blocks = VERSIONS[v][2] + VERSIONS[v][4];
    const ecTotal = VERSIONS[v][1] * blocks;
    const dataCap = total - ecTotal;
    const lenBits = v < 10 ? 8 : 16;
    const need = Math.ceil((4 + lenBits + bytes.length * 8) / 8);
    if (need <= dataCap) { version = v; break; }
  }
  if (!version) return null;

  const [totalCodewords, ecPerBlock, g1Blocks, g1Size, g2Blocks, g2Size] = VERSIONS[version];
  const blocks = g1Blocks + g2Blocks;
  const dataCodewords = totalCodewords - ecPerBlock * blocks;

  // --- bit stream ---
  const bits = [];
  const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); };

  push(0b0100, 4);                       // byte mode
  push(bytes.length, version < 10 ? 8 : 16);
  for (const b of bytes) push(b, 8);

  const capBits = dataCodewords * 8;
  for (let i = 0; i < 4 && bits.length < capBits; i++) bits.push(0);
  while (bits.length % 8 !== 0) bits.push(0);

  const dataBytes = [];
  for (let i = 0; i < bits.length; i += 8) {
    let b = 0;
    for (let j = 0; j < 8; j++) b = (b << 1) | bits[i + j];
    dataBytes.push(b);
  }
  const padBytes = [0xec, 0x11];
  let pi = 0;
  while (dataBytes.length < dataCodewords) {
    dataBytes.push(padBytes[pi++ % 2]);
  }

  // --- split into blocks + ecc ---
  const dataBlocks = [];
  const ecBlocks = [];
  let offset = 0;
  for (let i = 0; i < blocks; i++) {
    const sz = i < g1Blocks ? g1Size : g2Size;
    const blk = dataBytes.slice(offset, offset + sz);
    offset += sz;
    dataBlocks.push(blk);
    ecBlocks.push(rsEncode(blk, ecPerBlock));
  }

  // --- interleave ---
  const finalData = [];
  const maxData = Math.max(...dataBlocks.map((b) => b.length));
  for (let i = 0; i < maxData; i++) {
    for (const b of dataBlocks) if (i < b.length) finalData.push(b[i]);
  }
  const finalEC = [];
  for (let i = 0; i < ecPerBlock; i++) {
    for (const b of ecBlocks) finalEC.push(b[i]);
  }
  const all = finalData.concat(finalEC);

  // --- build the matrix ---
  const size = version * 4 + 17;
  const mod = new Uint8Array(size * size);
  const reserved = new Uint8Array(size * size);

  const setFn = (x, y, v) => {
    if (x < 0 || y < 0 || x >= size || y >= size) return;
    mod[y * size + x] = v ? 1 : 0;
    reserved[y * size + x] = 1;
  };

  // finder patterns
  const finder = (ox, oy) => {
    for (let y = -1; y <= 7; y++) {
      for (let x = -1; x <= 7; x++) {
        const inner = (x >= 0 && x <= 6 && (y === 0 || y === 6)) ||
                      (y >= 0 && y <= 6 && (x === 0 || x === 6)) ||
                      (x >= 2 && x <= 4 && y >= 2 && y <= 4);
        setFn(ox + x, oy + y, inner ? 1 : 0);
      }
    }
  };
  finder(0, 0);
  finder(size - 7, 0);
  finder(0, size - 7);

  // timing patterns
  for (let i = 8; i < size - 8; i++) {
    setFn(i, 6, i % 2 === 0 ? 1 : 0);
    setFn(6, i, i % 2 === 0 ? 1 : 0);
  }

  // alignment patterns
  const ap = alignPatternPos(version);
  for (const ay of ap) {
    for (const ax of ap) {
      if ((ax <= 8 && ay <= 8) || (ax <= 8 && ay >= size - 9) || (ax >= size - 9 && ay <= 8)) continue;
      for (let y = -2; y <= 2; y++) {
        for (let x = -2; x <= 2; x++) {
          const on = Math.max(Math.abs(x), Math.abs(y)) !== 1;
          setFn(ax + x, ay + y, on ? 1 : 0);
        }
      }
    }
  }

  // dark module + format info areas reserved
  setFn(8, size - 8, 1);
  for (let i = 0; i < 9; i++) {
    if (i !== 6) { reserved[i * size + 8] = 1; reserved[8 * size + i] = 1; }
  }
  for (let i = 0; i < 8; i++) {
    reserved[8 * size + (size - 1 - i)] = 1;
    reserved[(size - 1 - i) * size + 8] = 1;
  }

  // version info (v >= 7)
  if (version >= 7) {
    let rem = version;
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const bitsV = (version << 12) | rem;
    for (let i = 0; i < 18; i++) {
      const bit = (bitsV >>> i) & 1;
      const a = Math.floor(i / 3);
      const b = (i % 3) + size - 11;
      setFn(b, a, bit);
      setFn(a, b, bit);
    }
  }

  // --- place data ---
  let bitIdx = 0;
  const dataBits = [];
  for (const b of all) for (let i = 7; i >= 0; i--) dataBits.push((b >> i) & 1);

  let up = true;
  for (let col = size - 1; col > 0; col -= 2) {
    if (col === 6) col = 5;
    for (let i = 0; i < size; i++) {
      const row = up ? size - 1 - i : i;
      for (const c of [col, col - 1]) {
        if (reserved[row * size + c]) continue;
        const bit = bitIdx < dataBits.length ? dataBits[bitIdx++] : 0;
        mod[row * size + c] = bit;
      }
    }
    up = !up;
  }

  // --- mask (pattern 0) + format info ---
  const maskFn = (x, y) => (x + y) % 2 === 0;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (reserved[y * size + x]) continue;
      if (maskFn(x, y)) mod[y * size + x] ^= 1;
    }
  }

  // format info: ECC level M = 0b00, mask 0 = 0b000
  const fmt = (0b00 << 3) | 0;
  let fmtBits = fmt << 10;
  for (let i = 4; i >= 0; i--) {
    if ((fmtBits >>> i) & 1) fmtBits ^= 0b10100110111;
  }
  const formatBits = ((fmt << 10) | fmtBits) ^ 0b101010000010010;

  for (let i = 0; i <= 5; i++) mod[i * size + 8] = (formatBits >>> i) & 1;
  mod[7 * size + 8] = (formatBits >>> 6) & 1;
  mod[8 * size + 8] = (formatBits >>> 7) & 1;
  mod[8 * size + 7] = (formatBits >>> 8) & 1;
  for (let i = 9; i < 15; i++) mod[8 * size + (14 - i)] = (formatBits >>> i) & 1;

  for (let i = 0; i < 8; i++) mod[(size - 1 - i) * size + 8] = (formatBits >>> i) & 1;
  for (let i = 8; i < 15; i++) mod[8 * size + (15 - i - 1)] = (formatBits >>> i) & 1;
  mod[(size - 8) * size + 8] = 1;

  return { size, modules: mod };
}
