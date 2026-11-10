<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL prefix the app is served under ('' at the domain root). */
function app_dir(): string
{
    $configured = config('base_path');
    if ($configured !== null) {
        return rtrim((string) $configured, '/');
    }
    if (PHP_SAPI === 'cli') {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // On Hostinger the root .htaccess forwards every request into /public, so the script lives in
    // /public but visitors never see that folder in the address.
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (str_ends_with($dir, '/public') && !str_starts_with($path, $dir . '/') && $path !== $dir) {
        $dir = substr($dir, 0, -7);
    }
    return $dir;
}

function base_url(): string
{
    $configured = rtrim((string) config('url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . app_dir();
}

/** Internal URL. Falls back to ?r= routing when clean URLs are off. */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $dir = app_dir();
    if (config('clean_urls', true)) {
        return $dir . $path . ($query ? '?' . http_build_query($query) : '');
    }
    return $dir . '/index.php?' . http_build_query(['r' => $path] + $query);
}

function absolute_url(string $path = '/', array $query = []): string
{
    $rel = url($path, $query);
    $dir = app_dir();
    if ($dir !== '' && str_starts_with($rel, $dir)) {
        $rel = substr($rel, strlen($dir));
    }
    return base_url() . $rel;
}

/** Static asset with a cache-busting version. */
function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return app_dir() . '/assets/' . ltrim($path, '/') . $v;
}

function redirect(string $path, int $status = 302): never
{
    $target = preg_match('#^https?://#', $path) || (app_dir() !== '' && str_starts_with($path, app_dir() . '/')) ? $path : url($path);
    header('Location: ' . $target, true, $status);
    exit;
}

function back(): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $refHost = (string) parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '');
    if ($ref !== '' && $refHost === ($_SERVER['HTTP_HOST'] ?? '')) {
        header('Location: ' . $ref);
        exit;
    }
    redirect('/');
}

function wants_json(): bool
{
    return !empty($GLOBALS['__json']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function abort(int $code = 404, string $message = ''): never
{
    http_response_code($code);
    $messages = [
        401 => 'Please sign in.',
        403 => 'You don’t have permission to do that.',
        404 => 'This page isn’t here.',
        405 => 'That action isn’t allowed here.',
        419 => 'Your session expired. Go back, refresh the page and try again.',
        422 => 'Please check the form and try again.',
    ];
    $message = $message ?: ($messages[$code] ?? 'Something went wrong.');
    if (wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }
    echo View::render('error', ['code' => $code, 'message' => $message, 'title' => 'Error ' . $code], 'layouts/plain');
    exit;
}

function view(string $template, array $data = [], ?string $layout = 'layouts/app'): string
{
    return View::render($template, $data, $layout);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The JSON body of an API request (falls back to form fields for multipart uploads). */
function json_input(): array
{
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : null;
        $body = is_array($decoded) ? $decoded : $_POST;
    }
    return $body;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/** Trimmed string or null when empty — for optional DB columns. */
function nullable(mixed $v): ?string
{
    $v = is_string($v) ? trim($v) : $v;
    return ($v === null || $v === '') ? null : (string) $v;
}

/** A Y-m-d date or null. */
function valid_date(mixed $v): ?string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function old(string $key, mixed $default = ''): mixed
{
    return $GLOBALS['__old'][$key] ?? $default;
}

function errors(): array
{
    return $GLOBALS['__errors'] ?? [];
}

function error_for(string $key): string
{
    $err = errors()[$key] ?? null;
    return $err ? '<p class="field-error">' . e($err) . '</p>' : '';
}

function with_input_errors(array $errors, array $input = []): void
{
    unset($input['_csrf'], $input['password'], $input['password_confirm']);
    $_SESSION['_errors'] = $errors;
    $_SESSION['_old'] = $input;
}

function slugify(string $text): string
{
    if (function_exists('iconv')) {
        $text = (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    }
    $text = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '');
    return trim($text, '-') ?: 'org';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '?', 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function avatar(array $user, string $size = ''): string
{
    return '<span class="avatar ' . e($size) . '" style="--c:' . e($user['color'] ?? '#6b7280') . '" title="' . e($user['name']) . '">' . e(initials($user['name'])) . '</span>';
}

function human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

function fmt_date(?string $date, string $format = 'j M Y'): string
{
    return $date ? date($format, strtotime($date)) : '';
}

/** Pleasant avatar colours for new members. */
function random_color(): string
{
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#ca8a04', '#4f46e5', '#dc2626', '#0d9488'];
    return $palette[array_rand($palette)];
}

/** A stable colour per project, for its letter tile in the sidebar. */
function project_color(int $id): string
{
    $palette = ['#5b5bd6', '#0ea5e9', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0d9488', '#ea580c', '#2563eb', '#dc2626'];
    return $palette[$id % count($palette)];
}

function random_password(int $length = 12): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
