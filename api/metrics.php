<?php
declare(strict_types=1);

/**
 * GET  /api/metrics.php                 → aggregate metrics (PDF p.12)
 * GET  /api/metrics.php?action=sessions → recent sessions
 * POST /api/metrics.php?action=advisor  → advisor request from a postcard
 */

require __DIR__ . '/_helpers.php';
require __DIR__ . '/../server/lib/SessionStore.php';

use Portal\SessionStore;

$store = new SessionStore(tf1_db_path());
$action = (string) q('action', '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'sessions') {
    json_out([
        'ok'    => true,
        'items' => $store->recentSessions((int) q('limit', 20)),
    ]);
}

if ($method === 'POST') {
    $body = json_body();
    $token = (string) ($body['token'] ?? q('token', ''));

    if ($token === '') {
        fail('Falta el token de la postal.');
    }
    if (!$store->markAdvisorRequest($token)) {
        fail('Postal no encontrada.', 404);
    }

    json_out(['ok' => true, 'token' => $token]);
}

json_out([
    'ok'      => true,
    'metrics' => $store->metrics(),
]);
