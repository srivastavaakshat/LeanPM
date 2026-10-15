<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal front-controller router.
 * Patterns: {name} matches one segment.
 *
 * Middleware:
 *  - 'guest'  starts the session and checks CSRF on POST (sign-in page)
 *  - 'auth'   session + CSRF + a signed-in user (who has set their own password)
 *  - 'api'    like 'auth', but answers with JSON errors instead of redirects
 * Every route is private; there are no public pages.
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $middleware = ['auth']): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = ['auth']): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $regex = preg_replace_callback('#\{(\w+)\}#', fn ($m) => '(?P<' . $m[1] . '>[^/]+)', rtrim($pattern, '/') ?: '/');
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $middleware];
    }

    public static function currentPath(): string
    {
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            $path = $_GET['r'];
        } else {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $dir = app_dir();
            if ($dir !== '' && str_starts_with($path, $dir)) {
                $path = substr($path, strlen($dir));
            }
            if (str_starts_with($path, '/index.php')) {
                $path = substr($path, 10);
            }
        }
        return '/' . trim(rawurldecode($path), '/');
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = self::currentPath();
        $GLOBALS['__path'] = $path;
        $methodMismatch = false;

        foreach ($this->routes as [$m, $regex, $handler, $middleware]) {
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if (in_array('api', $middleware, true)) {
                $GLOBALS['__json'] = true;
            }
            Session::start();
            header('Cache-Control: no-store');
            if ($method === 'POST') {
                Csrf::verifyOrFail();
            }
            if (in_array('auth', $middleware, true) || in_array('api', $middleware, true)) {
                Auth::enforce(allowPasswordChange: in_array('password', $middleware, true));
            }

            [$class, $action] = $handler;
            $out = (new $class())->$action(...array_values($params));
            if (is_string($out)) {
                echo $out;
            }
            return;
        }

        abort($methodMismatch ? 405 : 404);
    }
}
