<?php
declare(strict_types=1);

namespace Portal;

/**
 * SQLite store for the portal.
 *
 * Writes into the schema defined in db/schema.sql (the source of truth):
 *   participantes      → the person going through the flow
 *   etapas_visitadas  → event log, one row per stage (NOT a state pointer)
 *   controles_usados  → the 4 board buttons
 *   telemetria_manos  → camera energy samples
 *   postcards         → the digital postcard behind the QR
 *   eventos           → raw system log
 *
 * The public API below is what PortalEngine and the api/ endpoints call.
 * The schema is created by db/init_db.py (python) — this class does NOT
 * create tables, it only reads/writes. If the file is missing, the PHP
 * server will report a clear error instead of silently making its own.
 *
 * Metrics tracked (PDF p.12):
 *   Participación → sessions started / completed, per day and hour
 *   Experiencia  → median duration, drop-off per step, technical failures
 *   Interés      → goals chosen and controls used, in aggregate
 *   Continuidad  → downloads and voluntary advisor requests
 */
final class SessionStore
{
    private ?\PDO $pdo = null;

    /** Stage → ordinal, mirrors etapas_visitadas.etapa_num in schema.sql. */
    private const ETAPA_NUM = [
        'enter'  => 1,
        'choose' => 2,
        'create' => 3,
        'take'   => 4,
        'done'   => 5,
    ];

    /** Board button → visual effect, mirrors controles_usados.efecto. */
    private const CONTROL_FX = [
        'save' => 'seed',
        'plan' => 'constellation',
        'know' => 'wave',
        'goal' => 'goal',
    ];

    public function __construct(private string $dbPath)
    {
        if (!is_file($this->dbPath)) {
            throw new \RuntimeException(
                "No existe la base de datos: {$this->dbPath}\n"
                . "Créala con:  cd db && python3 init_db.py --reset"
            );
        }
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout=4000');
        }
        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    public function createSession(string $name): string
    {
        $id = 'S' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $folio = 'TF1-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $this->pdo()->prepare(
            'INSERT INTO participantes (id, folio, nombre, sede, meta_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $folio,
            $name,
            'GDL',
            json_encode(['origen' => 'websocket'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->logEvent($id, 'session_started', ['name' => $name]);

        return $id;
    }

    public function markStep(?string $sessionId, string $step): void
    {
        if ($sessionId === null) {
            return;
        }

        $num = self::ETAPA_NUM[$step] ?? null;
        if ($num === null) {
            return;
        }

        // Close the previous stage so dwell time is measurable.
        $prev = $this->pdo()->prepare(
            'UPDATE etapas_visitadas
             SET terminada_en = datetime(\'now\'),
                 segundos = CAST((julianday(\'now\') - julianday(iniciada_en)) * 86400 AS INTEGER)
             WHERE participante_id = ? AND terminada_en IS NULL'
        );
        $prev->execute([$sessionId]);

        // UNIQUE(participante_id, etapa) — do not double-log a stage.
        $stmt = $this->pdo()->prepare(
            'INSERT INTO etapas_visitadas (participante_id, etapa, etapa_num)
             VALUES (?, ?, ?)
             ON CONFLICT(participante_id, etapa) DO NOTHING'
        );
        $stmt->execute([$sessionId, $step, $num]);

        $this->logEvent($sessionId, 'step_' . $step);
    }

    public function markControl(?string $sessionId, string $control): void
    {
        if ($sessionId === null) {
            return;
        }

        $fx = self::CONTROL_FX[$control] ?? $control;

        $ord = $this->pdo()->prepare(
            'SELECT COALESCE(MAX(orden), -1) + 1 FROM controles_usados WHERE participante_id = ?'
        );
        $ord->execute([$sessionId]);
        $orden = (int) $ord->fetchColumn();

        $stmt = $this->pdo()->prepare(
            'INSERT INTO controles_usados (participante_id, control, efecto, orden)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(participante_id, control) DO UPDATE SET orden = excluded.orden'
        );
        $stmt->execute([$sessionId, $control, $fx, $orden]);

        $this->logEvent($sessionId, 'control_' . $control, ['efecto' => $fx]);
    }

    public function setGoal(?string $sessionId, string $goal): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE participantes SET meta_id = ? WHERE id = ?');
        $stmt->execute([$goal, $sessionId]);
        $this->logEvent($sessionId, 'goal_' . $goal);
    }

    public function setMessage(?string $sessionId, string $message): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE participantes SET mensaje = ? WHERE id = ?');
        $stmt->execute([$message, $sessionId]);
    }

    public function setShareOptIn(?string $sessionId, bool $on): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE participantes SET compartir = ? WHERE id = ?');
        $stmt->execute([$on ? 1 : 0, $sessionId]);
        $this->logEvent($sessionId, $on ? 'share_optin' : 'share_declined');
    }

    public function completeSession(?string $sessionId, ?string $goal): void
    {
        if ($sessionId === null) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            "UPDATE participantes
             SET estado = 'completado',
                 completada_en = datetime('now'),
                 duracion_ms = CAST((julianday('now') - julianday(iniciada_en)) * 86400000 AS INTEGER),
                 meta_id = COALESCE(?, meta_id)
             WHERE id = ?"
        );
        $stmt->execute([$goal, $sessionId]);

        $this->markStep($sessionId, 'done');
        $this->logEvent($sessionId, 'session_completed', ['goal' => $goal]);
    }

    /** Records a camera energy sample (stage 03 telemetry). */
    public function markTelemetry(
        ?string $sessionId,
        float $energy,
        bool $handsJoined,
        bool $handsOpen
    ): void {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO telemetria_manos
                (participante_id, etapa, energia, manos_juntas, manos_abiertas)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            'create',
            round($energy, 3),
            $handsJoined ? 1 : 0,
            $handsOpen ? 1 : 0,
        ]);
    }

    public function markDownload(string $token): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE postcards SET descargada = descargada + 1 WHERE token = ?'
        );
        $stmt->execute([$token]);

        $row = $this->pdo()->prepare('SELECT participante_id FROM postcards WHERE token = ?');
        $row->execute([$token]);
        $r = $row->fetch();

        if ($r !== false) {
            $u = $this->pdo()->prepare('UPDATE participantes SET descargas = descargas + 1 WHERE id = ?');
            $u->execute([$r['participante_id']]);
            $this->logEvent($r['participante_id'], 'download');
        }

        return $stmt->rowCount() > 0;
    }

    public function markAdvisorRequest(string $token): bool
    {
        $row = $this->pdo()->prepare('SELECT participante_id FROM postcards WHERE token = ?');
        $row->execute([$token]);
        $r = $row->fetch();

        if ($r === false) {
            return false;
        }

        $u = $this->pdo()->prepare('UPDATE participantes SET asesor = asesor + 1 WHERE id = ?');
        $u->execute([$r['participante_id']]);

        $p = $this->pdo()->prepare('UPDATE postcards SET advisor = advisor + 1 WHERE token = ?');
        $p->execute([$token]);

        $this->logEvent($r['participante_id'], 'advisor_request');

        return true;
    }

    // ------------------------------------------------------------------
    // Postcards
    // ------------------------------------------------------------------

    public function makeQrToken(string $sessionId): string
    {
        $token = 'TF' . strtoupper(bin2hex(random_bytes(8)));

        $stmt = $this->pdo()->prepare(
            'INSERT INTO postcards (token, participante_id, mensaje)
             VALUES (?, ?, ?)
             ON CONFLICT(token) DO NOTHING'
        );
        $stmt->execute([$token, $sessionId, 'Hoy comienzo a construir mi futuro']);

        return $token;
    }

    public function postcard(string $token): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT pc.*, pa.nombre AS name, pa.meta_id AS goal, pa.folio
             FROM postcards pc
             LEFT JOIN participantes pa ON pa.id = pc.participante_id
             WHERE pc.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updatePostcard(string $token, string $goal, string $message): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE postcards SET meta_id = ?, mensaje = ? WHERE token = ?'
        );
        $stmt->execute([$goal, $message, $token]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Events + metrics
    // ------------------------------------------------------------------

    public function logEvent(?string $sessionId, string $kind, array $detail = []): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO eventos (participante_id, tipo, detalle) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $kind,
            json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * The aggregate metrics dashboard (PDF p.12), computed in ONE payload
     * so the operator screen does not need 5 round-trips.
     */
    public function metrics(): array
    {
        $pdo = $this->pdo();

        $started   = (int) $pdo->query('SELECT COUNT(*) FROM participantes')->fetchColumn();
        $completed = (int) $pdo->query("SELECT COUNT(*) FROM participantes WHERE estado='completado'")->fetchColumn();
        $active    = (int) $pdo->query("SELECT COUNT(*) FROM participantes WHERE estado IN ('activo','parcial')")->fetchColumn();

        // Median duration (SQLite has no median; compute in PHP).
        $durations = $pdo->query(
            'SELECT duracion_ms FROM participantes WHERE estado = \'completado\' AND duracion_ms > 0
             ORDER BY duracion_ms'
        )->fetchAll(\PDO::FETCH_COLUMN);

        $median = 0;
        if ($durations !== []) {
            $mid = (int) floor(count($durations) / 2);
            $median = (count($durations) % 2 === 0)
                ? (int) round(($durations[$mid - 1] + $durations[$mid]) / 2)
                : (int) $durations[$mid];
        }

        // Per-step drop-off: how many sessions reached each step.
        $stepRows = $pdo->query(
            'SELECT etapa, COUNT(DISTINCT participante_id) AS n
             FROM etapas_visitadas GROUP BY etapa'
        )->fetchAll();

        $byStep = ['enter' => 0, 'choose' => 0, 'create' => 0, 'take' => 0, 'done' => 0];
        foreach ($stepRows as $r) {
            $byStep[(string) $r['etapa']] = (int) $r['n'];
        }

        // Goals chosen (aggregate only — never individual).
        $goalRows = $pdo->query(
            'SELECT meta_id, COUNT(*) AS n FROM participantes
             WHERE meta_id IS NOT NULL GROUP BY meta_id ORDER BY n DESC'
        )->fetchAll();

        // Controls used (aggregate only).
        $ctrlRows = $pdo->query(
            'SELECT control, COUNT(DISTINCT participante_id) AS n FROM controles_usados GROUP BY control'
        )->fetchAll();
        $ctrlCount = [];
        foreach ($ctrlRows as $r) {
            $ctrlCount[(string) $r['control']] = (int) $r['n'];
        }
        arsort($ctrlCount);

        // Continuity.
        $downloads = (int) $pdo->query('SELECT COALESCE(SUM(descargas),0) FROM participantes')->fetchColumn();
        $advisor   = (int) $pdo->query('SELECT COALESCE(SUM(asesor),0) FROM participantes')->fetchColumn();

        // Per-hour capacity (the PDF's 24/hour theoretical figure).
        $perHour = $pdo->query(
            "SELECT strftime('%H', iniciada_en) AS h, COUNT(*) AS n
             FROM participantes GROUP BY h ORDER BY h"
        )->fetchAll();

        $peakHour = null;
        $peakCount = 0;
        foreach ($perHour as $r) {
            if ((int) $r['n'] > $peakCount) {
                $peakCount = (int) $r['n'];
                $peakHour = (string) $r['h'];
            }
        }

        // Technical failures — logged as events with type 'error_*'.
        $failures = (int) $pdo->query(
            "SELECT COUNT(*) FROM eventos WHERE tipo LIKE 'error_%'"
        )->fetchColumn();

        return [
            'participacion' => [
                'sesiones_iniciadas'  => $started,
                'sesiones_completadas'=> $completed,
                'sesiones_activas'    => $active,
                'tasa_completado'     => $started > 0 ? round($completed / $started, 3) : 0.0,
                'por_hora'            => array_map(
                    static fn(array $r): array => ['hora' => $r['h'], 'total' => (int) $r['n']],
                    $perHour
                ),
                'hora_pico'           => $peakHour,
                'maximo_hora_pico'    => $peakCount,
            ],
            'experiencia' => [
                'duracion_mediana_ms' => $median,
                'duracion_mediana_s'  => $median > 0 ? round($median / 1000, 1) : 0,
                'abandono_por_paso'   => $byStep,
                'fallas_tecnicas'     => $failures,
            ],
            'interes' => [
                'metas_elegidas'  => array_map(
                    static fn(array $r): array => ['meta' => $r['meta_id'], 'total' => (int) $r['n']],
                    $goalRows
                ),
                'controles_usados'=> array_map(
                    static fn(string $k, int $v): array => ['control' => $k, 'total' => $v],
                    array_keys($ctrlCount),
                    array_values($ctrlCount)
                ),
            ],
            'continuidad' => [
                'descargas'          => $downloads,
                'solicitudes_asesor' => $advisor,
            ],
        ];
    }

    /** Recent sessions for the operator panel. */
    public function recentSessions(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo()->prepare(
            "SELECT id, folio, nombre AS name, meta_id AS goal, estado AS status,
                    duracion_ms AS duration_ms, iniciada_en AS started_at,
                    completada_en AS completed_at, descargas AS downloads
             FROM participantes ORDER BY iniciada_en DESC LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
