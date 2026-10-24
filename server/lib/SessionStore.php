<?php
declare(strict_types=1);

namespace Portal;

/**
 * SQLite store for sessions and the aggregate metrics the PDF asks for.
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

    public function __construct(private string $dbPath)
    {
        $this->migrate();
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $this->pdo = new \PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA busy_timeout=4000');
        }
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id           TEXT PRIMARY KEY,
    name         TEXT,
    goal         TEXT,
    message      TEXT,
    share_opt_in  INTEGER NOT NULL DEFAULT 0,
    qr_token     TEXT,
    started_at   TEXT NOT NULL DEFAULT (datetime('now')),
    completed_at TEXT,
    duration_ms  INTEGER NOT NULL DEFAULT 0,
    last_step    TEXT,
    status       TEXT NOT NULL DEFAULT 'active',
    steps_seen   TEXT NOT NULL DEFAULT '[]',
    controls     TEXT NOT NULL DEFAULT '[]',
    downloads    INTEGER NOT NULL DEFAULT 0,
    advisor_req  INTEGER NOT NULL DEFAULT 0
);
SQL);

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT,
    kind       TEXT NOT NULL,
    detail     TEXT,
    at         TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS postcards (
    token       TEXT PRIMARY KEY,
    session_id  TEXT NOT NULL,
    goal        TEXT,
    message     TEXT,
    scene       TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    downloaded  INTEGER NOT NULL DEFAULT 0
);
SQL);

        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_events_session ON events(session_id)');
        $this->pdo()->exec('CREATE INDEX IF NOT EXISTS idx_events_kind ON events(kind)');
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    public function createSession(string $name): string
    {
        $id = 'S' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $this->pdo()->prepare(
            'INSERT INTO sessions (id, name, steps_seen, controls) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $name, '[]', '[]']);

        $this->logEvent($id, 'session_started', ['name' => $name]);

        return $id;
    }

    public function markStep(?string $sessionId, string $step): void
    {
        if ($sessionId === null) {
            return;
        }

        $stmt = $this->pdo()->prepare('SELECT steps_seen, started_at FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $steps = json_decode((string) $row['steps_seen'], true) ?: [];
        if (!in_array($step, $steps, true)) {
            $steps[] = $step;
        }

        $up = $this->pdo()->prepare('UPDATE sessions SET steps_seen = ?, last_step = ? WHERE id = ?');
        $up->execute([json_encode($steps), $step, $sessionId]);

        $this->logEvent($sessionId, 'step_' . $step);
    }

    public function markControl(?string $sessionId, string $control): void
    {
        if ($sessionId === null) {
            return;
        }

        $stmt = $this->pdo()->prepare('SELECT controls FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $controls = json_decode((string) $row['controls'], true) ?: [];
        if (!in_array($control, $controls, true)) {
            $controls[] = $control;
        }

        $up = $this->pdo()->prepare('UPDATE sessions SET controls = ? WHERE id = ?');
        $up->execute([json_encode($controls), $sessionId]);

        $this->logEvent($sessionId, 'control_' . $control);
    }

    public function setGoal(?string $sessionId, string $goal): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE sessions SET goal = ? WHERE id = ?');
        $stmt->execute([$goal, $sessionId]);
        $this->logEvent($sessionId, 'goal_' . $goal);
    }

    public function setMessage(?string $sessionId, string $message): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE sessions SET message = ? WHERE id = ?');
        $stmt->execute([$message, $sessionId]);
    }

    public function setShareOptIn(?string $sessionId, bool $on): void
    {
        if ($sessionId === null) {
            return;
        }
        $stmt = $this->pdo()->prepare('UPDATE sessions SET share_opt_in = ? WHERE id = ?');
        $stmt->execute([$on ? 1 : 0, $sessionId]);
        $this->logEvent($sessionId, $on ? 'share_optin' : 'share_declined');
    }

    public function completeSession(?string $sessionId, ?string $goal): void
    {
        if ($sessionId === null) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE sessions
             SET status = ?, completed_at = datetime(\'now\'),
                 duration_ms = CAST((julianday(\'now\') - julianday(started_at)) * 86400000 AS INTEGER)
             WHERE id = ?'
        );
        $stmt->execute(['completed', $sessionId]);

        $this->logEvent($sessionId, 'session_completed', ['goal' => $goal]);
    }

    public function markDownload(string $token): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE postcards SET downloaded = downloaded + 1 WHERE token = ?');
        $stmt->execute([$token]);

        $row = $this->pdo()->prepare('SELECT session_id FROM postcards WHERE token = ?');
        $row->execute([$token]);
        $r = $row->fetch();

        if ($r !== false) {
            $u = $this->pdo()->prepare('UPDATE sessions SET downloads = downloads + 1 WHERE id = ?');
            $u->execute([$r['session_id']]);
            $this->logEvent($r['session_id'], 'download');
        }

        return $stmt->rowCount() > 0;
    }

    public function markAdvisorRequest(string $token): bool
    {
        $stmt = $this->pdo()->prepare('SELECT session_id FROM postcards WHERE token = ?');
        $stmt->execute([$token]);
        $r = $stmt->fetch();

        if ($r === false) {
            return false;
        }

        $u = $this->pdo()->prepare('UPDATE sessions SET advisor_req = advisor_req + 1 WHERE id = ?');
        $u->execute([$r['session_id']]);
        $this->logEvent($r['session_id'], 'advisor_request');

        return true;
    }

    // ------------------------------------------------------------------
    // Postcards
    // ------------------------------------------------------------------

    public function makeQrToken(string $sessionId): string
    {
        $token = 'TF' . strtoupper(bin2hex(random_bytes(8)));

        $stmt = $this->pdo()->prepare(
            'INSERT INTO postcards (token, session_id, message) VALUES (?, ?, ?)
             ON CONFLICT(token) DO NOTHING'
        );
        $stmt->execute([$token, $sessionId, 'Hoy comienzo a construir mi futuro']);

        return $token;
    }

    public function postcard(string $token): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT p.*, s.name, s.goal FROM postcards p
             LEFT JOIN sessions s ON s.id = p.session_id
             WHERE p.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updatePostcard(string $token, string $goal, string $message): bool
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE postcards SET goal = ?, message = ? WHERE token = ?'
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
            'INSERT INTO events (session_id, kind, detail) VALUES (?, ?, ?)'
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

        $started   = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $completed = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE status='completed'")->fetchColumn();
        $active    = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE status='active'")->fetchColumn();

        // Median duration (SQLite has no median; compute in PHP).
        $durations = $pdo->query(
            'SELECT duration_ms FROM sessions WHERE status = \'completed\' AND duration_ms > 0
             ORDER BY duration_ms'
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
            "SELECT last_step, COUNT(*) AS n FROM sessions GROUP BY last_step"
        )->fetchAll();

        $byStep = ['enter' => 0, 'choose' => 0, 'create' => 0, 'take' => 0, 'done' => 0];
        foreach ($stepRows as $r) {
            $byStep[(string) $r['last_step']] = (int) $r['n'];
        }

        // Goals chosen (aggregate only — never individual).
        $goalRows = $pdo->query(
            'SELECT goal, COUNT(*) AS n FROM sessions WHERE goal IS NOT NULL GROUP BY goal ORDER BY n DESC'
        )->fetchAll();

        // Controls used (aggregate only).
        $ctrlRows = $pdo->query('SELECT controls FROM sessions')->fetchAll(\PDO::FETCH_COLUMN);
        $ctrlCount = [];
        foreach ($ctrlRows as $json) {
            foreach ((json_decode((string) $json, true) ?: []) as $c) {
                $ctrlCount[$c] = ($ctrlCount[$c] ?? 0) + 1;
            }
        }
        arsort($ctrlCount);

        // Continuity.
        $downloads = (int) $pdo->query('SELECT COALESCE(SUM(downloads),0) FROM sessions')->fetchColumn();
        $advisor   = (int) $pdo->query('SELECT COALESCE(SUM(advisor_req),0) FROM sessions')->fetchColumn();

        // Per-hour capacity (the PDF's 24/hour theoretical figure).
        $perHour = $pdo->query(
            "SELECT strftime('%H', started_at) AS h, COUNT(*) AS n
             FROM sessions GROUP BY h ORDER BY h"
        )->fetchAll();

        $peakHour = null;
        $peakCount = 0;
        foreach ($perHour as $r) {
            if ((int) $r['n'] > $peakCount) {
                $peakCount = (int) $r['n'];
                $peakHour = (string) $r['h'];
            }
        }

        // Technical failures — logged as events with kind 'error_*'.
        $failures = (int) $pdo->query(
            "SELECT COUNT(*) FROM events WHERE kind LIKE 'error_%'"
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
                    static fn(array $r): array => ['meta' => $r['goal'], 'total' => (int) $r['n']],
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
            "SELECT id, name, goal, status, duration_ms, started_at, completed_at, downloads
             FROM sessions ORDER BY started_at DESC LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
