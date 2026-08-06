<?php
declare(strict_types=1);

/**
 * Daybreak bootstrap: autoloader, config, error handling.
 * Included by public/index.php and bin/fetch.php.
 */

define('DB_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Daybreak\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = substr($class, strlen($prefix));
    $path = DB_ROOT . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

\Daybreak\Config::load(DB_ROOT . '/config/.env');

error_reporting(E_ALL);
ini_set('display_errors', \Daybreak\Config::get('APP_DEBUG') === 'true' ? '1' : '0');
// Log PHP errors/warnings ourselves rather than relying on the host's ambient
// php.ini — guarantees server-side visibility regardless of environment.
ini_set('log_errors', '1');
ini_set('error_log', DB_ROOT . '/storage/logs/php-error.log');
date_default_timezone_set('UTC');

// Backstop for anything that escapes every try/catch (e.g. during session/header
// setup, before the front controller's own dispatch try/catch takes over, or in
// CLI scripts). Never leaks details to an HTTP response — always logs server-side.
set_exception_handler(static function (\Throwable $e): void {
    error_log('[daybreak] uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        echo 'Internal error';
    }
});
