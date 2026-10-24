/* ============================================================
   "Tu futuro está en tus manos" · Pantalla principal 65"
   Role: 'stage'

   This screen owns the CAMERA and the hand tracking (the sensor lives
   next to the projector). It pushes telemetry to the server and
   renders the visual effects.

   Green screen by default → ?bg=dark for the control monitor.
   ============================================================ */

import { PortalSocket, drawQR } from './portal.js?v=1';
import { FxEngine, HandTracker, PALETTE } from './fx.js?v=1';

const bg = new URLSearchParams(location.search).get('bg');
if (bg === 'dark') document.body.dataset.bg = 'dark';

const $ = (id) => document.getElementById(id);

const el = {
  cam: $('cam'),
  fxCanvas: $('fx'),
  centre: $('centre'),
  viewIdle: $('viewIdle'),
  viewEnter: $('viewEnter'),
  viewChoose: $('viewChoose'),
  viewCreate: $('viewCreate'),
  viewTake: $('viewTake'),
  viewTakeaway: $('viewTakeaway'),
  enterName: $('enterName'),
  createTitle: $('createTitle'),
  createSub: $('createSub'),
  createHint: $('createHint'),
  energyBar: $('energyBar'),
  takeTitle: $('takeTitle'),
  taGoal: $('taGoal'),
  taQuote: $('taQuote'),
  qrCanvas: $('qrCanvas'),
  participantBar: $('participantBar'),
  pName: $('pName'),
  pMeta: $('pMeta'),
  stepNum: $('stepNum'),
  stepName: $('stepName'),
  stepBar: $('stepBar'),
  chipWs: $('chipWs'),
  chipCam: $('chipCam'),
  chipTrack: $('chipTrack'),
};

const fx = new FxEngine(el.fxCanvas);
fx.start();

let stage = null;
let lastStep = null;
let lastEnergy = 0;
let wasOpen = false;

// ---------------------------------------------------------------------
// Socket
// ---------------------------------------------------------------------
const socket = new PortalSocket({
  role: 'stage',
  label: 'pantalla-65',

  onStatus: (s) => {
    el.chipWs.dataset.on = s === 'online' ? '1' : '0';
  },

  onEvent: (msg) => {
    if (msg.type !== 'state') return;

    const ev = msg.event;
    const d = msg.data || {};

    switch (ev) {
      case 'enter':
        fx.clear();
        el.enterName.textContent = `, ${d.name || ''}`;
        break;

      case 'choose':
        fx.clear();
        break;

      case 'press':
        triggerEffect(d.fx, d.data || {});
        break;

      case 'explode':
        fx.triggerExplosion(d);
        break;

      case 'create':
        fx.clear();
        el.createTitle.textContent = 'Junta tus manos';
        el.createSub.textContent = 'Activa tu futuro. La energía sigue tus movimientos.';
        el.createHint.textContent = 'Mueve tus manos para dirigir la energía';
        break;

      case 'take':
        fx.clear();
        el.takeTitle.textContent = d.message || 'Hoy comienzo a construir mi futuro';
        break;

      case 'complete':
        fx.clear();
        break;
    }
  },

  onState: (s) => {
    stage = s;
    render(s);
  },
});

function triggerEffect(name, data) {
  switch (name) {
    case 'seed':         fx.triggerSeed(data); break;
    case 'constellation':fx.triggerConstellation(data); break;
    case 'wave':         fx.triggerWave(data); break;
    case 'goal':         fx.triggerGoal(data); break;
    case 'explosion':    fx.triggerExplosion(data); break;
  }
}

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
const STEP_META = {
  idle:   { n: '—',  name: 'Esperando' },
  enter:  { n: '01', name: 'Entra' },
  choose: { n: '02', name: 'Elige' },
  create: { n: '03', name: 'Crea' },
  take:   { n: '04', name: 'Llévatelo' },
  done:   { n: '✓',  name: 'Listo' },
};

function render(s) {
  const step = s.step || 'idle';

  // step pill
  const meta = STEP_META[step] || STEP_META.idle;
  el.stepNum.textContent = meta.n;
  el.stepName.textContent = meta.name;

  // which view
  show(el.viewIdle);
  if (step === 'enter')  show(el.viewEnter);
  if (step === 'choose') show(el.viewChoose);
  if (step === 'create') show(el.viewCreate);
  if (step === 'take')   show(el.viewTake);

  // the take-away overlay sits on top of the take view
  if (step === 'take' && s.qrToken) {
    show(el.viewTakeaway);
    el.taGoal.textContent = (s.goalData?.name || 'MI META');
    el.taQuote.textContent = `"${s.message || 'Hoy comienzo a construir mi futuro'}"`;
    const url = `${location.origin}/p/${s.qrToken}`;
    drawQR(el.qrCanvas, url, { size: 5 });
  }

  // participant bar
  if (s.participant?.name) {
    el.participantBar.classList.remove('hidden');
    el.pName.textContent = s.participant.name;
    el.pMeta.textContent = (s.goalData?.name || 'GUADALAJARA');
  } else {
    el.participantBar.classList.add('hidden');
  }

  // goal-specific copy in the "Crea" stage
  if (step === 'create' && s.goalData) {
    const goalCopy = {
      viajar:    ['Dale alas a tu meta', 'Tu constelación dibuja un camino que cruza el mundo'],
      aprender:  ['Tu meta es aprender', 'Cada estrella es un nuevo conocimiento por descubrir'],
      disfrutar: ['Tu meta es disfrutar', 'La energía ilumina el tiempo que te belongs'],
    }[s.goalData.id];
    if (goalCopy) {
      el.createTitle.textContent = goalCopy[0];
      el.createSub.textContent = goalCopy[1];
    }
  }

  fx.setIntensity(s.settings?.intensity ?? 1);

  // timer bar
  const total = Math.max(1, (s.deadline - s.startedAt) * 1000);
  const frac = s.deadline > 0 ? Math.max(0, Math.min(1, s.msLeft / total)) : 0;
  el.stepBar.style.width = `${(frac * 100).toFixed(1)}%`;

  lastStep = step;
}

function show(node) {
  [el.viewIdle, el.viewEnter, el.viewChoose, el.viewCreate, el.viewTake, el.viewTakeaway]
    .forEach((v) => v.classList.add('hidden'));
  node.classList.remove('hidden');
}

// ---------------------------------------------------------------------
// Camera + hand tracking
// ---------------------------------------------------------------------
const tracker = new HandTracker(el.cam, (t) => {
  el.chipTrack.dataset.on = t.energy > 0.04 ? '1' : '0';
  el.energyBar.style.width = `${(t.energy * 100).toFixed(1)}%`;

  // Mirror X because the video is mirrored for the participant.
  const mx = 1 - t.cx;

  // Push telemetry to the server so the board can mirror it.
  socket.track({
    energy: t.energy,
    joined: t.joined,
    open: t.open,
    hands: { x: mx, y: t.cy },
  });

  // When the participant opens their hands → the explosion beat.
  if (t.open && !wasOpen && stage?.step === 'create') {
    socket.explode({ x: mx, y: t.cy });
    fx.triggerExplosion({ x: mx, y: t.cy });
  }
  wasOpen = t.open;
});

tracker.start().then((ok) => {
  el.chipCam.dataset.on = ok ? '1' : '0';
  if (!ok) {
    // Continuity path from the PDF (p.11): no camera → touch-only mode.
    document.body.dataset.nocam = '1';
  }
});

// ---------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------
socket.connect();
setInterval(() => socket.ping(), 15000);

if ('wakeLock' in navigator) {
  const request = () => navigator.wakeLock.request('screen').catch(() => {});
  request();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') request();
  });
}
