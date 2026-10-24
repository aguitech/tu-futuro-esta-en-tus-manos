/* ============================================================
   "Tu futuro está en tus manos" · Tablero touch
   Role: 'board'

   The board is what the participant actually touches. It also carries
   the operator actions (invite, advance, close) since in a real
   installation both live on the same console.
   ============================================================ */

import { PortalSocket } from './portal.js?v=1';

const $ = (id) => document.getElementById(id);

const el = {
  controls: $('controls'),
  goals: $('goals'),
  stepNum: $('stepNum'),
  stepName: $('stepName'),
  participantName: $('participantName'),
  chipWs: $('chipWs'),
  chipTrack: $('chipTrack'),
  chipCount: $('chipCount'),
  timerWrap: $('timerWrap'),
  timerVal: $('timerVal'),
  timerBar: $('timerBar'),
  energyBar: $('energyBar'),
  msgInput: $('msgInput'),
  btnShare: $('btnShare'),
  toast: $('toast'),
};

let board = null;
let lastStep = null;
let shareOn = false;

/* Inline SVG icons — no icon font, no external request. */
const ICONS = {
  seed: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round">
    <path d="M32 54V28"/><path d="M32 30C32 18 22 12 10 12c0 12 8 20 22 18Z" fill="rgba(255,213,42,.2)"/>
    <path d="M32 30c0-9 7-14 16-14 0 9-5 15-16 14Z" fill="rgba(255,213,42,.2)"/>
  </svg>`,
  star: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linejoin="round">
    <path d="M32 8l5.5 15.5L54 26l-12 11 3.5 17L32 46 18.5 54 22 37 10 26l16.5-2.5L32 8Z"
          fill="rgba(255,213,42,.18)"/>
  </svg>`,
  wave: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round">
    <path d="M8 32c6-14 12-14 18 0s12 14 18 0 8-10 12-4"/>
    <path d="M8 44c6-10 12-10 18 0s12 10 18 0 8-6 12-2" opacity=".55"/>
  </svg>`,
  goal: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round">
    <circle cx="32" cy="32" r="19"/><circle cx="32" cy="32" r="9" fill="rgba(255,213,42,.22)"/>
    <circle cx="32" cy="32" r="2.6" fill="currentColor"/>
  </svg>`,
  plane: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linejoin="round">
    <path d="M32 6l7 22 19 8-19 8-7 14-7-14-19-8 19-8 7-22Z" fill="rgba(77,163,255,.25)"/>
  </svg>`,
  book: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linejoin="round">
    <path d="M10 14h18a8 8 0 018 8v30a7 7 0 00-7-7H10V14Z" fill="rgba(255,213,42,.2)"/>
    <path d="M54 14H36a8 8 0 00-8 8v30a7 7 0 017-7h19V14Z"/>
  </svg>`,
  sun: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round">
    <circle cx="32" cy="32" r="12" fill="rgba(255,138,91,.25)"/>
    <path d="M32 6v6M32 52v6M6 32h6M52 32h6M14 14l4 4M46 46l4 4M50 14l-4 4M18 46l-4 4"/>
  </svg>`,
};

// ---------------------------------------------------------------------
// Socket
// ---------------------------------------------------------------------
const socket = new PortalSocket({
  role: 'board',
  label: 'tablero-touch',

  onStatus: (s) => { el.chipWs.dataset.on = s === 'online' ? '1' : '0'; },

  onEvent: (msg) => {
    if (msg.type === 'error') {
      toast(msg.error || 'Error');
      return;
    }
    if (msg.type !== 'state') return;

    // Pulse the control that was just pressed.
    if (msg.event === 'press') {
      const node = el.controls.querySelector(`[data-ctl="${msg.data.control}"]`);
      if (node) {
        node.classList.remove('pulse');
        void node.offsetWidth;
        node.classList.add('pulse');
      }
    }
    if (msg.event === 'goal') {
      const node = el.goals.querySelector(`[data-goal="${msg.data.goal}"]`);
      if (node) {
        node.classList.remove('pulse');
        void node.offsetWidth;
        node.classList.add('pulse');
      }
    }
  },

  onState: (stage, b) => {
    board = b || board;
    render(stage, board);
  },
});

// High-frequency hand telemetry from the stage screen.
socket.on('track', (msg) => {
  el.chipTrack.dataset.on = msg.energy > 0.04 ? '1' : '0';
  el.energyBar.style.width = `${((msg.energy || 0) * 100).toFixed(1)}%`;
});

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

function render(stage, b) {
  if (!b) return;

  const step = stage.step || 'idle';
  const meta = STEP_META[step] || STEP_META.idle;
  el.stepNum.textContent = meta.n;
  el.stepName.textContent = meta.name;

  el.participantName.textContent = stage.participant?.name || '—';

  // ---- controls ----
  const activated = stage.activated || [];
  el.controls.innerHTML = (b.controls || []).map((c) => `
    <button class="ctl ${activated.includes(c.id) ? 'on' : ''}" data-ctl="${c.id}">
      <span class="ico">${ICONS[c.icon] || ICONS.star}</span>
      <span class="nm">${c.name}</span>
      <span class="tg">${c.tag}</span>
    </button>`).join('');

  // ---- goals ----
  el.goals.innerHTML = (b.goals || []).map((g) => `
    <button class="goal ${stage.goal === g.id ? 'on' : ''}" data-goal="${g.id}">
      <span class="gico">${ICONS[g.icon] || ICONS.goal}</span>
      <span class="gtxt">
        <span class="gnm">${g.name}</span>
        <span class="gcp">${g.copy}</span>
      </span>
    </button>`).join('');

  // ---- timer ----
  const total = Math.max(1, (stage.deadline - stage.startedAt) * 1000);
  const frac = stage.deadline > 0 ? Math.max(0, Math.min(1, stage.msLeft / total)) : 0;
  el.timerBar.style.width = `${(frac * 100).toFixed(1)}%`;
  el.timerVal.textContent = stage.deadline > 0 ? `${(stage.msLeft / 1000).toFixed(1)}s` : '—';
  el.timerWrap.classList.toggle('urgent', stage.msLeft > 0 && stage.msLeft < 5000 && step === 'create');

  el.btnShare.textContent = shareOn ? 'Compartir: Sí ✓' : 'Compartir: No';

  lastStep = step;
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------
el.controls.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-ctl]');
  if (!btn) return;
  socket.press(btn.dataset.ctl);
});

el.goals.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-goal]');
  if (!btn) return;
  socket.chooseGoal(btn.dataset.goal);
});

$('btnEnter').addEventListener('click', () => {
  const name = prompt('Nombre del participante (opcional):', '') || '';
  socket.enter(name.trim());
  toast('Participante invitado');
});

$('btnCreate').addEventListener('click', () => socket.create());
$('btnTake').addEventListener('click', () => socket.take());
$('btnNext').addEventListener('click', () => {
  socket.complete();
  toast('Sesión cerrada');
});
$('btnSkip').addEventListener('click', () => socket.skipTimer());

$('btnMsg').addEventListener('click', () => {
  socket.setMessage(el.msgInput.value);
  toast('Mensaje actualizado');
});
el.msgInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') $('btnMsg').click();
});

el.btnShare.addEventListener('click', () => {
  shareOn = !shareOn;
  socket.setShare(shareOn);
  toast(shareOn ? 'Acepta compartir' : 'No compartirá');
});

// ---------------------------------------------------------------------
function toast(msg) {
  el.toast.textContent = msg;
  el.toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.toast.classList.remove('show'), 2200);
}

// ---------------------------------------------------------------------
socket.connect();
setInterval(() => socket.ping(), 15000);
