<?php
declare(strict_types=1);

/**
 * A minimal authenticated SMTP client, so mail can go out through a real
 * mailbox (Gmail, Hostinger email) instead of the host's mail() function.
 *
 * mail() on shared hosting hands the message to a local queue and reports
 * success whether or not it is ever delivered, and Gmail routinely drops mail
 * from a domain without SPF/DKIM. Sending through the mailbox's own server
 * avoids both.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    public string $lastError = '';

    public function __construct(private array $config)
    {
    }

    /**
     * $to is one address or several separated by commas. $headers is the header
     * block without To/Subject, lines joined by CRLF. Returns true once the
     * server accepted the message for every recipient.
     */
    public function send(string $to, string $subject, string $body, string $headers): bool
    {
        $this->lastError = '';
        $host = (string) $this->config['host'];
        $port = (int) $this->config['port'];
        $secure = (string) ($this->config['secure'] ?? 'ssl');
        $user = (string) $this->config['username'];

        // One RCPT TO per address; a bare comma-separated string in a single
        // RCPT TO is not valid SMTP and the server refuses the lot.
        $recipients = array_values(array_filter(array_map('trim', explode(',', $to)), static fn (string $a): bool => $a !== ''));

        try {
            if ($recipients === []) {
                throw new RuntimeException('no recipient address configured');
            }

            $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
            $context = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
            $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                throw new RuntimeException("connect {$remote} failed: {$errstr} ({$errno})");
            }
            $this->socket = $socket;
            stream_set_timeout($socket, 30);

            $this->expect([220]);
            $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $this->command($ehlo, [250]);

            if ($secure === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->command($ehlo, [250]);
            }

            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334], 'username');
            $this->command(base64_encode((string) $this->config['password']), [235], 'password');

            $this->command('MAIL FROM:<' . $user . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', [354]);

            $domain = substr(strrchr($user, '@') ?: '@localhost', 1);
            $message = implode("\r\n", [
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
                'To: ' . implode(', ', array_map(static fn (string $a): string => '<' . $a . '>', $recipients)),
                'Subject: ' . $subject,
                $headers,
            ]) . "\r\n\r\n" . $body;

            // CRLF line endings, and a leading dot doubled so it cannot end DATA early.
            $message = str_replace(["\r\n", "\r"], "\n", $message);
            $message = str_replace("\n", "\r\n", $message);
            $message = preg_replace('/^\./m', '..', $message) ?? $message;

            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect([250]);
            @fwrite($socket, "QUIT\r\n");
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            if (is_resource($this->socket)) {
                @fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /** $label replaces the command in errors, so secrets never reach the log. */
    private function command(string $line, array $codes, ?string $label = null): void
    {
        fwrite($this->socket, $line . "\r\n");
        $this->expect($codes, $label ?? explode(' ', $line)[0]);
    }

    private function expect(array $codes, string $after = 'connect'): void
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            // Multi-line replies continue while the fourth character is '-'.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("after {$after}: " . trim($reply ?: 'no reply (timeout)'));
        }
    }
}
