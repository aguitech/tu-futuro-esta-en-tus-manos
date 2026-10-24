<?php
declare(strict_types=1);

/**
 * Front controller for local dev (`php -S 127.0.0.1:8090 router.php`).
 *
 * Maps the production URL shapes onto the file layout so dev and prod hit
 * the same paths:
 *   /p/<token>      → api/postcard.php?token=<token>
 *   /api/<file>     → api/<file>.php
 *   /<file>         → public/<file>  (stage.html, board.html, assets/…)
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = urldecode($uri);

// --- health ---
if ($uri === '/health' || $uri === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => true,
        'service' => 'tu-futuro-esta-en-tus-manos',
        'ws'      => 'ws://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ':8790/ws',
        'pages'   => ['/stage.html', '/board.html'],
    ]);
    exit;
}

// --- postcard: /p/<token> ---
if (preg_match('#^/p/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/api/postcard.php';
    exit;
}

// --- API ---
if (preg_match('#^/api/([a-z_]+)\.php$#', $path, $m)) {
    $file = __DIR__ . '/api/' . $m[1] . '.php';
    if (is_file($file)) {
        require $file;
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Endpoint no encontrado']);
    exit;
}

// --- static assets + pages from public/ ---
// The built-in server's docroot is the repo root, so we serve public/*
// ourselves rather than relying on `return false`.
$candidate = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($candidate)) {
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($candidate);
    exit;
}

// --- index → the board (the screen the participant touches) ---
if ($path === '/' || $path === '/index.html') {
    require __DIR__ . '/public/board.html';
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'No encontrado', 'path' => $path]);
