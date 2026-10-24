<?php
declare(strict_types=1);

/**
 * "Tu futuro está en tus manos" · WebSocket portal server
 *
 * Single process, single entrypoint. Owns the portal engine and routes
 * incoming client messages to portal verbs.
 *
 * Run:  php server/server.php [--host=0.0.0.0] [--port=8790] [--db=storage/portal.sqlite]
 */

require __DIR__ . '/lib/Frame.php';
require __DIR__ . '/lib/WsConnection.php';
require __DIR__ . '/lib/WsServer.php';
require __DIR__ . '/lib/PortalState.php';
require __DIR__ . '/lib/SessionStore.php';
require __DIR__ . '/lib/PortalEngine.php';

use Portal\PortalEngine;
use Portal\PortalState;
use Portal\SessionStore;
use Portal\Ws\WsConnection;
use Portal\Ws\WsServer;

$opts = getopt('', ['host::', 'port::', 'db::', 'hz::']);

$host   = (string) ($opts['host'] ?? getenv('TF1_HOST') ?: '0.0.0.0');
$port   = (int)    ($opts['port'] ?? getenv('TF1_PORT') ?: 8790);
$dbPath = (string) ($opts['db']   ?? getenv('TF1_DB')   ?: dirname(__DIR__) . '/storage/portal.sqlite');
$tickHz = (int)    ($opts['hz']   ?? getenv('TF1_HZ')   ?: 20);

$store  = new SessionStore($dbPath);
$state  = new PortalState();
$ws     = new WsServer($host, $port, $tickHz);
$engine = new PortalEngine($ws, $state, $store);

$ws->onMessage(function (WsConnection $c, array $msg) use ($engine, $ws): void {
  // ISOLATION: one bad message must never kill the whole show.
  // A live event cannot afford for a single DB hiccup to take down every
  // screen in the venue — so we catch, report, and keep the socket alive.
  try {
    handleMessage($c, $msg, $engine, $ws);
  } catch (Throwable $e) {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
    $c->sendJson([
        'type'  => 'error',
        'error' => 'Error interno del servidor. El operador ha sido notificado.',
    ]);
  }
});

function handleMessage(WsConnection $c, array $msg, PortalEngine $engine, WsServer $ws): void
{
    if ($msg['type'] === '__hello__') {
        $c->sendJson([
            'type' => 'welcome',
            'id'   => $c->id,
            'stage'=> $engine->state()->toStage(),
            'board'=> $engine->state()->toBoard(),
        ]);
        return;
    }

    if ($msg['type'] === '__bye__') {
        return;
    }

    $type = (string) ($msg['type'] ?? '');

    // --- role handshake ------------------------------------------------
    if ($type === 'hello') {
        $role = (string) ($msg['role'] ?? 'unknown');
        if (!in_array($role, ['stage', 'board', 'op'], true)) {
            $role = 'unknown';
        }
        $c->role  = $role;
        $c->label = substr((string) ($msg['label'] ?? ''), 0, 24);

        $c->sendJson([
            'type' => 'welcome',
            'id'   => $c->id,
            'role' => $c->role,
            'stage'=> $engine->state()->toStage(),
            'board'=> $engine->state()->toBoard(),
        ]);

        $ws->broadcast([
            'type'   => 'presence',
            'id'     => $c->id,
            'role'   => $c->role,
            'label'  => $c->label,
            'action' => 'join',
        ], 'stage');

        return;
    }

    // --- camera telemetry (high frequency, from the stage screen) ------
    if ($type === 'track') {
        $engine->track((array) ($msg['opts'] ?? []));
        return;
    }

    // --- ping (clock sync) ---------------------------------------------
    if ($type === 'ping') {
        $c->sendJson(['type' => 'pong', 'clientNow' => $msg['t'] ?? null, 'serverNow' => microtime(true)]);
        return;
    }

    // --- board verbs ----------------------------------------------------
    // The stage (65") is display-only apart from camera telemetry.
    if ($c->role === 'stage') {
        if (in_array($type, ['enter','goal','create','take','press','complete','explode','skip','message','share','setting'], true)) {
            $c->sendJson(['type' => 'error', 'error' => 'La pantalla principal no puede comandar la experiencia.']);
        }
        return;
    }

    switch ($type) {
        case 'enter':
            $engine->enter((array) ($msg['opts'] ?? []));
            break;

        case 'goal':
            $engine->chooseGoal((string) ($msg['goal'] ?? ''));
            break;

        case 'create':
            $engine->create((array) ($msg['opts'] ?? []));
            break;

        case 'take':
            $engine->take((array) ($msg['opts'] ?? []));
            break;

        case 'press':
            $engine->press((string) ($msg['control'] ?? ''), (array) ($msg['opts'] ?? []));
            break;

        case 'explode':
            $engine->explode();
            break;

        case 'complete':
            $engine->complete();
            break;

        case 'skip':
            $engine->skipTimer();
            break;

        case 'message':
            $engine->setMessage((string) ($msg['message'] ?? ''));
            break;

        case 'share':
            $engine->setShareOptIn((bool) ($msg['optIn'] ?? false));
            break;

        case 'setting':
            $engine->setSetting((string) ($msg['key'] ?? ''), $msg['value'] ?? 0);
            break;

        case 'state':
            $c->sendJson([
                'type' => 'state',
                'stage'=> $engine->state()->toStage(),
                'board'=> $engine->state()->toBoard(),
            ]);
            break;

        default:
            $c->sendJson(['type' => 'error', 'error' => "Mensaje desconocido: {$type}"]);
    }
}

// Tick drives the auto-advance between the 4 steps.
$ws->onTick(function () use ($engine): void {
    $engine->tick();
});

if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use ($ws): void {
            fwrite(STDOUT, "\n[ws] señal recibida, cerrando...\n");
            $ws->stop();
        });
    }
}

try {
    $ws->start();
    fwrite(STDOUT, "[portal] listo · 4 etapas · 120 s por participante\n");
    $ws->run();
} catch (Throwable $e) {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
    exit(1);
}
