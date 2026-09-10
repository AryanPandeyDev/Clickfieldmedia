<?php
declare(strict_types=1);

/**
 * A read-only look at whether the enquiry API is working, and at what has been
 * failing. Reachable only with the token in config.php.
 *
 * Deliberately narrow: it reports counts, reason codes and the plumbing, and
 * never a name, an address or the contents of a lead. Somebody who guessed the
 * token would learn that submissions were failing, not who sent them.
 */

require_once __DIR__ . '/bootstrap.php';

/** @var array $config */
/** @var Storage $storage */
/** @var Http $http */

$token = (string) ($config['health_token'] ?? '');

// No token configured means the endpoint is switched off. Answer exactly as a
// missing file would, so its existence is not confirmed either way.
if ($token === '') {
    http_response_code(404);
    exit;
}

$given = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');

// Constant-time, so the token cannot be recovered by timing the comparison.
if (!hash_equals($token, $given)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** The last few failures, reduced to what is safe to show. */
$recent = [];
$counts = [];
$logFile = $storage->path('failures.log');

if (is_readable($logFile)) {
    // Read the tail rather than the whole file, which may have grown large.
    $handle = fopen($logFile, 'r');
    $lines = [];
    if ($handle) {
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);
        $read = min($size, 128 * 1024);
        fseek($handle, -$read, SEEK_END);
        $lines = array_filter(explode("\n", (string) fread($handle, $read)));
        fclose($handle);
    }

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry) || !isset($entry['code'])) {
            continue;
        }
        $counts[$entry['code']] = ($counts[$entry['code']] ?? 0) + 1;
        $recent[] = [
            'at' => $entry['at'] ?? null,
            'ref' => $entry['ref'] ?? null,
            'code' => $entry['code'],
            'message' => $entry['message'] ?? null,
        ];
    }

    arsort($counts);
    $recent = array_slice($recent, -25);
}

$leadsFile = $storage->path('leads.csv');

echo json_encode([
    'ok' => true,
    'checked_at' => date('c'),
    'php' => PHP_VERSION,

    // The three things that actually stop this endpoint working.
    'checks' => [
        'mail_function_available' => function_exists('mail'),
        'storage_writable' => is_writable($storage->dir()),
        'storage_outside_web_root' => !$storage->isInsideWebRoot(),
    ],

    'storage' => [
        'dir' => $storage->dir(),
        'leads_recorded' => is_readable($leadsFile)
            // Minus the header row.
            ? max(0, count(file($leadsFile, FILE_SKIP_EMPTY_LINES) ?: []) - 1)
            : 0,
        'failure_log_bytes' => is_readable($logFile) ? filesize($logFile) : 0,
    ],

    'failures_by_reason' => $counts,
    'recent_failures' => $recent,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
