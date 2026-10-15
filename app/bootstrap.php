<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

$GLOBALS['__config'] = require BASE_PATH . '/config/app.php';

date_default_timezone_set($GLOBALS['__config']['timezone']);

// PSR-4 style autoloader for the App\ namespace.
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

if ($GLOBALS['__config']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    // Visitors never see errors; command-line tools (install, migrate) always print them.
    ini_set('display_errors', PHP_SAPI === 'cli' ? 'stderr' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
}
