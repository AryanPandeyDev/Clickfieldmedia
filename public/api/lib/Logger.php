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
 *   leads.csv     every accepted enquiry, written before the mail is
 *                 attempted, so neither a mail outage nor a browser giving up
 *                 mid-send can lose one.
 *   incomplete.csv  submissions that were turned away for a missing or
 *                 malformed field, with whatever the visitor had typed. Some
 *                 of them are a real enquiry that gave up at the error.
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
     * Persist a lead. Called before the mail is attempted, so that nothing
     * happening during delivery can cost us the enquiry: a delivery problem is
     * ours to chase rather than the visitor's to retry.
     *
     * $status is written to the fourth column. A bool is the plain emailed or
     * not of a finished attempt; a string carries anything else worth knowing
     * there — 'pending' while the mail is still in flight, or the reason a
     * submission was turned away, for the file of rejected ones.
     */
    public function lead(
        array $data,
        array $fields,
        bool|string $status,
        string $fileName = 'leads.csv',
        string $statusLabel = 'Emailed',
    ): bool {
        $file = $this->storage->path($fileName);
        $isNew = !file_exists($file) || filesize($file) === 0;

        $handle = @fopen($file, 'a');
        if ($handle === false) {
            // The whole lead goes into the context, so an unwritable CSV still
            // leaves it recoverable from failures.log — or, failing that, from
            // the host's PHP error log.
            $this->failure('lead_store_failed', 'Could not write ' . $fileName, ['lead' => $data]);
            return false;
        }

        @flock($handle, LOCK_EX);
        if ($isNew) {
            @fputcsv($handle, array_merge(['Received', 'Reference', 'IP', $statusLabel], array_values($fields)));
        }
        $written = @fputcsv($handle, array_merge(
            [date('c'), $this->requestId, $this->clientIp(), is_string($status) ? $status : ($status ? 'yes' : 'NO')],
            array_map([$this, 'csvSafe'], array_values($data)),
        ));
        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);
        return $written !== false;
    }

    /**
     * Settle the row this request wrote once the mail outcome is known.
     *
     * The row is saved as 'pending' before the mail is attempted and finished
     * here, which is what makes the save unconditional: whatever happens
     * during an SMTP conversation, the lead is already on disk.
     *
     * The file is rewritten in one write under the same exclusive lock rather
     * than patched in place, because a CSV cell has no fixed width and seeking
     * to overwrite one would shift every byte after it. A contact form's CSV
     * is small enough that this costs nothing, and a row left reading
     * 'pending' is still a lead — just one whose delivery we cannot vouch for.
     */
    public function settleMailed(bool $mailed, string $fileName = 'leads.csv'): bool
    {
        $handle = @fopen($this->storage->path($fileName), 'r+');
        if ($handle === false) {
            return false;
        }

        @flock($handle, LOCK_EX);

        $rows = [];
        $found = false;
        while (($row = @fgetcsv($handle)) !== false) {
            // fgetcsv reports a blank line as a single null field.
            if ($row === [null]) {
                continue;
            }
            if (($row[1] ?? '') === $this->requestId && ($row[3] ?? '') === 'pending') {
                $row[3] = $mailed ? 'yes' : 'NO';
                $found = true;
            }
            $rows[] = $row;
        }

        // Built whole before anything is truncated, so a failure part way
        // through writing cannot leave the file short of rows.
        if ($found) {
            $buffer = fopen('php://temp', 'r+');
            foreach ($rows as $row) {
                @fputcsv($buffer, $row);
            }
            rewind($buffer);
            $body = (string) stream_get_contents($buffer);
            fclose($buffer);

            if ($body !== '') {
                @rewind($handle);
                @ftruncate($handle, 0);
                @fwrite($handle, $body);
            }
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
        return $found;
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
