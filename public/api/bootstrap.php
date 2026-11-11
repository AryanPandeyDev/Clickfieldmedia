<?php
declare(strict_types=1);

/**
 * Shared start-up for every endpoint under api/.
 *
 * The important job here is the last one: making sure that however badly a
 * request goes, the caller still receives JSON. A PHP warning printed above
 * the response, or an uncaught error producing a blank 500, both arrive at the
 * browser as unparseable output, and the form can then only say "something
 * went wrong" — which is exactly the failure that is hardest to diagnose.
 */

mb_internal_encoding('UTF-8');

// Errors are for the log, never for the response body.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/lib/Storage.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/Validator.php';
require_once __DIR__ . '/lib/Smtp.php';
require_once __DIR__ . '/lib/Mailer.php';

$config = require __DIR__ . '/config.php';

// Short, unambiguous, and easy to read down a phone line.
$requestId = strtoupper(bin2hex(random_bytes(4)));

$storage = new Storage($config['storage_dir_name']);
$logger = new Logger($storage, $requestId, $config['log_max_bytes']);
$http = new Http($requestId, $config['thank_you']);

/**
 * Anything thrown or fatal from here on is logged with the reference the
 * visitor is shown, then answered as a normal failure.
 */
set_exception_handler(static function (Throwable $e) use ($logger, $http): void {
    $logger->failure('exception', 'Unhandled ' . $e::class, [
        'detail' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    $http->failure(500, 'server_error', 'Something broke on our side while sending this. Please try again in a moment.');
});

/**
 * Warnings and notices are recorded and then stepped over, rather than being
 * promoted to exceptions.
 *
 * Two reasons. The storage and logging code suppresses failures with @ on
 * purpose, because losing a log line must never cost us the enquiry that was
 * being logged; a handler that threw would turn each of those into a 500. And
 * a deprecation introduced by a future PHP upgrade should show up in the log,
 * not take the form down. Anything genuinely fatal is still caught by the
 * shutdown function below.
 */
set_error_handler(static function (int $no, string $message, string $file, int $line) use ($logger): bool {
    // An expression written with @ reports a reduced level; honour it.
    if ((error_reporting() & $no) === 0) {
        return true;
    }

    $logger->failure('php_' . ($no === E_WARNING || $no === E_USER_WARNING ? 'warning' : 'notice'), $message, [
        'where' => basename($file) . ':' . $line,
        'level' => $no,
    ]);

    // Handled here, so PHP's own handler does not also print it.
    return true;
});

register_shutdown_function(static function () use ($logger, $http): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $logger->failure('fatal', $error['message'], [
        'where' => basename($error['file']) . ':' . $error['line'],
    ]);

    // Only speak if nothing has been sent yet; otherwise we would append
    // JSON to a half-written reply and break it further.
    if (!headers_sent()) {
        $http->failure(500, 'server_error', 'Something broke on our side while sending this. Please try again in a moment.');
    }
});
