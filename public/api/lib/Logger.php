<?php
declare(strict_types=1);

/**
 * Writes what happened to each request.
 *
 * Two separate files, because they answer different questions:
 *
 *   failures.log  every request that did not result in a queued email, one
 *                 JSON object per line, so it can be read with tail or
 *                 filtered with grep without a parser.
 *   leads.csv     every accepted enquiry, so a mail outage never loses one.
 *
 * Each line carries the same short reference that the visitor is shown on
 * screen, which is the point: when someone reports "it said it failed", that
 * reference finds the exact request.
 */
final class Logger
{
    public function __construct(
        private Storage $storage,
        private string $requestId,
        private int $maxBytes,
    ) {
    }

    /** The reference shown to the visitor and stamped on every log line. */
    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * Record a request that failed, was refused, or succeeded only partly.
     *
     * $code is a short machine-readable reason (rate_limited, invalid_field,
     * mail_failed, …) so the log can be counted by category later.
     */
    public function failure(string $code, string $message, array $context = []): void
    {
        $entry = [
            'at' => date('c'),
            'ref' => $this->requestId,
            'code' => $code,
            'message' => $message,
            'ip' => $this->clientIp(),
            'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'origin' => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            'referer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
        ] + $context;

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = $this->storage->append('failures.log', $line . "\n", $this->maxBytes);

        // If even the log is unwritable, fall back to the host's PHP error log
        // so the failure is not lost entirely.
        if (!$written) {
            error_log('clickfield-api ' . $line);
        }
    }

    /**
     * Persist an accepted lead before the mail is attempted, so a delivery
     * problem is ours to chase rather than the visitor's to retry.
     */
    public function lead(array $data, array $fields, bool $mailed): bool
    {
        $file = $this->storage->path('leads.csv');
        $isNew = !file_exists($file) || filesize($file) === 0;

        $handle = @fopen($file, 'a');
        if ($handle === false) {
            $this->failure('lead_store_failed', 'Could not write leads.csv', ['lead' => $data]);
            return false;
        }

        @flock($handle, LOCK_EX);
        if ($isNew) {
            @fputcsv($handle, array_merge(['Received', 'Reference', 'IP', 'Emailed'], array_values($fields)));
        }
        $written = @fputcsv($handle, array_merge(
            [date('c'), $this->requestId, $this->clientIp(), $mailed ? 'yes' : 'NO'],
            array_map([$this, 'csvSafe'], array_values($data)),
        ));
        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);
        return $written !== false;
    }

    /**
     * Neutralise spreadsheet formula injection. A value opening with =, +, -
     * or @ is executed as a formula when leads.csv is opened in Excel or
     * Sheets, so a lead could otherwise run code on the machine that reads it.
     */
    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /** The visitor's address, preferring what the CDN reports. */
    public function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? '';
            if ($value === '') {
                continue;
            }
            // X-Forwarded-For is a chain; the first entry is the client.
            $candidate = trim(explode(',', (string) $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return 'unknown';
    }
}
