<?php
declare(strict_types=1);

namespace Portal;

/**
 * Estado del portal interactivo.
 *
 * La experiencia del PDF son 4 etapas dentro de 120 segundos:
 *
 *   01 Entra    · 15 s → el portal reconoce al participante
 *   02 Elige    · 25 s → selecciona una meta y activa decisiones
 *   03 Crea     · 50 s → mueve las manos para dirigir la energía
 *   04 Llévatelo· 30 s → pose final, mensaje personal y QR
 *
 * Cada mutación incrementa `rev`. Las pantallas descartan cualquier frame
 * con `rev` <= al último aplicado, así que una entrega desordenada nunca
 * puede rebobinar la experiencia.
 */
final class PortalState
{
    // --- Etapas -------------------------------------------------------
    public const STEP_IDLE      = 'idle';       // esperando participante
    public const STEP_ENTER     = 'enter';      // 01 Entra
    public const STEP_CHOOSE    = 'choose';     // 02 Elige
    public const STEP_CREATE    = 'create';     // 03 Crea
    public const STEP_TAKE      = 'take';       // 04 Llévatelo
    public const STEP_DONE      = 'done';       // participant cerrado

    // --- Controles del tablero ----------------------------------------
    public const BTN_SAVE   = 'save';    // AHORRAR  → semilla de luz
    public const BTN_PLAN   = 'plan';    // PLANEAR  → constelación
    public const BTN_KNOW   = 'know';    // CONOCER  → onda de info
    public const BTN_GOAL   = 'goal';    // MI META  → escena según meta

    public string $step = self::STEP_IDLE;

    /** Participant's chosen life goal. */
    public ?string $goal = null;

    /** Which board controls have been activated this session. */
    public array $activated = [];

    /** Live effect the big screen should be rendering right now. */
    public ?string $effect = null;

    /** Extra payload for the effect (seed position, constellation points…). */
    public array $effectData = [];

    /** Hand-tracking energy level 0..1, driven by the camera. */
    public float $energy = 0.0;

    /** Whether hands are currently detected (true = "junta tus manos"). */
    public bool $handsJoined = false;

    /** Whether hands are open (true = the explosion beat). */
    public bool $handsOpen = false;

    public ?string $participantId = null;
    public ?string $participantName = null;

    /** When the current step's timer expires. */
    public float $deadline = 0.0;
    public float $startedAt = 0.0;

    /** Personal message shown on the postcard. */
    public string $message = 'Hoy comienzo a construir mi futuro';

    /** QR payload for the take-away. */
    public ?string $qrToken = null;

    public bool $shareOptIn = false;

    public int $rev = 0;

    /** Tunables the operator can change live. */
    public array $settings = [
        'stepEnterMs'  => 15000,
        'stepChooseMs' => 25000,
        'stepCreateMs' => 50000,
        'stepTakeMs'   => 30000,
        'intensity'    => 1.0,   // 0..2 visual intensity multiplier
        'effects'      => 1,     // 0 = off, 1 = on
        'camera'       => 1,     // 0 = mirror mode only, 1 = camera live
    ];

    /** Rotating index for the brand facts — keeps CONOCER from repeating. */
    public int $tick = 0;

    public function bump(): int
    {
        return ++$this->rev;
    }

    public function currentRev(): int
    {
        return $this->rev;
    }

    /** Duration of the active step, in ms. */
    public function stepDurationMs(string $step): int
    {
        return match ($step) {
            self::STEP_ENTER  => $this->settings['stepEnterMs'],
            self::STEP_CHOOSE => $this->settings['stepChooseMs'],
            self::STEP_CREATE => $this->settings['stepCreateMs'],
            self::STEP_TAKE   => $this->settings['stepTakeMs'],
            default           => 0,
        };
    }

    /** The 4 board controls, in the order the PDF lists them. */
    public static function controls(): array
    {
        return [
            [
                'id'   => self::BTN_SAVE,
                'name' => 'AHORRAR',
                'icon' => 'seed',
                'tag'  => 'Un hábito empieza hoy',
                'copy' => 'Una semilla de luz crece entre tus manos.',
                'fx'   => 'seed',
            ],
            [
                'id'   => self::BTN_PLAN,
                'name' => 'PLANEAR',
                'icon' => 'star',
                'tag'  => 'Dale dirección a tu futuro',
                'copy' => 'Una constelación dibuja el camino a tu meta.',
                'fx'   => 'constellation',
            ],
            [
                'id'   => self::BTN_KNOW,
                'name' => 'CONOCER',
                'icon' => 'wave',
                'tag'  => 'Conoce tu Afore',
                'copy' => 'Una onda revela información breve sobre tu Afore, validada por la marca.',
                'fx'   => 'wave',
            ],
            [
                'id'   => self::BTN_GOAL,
                'name' => 'MI META',
                'icon' => 'goal',
                'tag'  => 'Tu meta, tu escena',
                'copy' => 'El escenario responde a lo que elegiste: viajar, aprender o disfrutar tu tiempo.',
                'fx'   => 'goal',
            ],
        ];
    }

    /** The life goals a participant can pick in step 02. */
    public static function goals(): array
    {
        return [
            [
                'id'    => 'viajar',
                'name'  => 'VIAJAR',
                'icon'  => 'plane',
                'copy'  => 'Ver el mundo',
                'scene' => 'travel',
                'accent'=> '#4DA3FF',
            ],
            [
                'id'    => 'aprender',
                'name'  => 'APRENDER',
                'icon'  => 'book',
                'copy'  => 'Crecer cada día',
                'scene' => 'learn',
                'accent'=> '#FFD52A',
            ],
            [
                'id'    => 'disfrutar',
                'name'  => 'DISFRUTAR',
                'icon'  => 'sun',
                'copy'  => 'Disfrutar tu tiempo',
                'scene' => 'enjoy',
                'accent'=> '#FF8A5B',
            ],
        ];
    }

    /** Public payload for the 65" screen. */
    public function toStage(): array
    {
        return [
            'rev'       => $this->rev,
            'step'      => $this->step,
            'goal'      => $this->goal,
            'goalData'  => $this->goalData(),
            'activated' => array_keys($this->activated),
            'effect'    => $this->effect,
            'effectData'=> $this->effectData,
            'energy'    => round($this->energy, 3),
            'handsJoined'=> $this->handsJoined,
            'handsOpen' => $this->handsOpen,
            'participant' => [
                'id'   => $this->participantId,
                'name' => $this->participantName,
            ],
            'deadline'  => $this->deadline,
            'startedAt' => $this->startedAt,
            'serverNow' => microtime(true),
            'msLeft'    => $this->deadline > 0
                            ? max(0, (int) round(($this->deadline - microtime(true)) * 1000))
                            : 0,
            'message'   => $this->message,
            'qrToken'   => $this->qrToken,
            'settings'  => $this->settings,
        ];
    }

    /** Payload for the touch board — includes operator-only bits. */
    public function toBoard(): array
    {
        $p = $this->toStage();
        $p['controls'] = self::controls();
        $p['goals']     = self::goals();
        $p['shareOptIn'] = $this->shareOptIn;
        return $p;
    }

    private function goalData(): ?array
    {
        if ($this->goal === null) {
            return null;
        }
        foreach (self::goals() as $g) {
            if ($g['id'] === $this->goal) {
                return $g;
            }
        }
        return null;
    }
}
