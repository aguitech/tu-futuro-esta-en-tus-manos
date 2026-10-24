/* ============================================================
   "Tu futuro está en tus manos" · Motor de efectos (canvas)
   Sin dependencias. 4 efectos del PDF:
     seed         → AHORRAR: una semilla de luz crece entre las manos
     constellation→ PLANEAR: una constelación dibuja el camino a la meta
     wave         → CONOCER: una onda revela información de Afore
     goal         → MI META: el escenario responde a la meta elegida
     explosion    → el momento: juntas las manos y las abres
   ============================================================ */

export const PALETTE = {
  gold:   '#FFD52A',
  goldSoft: '#F7C85A',
  white:  '#FFFFFF',
  blue:   '#1689E5',
  sky:    '#4DA3FF',
  coral:  '#FF8A5B',
  navy:   '#011538',
};

const TAU = Math.PI * 2;

export class FxEngine {
  constructor(canvas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.dpr = Math.min(window.devicePixelRatio || 1, 2);
    this.w = 0;
    this.h = 0;
    this.particles = [];
    this.rings = [];
    this.stars = [];
    this.seed = null;
    this.constellation = null;
    this.wave = null;
    this.goal = null;
    this.intensity = 1;
    this.running = false;
    this._resize();

    // A canvas that is laid out by CSS (inset:0 inside a grid) reports a
    // zero height if we measure before the first layout pass. ResizeObserver
    // fires as soon as the box gets its real size, so the FX never end up
    // rendering into a 1-pixel-tall buffer on the 65" screen.
    if (typeof ResizeObserver !== 'undefined') {
      this._ro = new ResizeObserver(() => this._resize());
      this._ro.observe(this.canvas);
    }
    window.addEventListener('resize', () => this._resize());
  }

  _resize() {
    const r = this.canvas.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return;   // not laid out yet
    const w = Math.round(r.width);
    const h = Math.round(r.height);
    if (w === this.w && h === this.h) return;  // no-op, avoid clearing
    this.w = w;
    this.h = h;
    this.canvas.width = Math.round(this.w * this.dpr);
    this.canvas.height = Math.round(this.h * this.dpr);
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    this._seedStars();
  }

  _seedStars() {
    // A faint starfield gives the dark areas depth.
    const n = Math.round((this.w * this.h) / 26000);
    this.stars = Array.from({ length: n }, () => ({
      x: Math.random() * this.w,
      y: Math.random() * this.h,
      r: Math.random() * 1.4 + 0.3,
      a: Math.random() * 0.5 + 0.1,
      tw: Math.random() * TAU,
    }));
  }

  start() {
    if (this.running) return;
    this.running = true;
    const loop = () => {
      if (!this.running) return;
      this._frame();
      this._raf = requestAnimationFrame(loop);
    };
    this._raf = requestAnimationFrame(loop);
  }

  stop() {
    this.running = false;
    if (this._raf) cancelAnimationFrame(this._raf);
  }

  setIntensity(v) { this.intensity = Math.max(0, Math.min(2, Number(v) || 1)); }

  // ------------------------------------------------------------------
  // Effect triggers
  // ------------------------------------------------------------------

  /** AHORRAR — a seed of light grows between the hands. */
  triggerSeed(data = {}) {
    this.seed = {
      x: (data.x ?? 0.5) * this.w,
      y: (data.y ?? 0.5) * this.h,
      r: 0,
      born: performance.now(),
      energy: data.energy ?? 0,
    };
    this._burst(this.seed.x, this.seed.y, 42, PALETTE.gold, 2.2);
  }

  /** PLANEAR — a constellation draws the path to the goal. */
  triggerConstellation(data = {}) {
    const pts = (data.points || []).map((p) => ({
      x: p.x * this.w,
      y: p.y * this.h,
    }));
    this.constellation = {
      points: pts,
      goal: data.goal,
      progress: 0,
      born: performance.now(),
    };
  }

  /** CONOCER — a wave reveals a brand fact. */
  triggerWave(data = {}) {
    this.wave = {
      text: data.fact || '',
      r: 0,
      born: performance.now(),
    };
  }

  /** MI META — the scene reacts to the chosen life goal. */
  triggerGoal(data = {}) {
    this.goal = {
      goal: data.goal,
      born: performance.now(),
      t: 0,
    };
    const accent = this._accentFor(data.goal);
    this._burst(this.w / 2, this.h / 2, 60, accent, 3.4);
  }

  /** The moment — opening the hands. */
  triggerExplosion(data = {}) {
    const x = (data.x ?? 0.5) * this.w;
    const y = (data.y ?? 0.5) * this.h;
    this._burst(x, y, 150, PALETTE.gold, 6.5);
    this._burst(x, y, 70, PALETTE.white, 4.2);
    this.rings.push({ x, y, r: 0, max: Math.max(this.w, this.h) * 0.9, born: performance.now() });
    this.rings.push({ x, y, r: 0, max: Math.max(this.w, this.h) * 0.65, born: performance.now() + 140 });
  }

  clear() {
    this.seed = null;
    this.constellation = null;
    this.wave = null;
    this.goal = null;
    this.particles.length = 0;
    this.rings.length = 0;
  }

  _accentFor(goal) {
    return ({ viajar: PALETTE.sky, aprender: PALETTE.gold, disfrutar: PALETTE.coral })[goal] || PALETTE.gold;
  }

  _burst(x, y, count, color, speed) {
    const n = Math.round(count * Math.min(this.intensity, 1.8));
    for (let i = 0; i < n; i++) {
      const a = Math.random() * TAU;
      const v = (Math.random() * 0.6 + 0.4) * speed * (0.5 + this.intensity * 0.5);
      this.particles.push({
        x, y,
        vx: Math.cos(a) * v,
        vy: Math.sin(a) * v,
        life: 1,
        decay: 0.006 + Math.random() * 0.012,
        size: Math.random() * 3.4 + 1.1,
        color,
      });
    }
  }

  // ------------------------------------------------------------------
  // Frame
  // ------------------------------------------------------------------

  _frame() {
    const ctx = this.ctx;
    const now = performance.now();

    ctx.clearRect(0, 0, this.w, this.h);

    // --- starfield ---
    ctx.save();
    for (const s of this.stars) {
      const tw = 0.55 + 0.45 * Math.sin(now / 900 + s.tw);
      ctx.globalAlpha = s.a * tw;
      ctx.fillStyle = PALETTE.white;
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, TAU);
      ctx.fill();
    }
    ctx.restore();

    // --- constellation ---
    if (this.constellation) this._drawConstellation(ctx, now);
    // --- goal scene ---
    if (this.goal) this._drawGoal(ctx, now);
    // --- seed ---
    if (this.seed) this._drawSeed(ctx, now);
    // --- wave ---
    if (this.wave) this._drawWave(ctx, now);
    // --- rings (explosion) ---
    this._drawRings(ctx, now);
    // --- particles ---
    this._drawParticles(ctx);
  }

  _drawParticles(ctx) {
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    for (const p of this.particles) {
      p.x += p.vx;
      p.y += p.vy;
      p.vx *= 0.985;
      p.vy *= 0.985;
      p.vy += 0.018;          // slight gravity
      p.life -= p.decay;

      if (p.life <= 0) continue;

      ctx.globalAlpha = Math.max(0, p.life) * 0.9;
      ctx.fillStyle = p.color;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.size * p.life, 0, TAU);
      ctx.fill();
    }
    ctx.restore();

    // reap
    this.particles = this.particles.filter((p) => p.life > 0);
  }

  _drawRings(ctx, now) {
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    for (const r of this.rings) {
      const t = (now - r.born) / 1000;
      if (t < 0) continue;
      const p = Math.min(1, t / 1.3);
      const rad = r.max * (1 - Math.pow(1 - p, 2.2));
      ctx.globalAlpha = (1 - p) * 0.55;
      ctx.strokeStyle = PALETTE.gold;
      ctx.lineWidth = 5 * (1 - p) + 1;
      ctx.beginPath();
      ctx.arc(r.x, r.y, rad, 0, TAU);
      ctx.stroke();
    }
    ctx.restore();
    this.rings = this.rings.filter((r) => now - r.born < 1600);
  }

  _drawSeed(ctx, now) {
    const s = this.seed;
    const age = (now - s.born) / 1000;
    const target = 46 + (s.energy || 0) * 58;
    s.r += (target - s.r) * 0.06;

    ctx.save();
    ctx.globalCompositeOperation = 'lighter';

    // glow
    const g = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, s.r * 2.6);
    g.addColorStop(0, 'rgba(255,213,42,.55)');
    g.addColorStop(0.45, 'rgba(247,200,90,.18)');
    g.addColorStop(1, 'rgba(255,213,42,0)');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(s.x, s.y, s.r * 2.6, 0, TAU);
    ctx.fill();

    // core
    ctx.fillStyle = PALETTE.white;
    ctx.beginPath();
    ctx.arc(s.x, s.y, s.r * 0.34, 0, TAU);
    ctx.fill();

    // orbiting sparks
    for (let i = 0; i < 7; i++) {
      const a = age * 1.5 + (i / 7) * TAU;
      const rr = s.r * (1.15 + 0.12 * Math.sin(age * 3 + i));
      ctx.fillStyle = PALETTE.goldSoft;
      ctx.globalAlpha = 0.75;
      ctx.beginPath();
      ctx.arc(s.x + Math.cos(a) * rr, s.y + Math.sin(a) * rr * 0.82, 2.6, 0, TAU);
      ctx.fill();
    }
    ctx.restore();
  }

  _drawConstellation(ctx, now) {
    const c = this.constellation;
    const age = (now - c.born) / 1000;

    // progressive draw
    c.progress = Math.min(1, age / 2.4);

    ctx.save();
    ctx.globalCompositeOperation = 'lighter';

    const pts = c.points;
    if (pts.length < 2) { ctx.restore(); return; }

    // lines
    ctx.strokeStyle = 'rgba(255,213,42,.45)';
    ctx.lineWidth = 1.6;
    ctx.beginPath();
    const upto = Math.floor(c.progress * (pts.length - 1));
    for (let i = 0; i <= upto; i++) {
      if (i === 0) ctx.moveTo(pts[0].x, pts[0].y);
      else ctx.lineTo(pts[i].x, pts[i].y);
    }
    ctx.stroke();

    // nodes
    for (let i = 0; i <= upto; i++) {
      const p = pts[i];
      const pulse = 0.65 + 0.35 * Math.sin(now / 420 + i);
      const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 16 * pulse);
      g.addColorStop(0, 'rgba(255,255,255,.9)');
      g.addColorStop(1, 'rgba(255,213,42,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(p.x, p.y, 16 * pulse, 0, TAU);
      ctx.fill();

      ctx.fillStyle = PALETTE.white;
      ctx.beginPath();
      ctx.arc(p.x, p.y, 2.4, 0, TAU);
      ctx.fill();
    }
    ctx.restore();
  }

  _drawWave(ctx, now) {
    const w = this.wave;
    const age = (now - w.born) / 1000;
    if (age > 4.2) { this.wave = null; return; }

    const cy = this.h * 0.5;
    const maxR = Math.max(this.w, this.h) * 0.78;

    ctx.save();
    ctx.globalCompositeOperation = 'lighter';

    for (let i = 0; i < 3; i++) {
      const t = ((age * 0.55 + i / 3) % 1);
      const r = t * maxR;
      ctx.globalAlpha = (1 - t) * 0.5;
      ctx.strokeStyle = PALETTE.goldSoft;
      ctx.lineWidth = 3 * (1 - t) + 0.6;
      ctx.beginPath();
      ctx.arc(this.w / 2, cy, r, 0, TAU);
      ctx.stroke();
    }
    ctx.restore();

    // the fact text
    const alpha = Math.min(1, age * 2.2) * (age > 3.6 ? Math.max(0, (4.2 - age) / 0.6) : 1);
    if (alpha > 0 && w.text) {
      ctx.save();
      ctx.globalAlpha = alpha;
      ctx.font = `700 ${Math.round(Math.min(38, this.w * 0.028))}px Montserrat, Inter, sans-serif`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = PALETTE.white;
      ctx.shadowColor = 'rgba(0,0,0,.85)';
      ctx.shadowBlur = 18;

      const words = w.text.split(' ');
      const lines = [];
      let line = '';
      const maxW = this.w * 0.72;
      for (const word of words) {
        const test = line ? `${line} ${word}` : word;
        if (ctx.measureText(test).width > maxW && line) {
          lines.push(line);
          line = word;
        } else {
          line = test;
        }
      }
      if (line) lines.push(line);

      const lh = Math.round(Math.min(38, this.w * 0.028) * 1.45);
      const startY = cy - ((lines.length - 1) * lh) / 2;
      lines.forEach((l, i) => ctx.fillText(l, this.w / 2, startY + i * lh));
      ctx.restore();
    }
  }

  _drawGoal(ctx, now) {
    const g = this.goal;
    const age = (now - g.born) / 1000;
    if (age > 6) { this.goal = null; return; }

    const accent = this._accentFor(g.goal);
    const cx = this.w / 2;
    const cy = this.h / 2;

    ctx.save();
    ctx.globalCompositeOperation = 'lighter';

    // rising light columns — one per goal type
    const cols = g.goal === 'viajar' ? 3 : g.goal === 'aprender' ? 4 : 5;
    for (let i = 0; i < cols; i++) {
      const x = cx + (i - (cols - 1) / 2) * (this.w * 0.13);
      const h = this.h * (0.28 + 0.14 * Math.sin(now / 700 + i));
      const grad = ctx.createLinearGradient(x, cy, x, cy - h);
      grad.addColorStop(0, 'rgba(255,255,255,0)');
      grad.addColorStop(1, accent + '55');
      ctx.fillStyle = grad;
      ctx.globalAlpha = Math.min(1, age * 1.5) * 0.55;
      ctx.fillRect(x - this.w * 0.035, cy - h, this.w * 0.07, h);
    }

    // central halo
    const pulse = 0.7 + 0.3 * Math.sin(now / 480);
    const rad = Math.min(this.w, this.h) * 0.2 * pulse;
    const grad2 = ctx.createRadialGradient(cx, cy, 0, cx, cy, rad);
    grad2.addColorStop(0, accent + 'aa');
    grad2.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.globalAlpha = Math.min(1, age * 1.4) * 0.6;
    ctx.fillStyle = grad2;
    ctx.beginPath();
    ctx.arc(cx, cy, rad, 0, TAU);
    ctx.fill();

    ctx.restore();
  }
}

/**
 * Hand detection WITHOUT external libraries.
 *
 * Uses the browser's own camera + a motion/skin heuristic on a downscaled
 * canvas. This is the "modo touch si falla el seguimiento" continuity path
 * described in the PDF (p.11) — no drivers, no Kinect, works on any laptop.
 *
 * If a Kinect/RealSense is present the operator can swap this module for a
 * native bridge; the portal protocol does not change.
 */
export class HandTracker {
  constructor(video, onUpdate) {
    this.video = video;
    this.onUpdate = onUpdate;
    this.canvas = document.createElement('canvas');
    this.canvas.width = 160;
    this.canvas.height = 120;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    this.prev = null;
    this.energy = 0;
    this.joined = false;
    this.open = false;
    this.active = false;
    this._loop = this._loop.bind(this);
  }

  async start() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
        audio: false,
      });
      this.video.srcObject = stream;
      await this.video.play();
      this.active = true;
      requestAnimationFrame(this._loop);
      return true;
    } catch (e) {
      this.active = false;
      return false;
    }
  }

  stop() {
    this.active = false;
    if (this.video.srcObject) {
      this.video.srcObject.getTracks().forEach((t) => t.stop());
      this.video.srcObject = null;
    }
  }

  _loop() {
    if (!this.active) return;

    const { ctx, canvas } = this;
    try {
      ctx.drawImage(this.video, 0, 0, canvas.width, canvas.height);
    } catch (e) {
      return;
    }

    const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const px = frame.data;
    const w = canvas.width, h = canvas.height;

    // 1) motion energy vs the previous frame
    let motion = 0;
    let sumX = 0, sumY = 0, weight = 0;

    if (this.prev) {
      const prev = this.prev;
      for (let y = 0; y < h; y += 2) {
        for (let x = 0; x < w; x += 2) {
          const i = (y * w + x) * 4;
          const j = (y * w + x) * 4;
          const d =
            Math.abs(px[i] - prev[j]) +
            Math.abs(px[i + 1] - prev[j + 1]) +
            Math.abs(px[i + 2] - prev[j + 2]);
          if (d > 26) {
            motion++;
            sumX += x;
            sumY += y;
            weight++;
          }
        }
      }
    }

    // keep a copy for the next frame
    this.prev = new Uint8ClampedArray(px);

    const motionRatio = motion / ((w / 2) * (h / 2));
    this.energy = Math.max(0, Math.min(1, motionRatio * 5.5));

    // 2) centroid of motion → approximates where the hands are
    let cx = 0.5, cy = 0.5;
    if (weight > 0) {
      cx = sumX / weight / w;
      cy = sumY / weight / h;
    }

    // 3) "joined" = motion concentrated in a tight central region
    this.joined = this.energy > 0.12 && cx > 0.32 && cx < 0.68;

    // 4) "open" = high energy spread wide (hands opening outward)
    this.open = this.energy > 0.5;

    this.onUpdate?.({
      energy: this.energy,
      joined: this.joined,
      open: this.open,
      cx,
      cy,
    });

    requestAnimationFrame(this._loop);
  }
}
