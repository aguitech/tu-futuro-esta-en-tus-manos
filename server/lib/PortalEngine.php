<?php
declare(strict_types=1);

namespace Portal;

use Portal\PortalState;
use Portal\Ws\WsServer;

/**
 * The portal director.
 *
 * Owns the PortalState and turns board verbs into state transitions plus
 * broadcasts. Everything runs in the WebSocket event loop's tick.
 *
 * The 4-step flow is auto-advancing (the timer pushes the experience
 * forward), but the operator can always override with an explicit verb.
 */
final class PortalEngine
{
    private PortalState $state;

    public function __construct(
        private WsServer $ws,
        PortalState $state,
        private SessionStore $store
    ) {
        $this->state = $state;
    }

    public function state(): PortalState { return $this->state; }

    // ------------------------------------------------------------------
    // Session lifecycle
    // ------------------------------------------------------------------

    /**
     * STEP 1 — a participant arrives. The board calls this when the host
     * lets someone in. Generates the QR token for the take-away.
     */
    public function enter(array $opts = []): array
    {
        $name = trim((string) ($opts['name'] ?? ''));
        if ($name === '') {
            $name = 'Participante';
        }
        if (mb_strlen($name) > 24) {
            $name = mb_substr($name, 0, 24);
        }

        $this->state->participantId   = $this->store->createSession($name);
        $this->state->participantName = $name;
        $this->state->goal            = null;
        $this->state->activated       = [];
        $this->state->effect          = null;
        $this->state->effectData      = [];
        $this->state->energy          = 0.0;
        $this->state->handsJoined     = false;
        $this->state->handsOpen       = false;
        $this->state->message         = 'Hoy comienzo a construir mi futuro';
        $this->state->shareOptIn      = false;
        $this->state->qrToken         = $this->store->makeQrToken($this->state->participantId);

        $this->state->step     = PortalState::STEP_ENTER;
        $this->state->startedAt = microtime(true);
        $this->state->deadline  = $this->state->startedAt
                                + ($this->state->stepDurationMs(PortalState::STEP_ENTER) / 1000);

        $this->store->markStep($this->state->participantId, PortalState::STEP_ENTER);

        return $this->commit('enter', [
            'participantId' => $this->state->participantId,
            'name'          => $name,
        ]);
    }

    /**
     * STEP 2 — the participant picks a life goal. The board sends the goal id.
     */
    public function chooseGoal(string $goalId): array
    {
        $valid = array_column(PortalState::goals(), 'id');
        if (!in_array($goalId, $valid, true)) {
            return $this->fail("Meta desconocida: {$goalId}");
        }

        $this->state->goal = $goalId;
        $this->state->step = PortalState::STEP_CHOOSE;
        $this->state->startedAt = microtime(true);
        $this->state->deadline  = $this->state->startedAt
                                + ($this->state->stepDurationMs(PortalState::STEP_CHOOSE) / 1000);

        $this->store->markStep($this->state->participantId, PortalState::STEP_CHOOSE);
        $this->store->setGoal($this->state->participantId, $goalId);

        return $this->commit('choose', ['goal' => $goalId]);
    }

    /**
     * STEP 3 — the "Crea" stage. The participant moves their hands to direct
     * the energy. Energy and hand state stream in continuously; the stage
     * advances when the operator says so (or when the timer expires).
     */
    public function create(array $opts = []): array
    {
        $this->state->step = PortalState::STEP_CREATE;
        $this->state->startedAt = microtime(true);
        $this->state->deadline  = $this->state->startedAt
                                + ($this->state->stepDurationMs(PortalState::STEP_CREATE) / 1000);

        $this->store->markStep($this->state->participantId, PortalState::STEP_CREATE);

        return $this->commit('create', ['goal' => $this->state->goal]);
    }

    /**
     * STEP 4 — the take-away: final pose, personal message, QR.
     */
    public function take(array $opts = []): array
    {
        if (isset($opts['message']) && trim((string) $opts['message']) !== '') {
            $m = trim((string) $opts['message']);
            $this->state->message = mb_strlen($m) > 80 ? mb_substr($m, 0, 80) : $m;
        }

        $this->state->step = PortalState::STEP_TAKE;
        $this->state->startedAt = microtime(true);
        $this->state->deadline  = $this->state->startedAt
                                + ($this->state->stepDurationMs(PortalState::STEP_TAKE) / 1000);

        $this->store->markStep($this->state->participantId, PortalState::STEP_TAKE);

        return $this->commit('take', [
            'message' => $this->state->message,
            'qrToken' => $this->state->qrToken,
        ]);
    }

    /** The participant is done — archive the session and reset the portal. */
    public function complete(): array
    {
        $pid = $this->state->participantId;
        if ($pid !== null) {
            $this->store->completeSession($pid, $this->state->goal);
        }

        $this->state->step            = PortalState::STEP_IDLE;
        $this->state->participantId   = null;
        $this->state->participantName = null;
        $this->state->goal            = null;
        $this->state->activated       = [];
        $this->state->effect          = null;
        $this->state->effectData      = [];
        $this->state->energy          = 0.0;
        $this->state->handsJoined     = false;
        $this->state->handsOpen       = false;
        $this->state->qrToken         = null;
        $this->state->deadline        = 0.0;
        $this->state->startedAt       = 0.0;

        return $this->commit('complete', ['participantId' => $pid]);
    }

    // ------------------------------------------------------------------
    // Board controls → visual effects
    // ------------------------------------------------------------------

    /**
     * A board control was pressed. Maps to the visual effect described in
     * the PDF: AHORRAR→seed, PLANEAR→constellation, CONOCER→wave, MI META→goal.
     */
    public function press(string $controlId, array $opts = []): array
    {
        $map = [
            PortalState::BTN_SAVE => 'seed',
            PortalState::BTN_PLAN => 'constellation',
            PortalState::BTN_KNOW => 'wave',
            PortalState::BTN_GOAL => 'goal',
        ];

        if (!isset($map[$controlId])) {
            return $this->fail("Control desconocido: {$controlId}");
        }

        $fx = $map[$controlId];

        // The seed grows from the participant's hands.
        if ($fx === 'seed') {
            $this->state->effectData = [
                'x' => (float) ($opts['x'] ?? 0.5),
                'y' => (float) ($opts['y'] ?? 0.5),
                'r' => 0,
            ];
        }

        // The constellation draws the path to the goal.
        if ($fx === 'constellation') {
            $this->state->effectData = [
                'goal'  => $this->state->goal,
                'points'=> $this->constellationPoints(),
                't'     => 0,
            ];
        }

        // The wave reveals a brand-approved fact about Afore.
        if ($fx === 'wave') {
            $this->state->effectData = [
                'fact' => $this->brandFact(),
                'r'    => 0,
            ];
        }

        // The goal scene reacts to the chosen life goal.
        if ($fx === 'goal') {
            $this->state->effectData = [
                'goal' => $this->state->goal,
                't'    => 0,
            ];
        }

        $this->state->effect = $fx;
        $this->state->activated[$controlId] = true;

        $this->store->markControl($this->state->participantId, $controlId);

        return $this->commit('press', [
            'control' => $controlId,
            'fx'      => $fx,
            'data'    => $this->state->effectData,
        ]);
    }

    /**
     * Continuous hand-tracking telemetry from the camera on the big screen.
     * High-frequency, so it is sent as a light frame instead of a full state.
     */
    public function track(array $opts = []): array
    {
        $this->state->energy      = max(0.0, min(1.0, (float) ($opts['energy'] ?? 0)));
        $this->state->handsJoined = (bool) ($opts['joined'] ?? false);
        $this->state->handsOpen   = (bool) ($opts['open'] ?? false);

        $this->state->bump();

        // Light frame — no full state, the screens interpolate locally.
        $this->ws->broadcast([
            'type'  => 'track',
            'energy'=> round($this->state->energy, 3),
            'joined'=> $this->state->handsJoined,
            'open'  => $this->state->handsOpen,
            'hands' => $opts['hands'] ?? null,
        ]);

        return ['ok' => true];
    }

    /** The explosion beat — the participant opened their hands. */
    public function explode(): array
    {
        $this->state->effect = 'explosion';
        $this->state->effectData = [
            'x' => 0.5,
            'y' => 0.5,
            't' => 0,
        ];

        return $this->commit('explode', []);
    }

    // ------------------------------------------------------------------
    // Operator controls
    // ------------------------------------------------------------------

    public function setSetting(string $key, $value): array
    {
        if (!array_key_exists($key, $this->state->settings)) {
            return $this->fail("Ajuste desconocido: {$key}");
        }
        $this->state->settings[$key] = is_numeric($value)
            ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
            : $value;

        return $this->commit('settings', ['key' => $key, 'value' => $this->state->settings[$key]]);
    }

    /** Skip the current step's timer. */
    public function skipTimer(): array
    {
        if ($this->state->deadline > 0.0) {
            $this->state->deadline = microtime(true);
        }
        return $this->commit('skip', []);
    }

    public function setMessage(string $message): array
    {
        $m = trim($message);
        if ($m === '') {
            return $this->fail('Mensaje vacío.');
        }
        $this->state->message = mb_strlen($m) > 80 ? mb_substr($m, 0, 80) : $m;
        $this->store->setMessage($this->state->participantId, $this->state->message);

        return $this->commit('message', ['message' => $this->state->message]);
    }

    public function setShareOptIn(bool $on): array
    {
        $this->state->shareOptIn = $on;
        $this->store->setShareOptIn($this->state->participantId, $on);
        return $this->commit('share', ['optIn' => $on]);
    }

    // ------------------------------------------------------------------
    // Tick — auto-advance
    // ------------------------------------------------------------------

    public function tick(): void
    {
        if ($this->state->deadline <= 0.0) {
            return;
        }
        if (microtime(true) < $this->state->deadline) {
            return;
        }

        // Each step rolls into the next one automatically so the experience
        // never stalls waiting for the operator.
        switch ($this->state->step) {
            case PortalState::STEP_ENTER:
                if ($this->state->goal === null) {
                    // No goal picked — the board will pick one, but don't stall.
                    $this->state->goal = 'disfrutar';
                    $this->store->setGoal($this->state->participantId, $this->state->goal);
                }
                $this->create();
                break;

            case PortalState::STEP_CHOOSE:
                $this->create();
                break;

            case PortalState::STEP_CREATE:
                $this->take();
                break;

            case PortalState::STEP_TAKE:
                $this->complete();
                break;
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** 12 deterministic points — the constellation the PDF describes. */
    private function constellationPoints(): array
    {
        $pts = [];
        for ($i = 0; $i < 12; $i++) {
            $angle = ($i / 12) * M_PI * 2;
            $radius = 0.22 + 0.13 * (($i % 3) / 2);
            $pts[] = [
                'x' => 0.5 + cos($angle) * $radius * (0.9 + 0.2 * sin($i)),
                'y' => 0.5 + sin($angle) * $radius * 0.62,
            ];
        }
        return $pts;
    }

    /** Brand-approved Afore facts — validated copy for the CONOCER wave. */
    private function brandFact(): string
    {
        $facts = [
            'Tu Afore es tu dinero para el futuro: crecer, con el tiempo y a tu ritmo.',
            'Con una Afore, cada peso que ahorras trabaja para ti el día que lo necesites.',
            'Tu futuro no se decide hoy: se construye hoy, paso a paso.',
        ];
        $i = ($this->state->tick++) % count($facts);
        return $facts[$i];
    }

    /**
     * Broadcast a state frame. The stage (65") gets the stage payload; the
     * board gets the richer payload including controls and goals.
     */
    private function commit(string $event, array $data): array
    {
        $this->state->bump();

        $this->ws->broadcast([
            'type'  => 'state',
            'event' => $event,
            'data'  => $data,
            'stage' => $this->state->toStage(),
            'board' => $this->state->toBoard(),
        ]);

        return ['ok' => true, 'event' => $event, 'rev' => $this->state->currentRev(), 'data' => $data];
    }

    private function fail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }
}
