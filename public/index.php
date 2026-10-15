<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header('X-Robots-Tag: noindex, nofollow');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

$router = new Router();
require APP_PATH . '/routes.php';

try {
    $router->dispatch();
} catch (\Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    if (wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => config('debug') ? $e->getMessage() : 'Something went wrong. Please try again.']);
    } elseif (config('debug')) {
        echo '<pre style="padding:24px;font:13px/1.5 ui-monospace,monospace;white-space:pre-wrap">' . e((string) $e) . '</pre>';
    } else {
        echo \App\Core\View::render('error', ['code' => 500, 'message' => 'Something went wrong on our side. Please try again in a minute.', 'title' => 'Error'], 'layouts/plain');
    }
}
