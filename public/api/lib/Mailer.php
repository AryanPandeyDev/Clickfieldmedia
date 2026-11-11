<?php
declare(strict_types=1);

/**
 * Builds and sends the enquiry email.
 *
 * Sent through the mailbox's own SMTP server when a login is configured (see
 * api/secrets.php), else by mail(). mail() on shared hosting fails quietly and
 * for reasons that have nothing to do with the visitor, so this reports whether
 * it worked and leaves the decision about what to do with that to the caller.
 */
final class Mailer
{
    /** Why the last SMTP attempt failed, for the log. Empty when it did not. */
    public string $lastError = '';

    public function __construct(
        private array $config,
        private array $fields,
        private ?array $smtp = null,
    ) {
    }

    private function usesSmtp(): bool
    {
        return ($this->smtp['host'] ?? '') !== '' && ($this->smtp['password'] ?? '') !== '';
    }

    /** Gmail rewrites any From that is not the signed-in account, so match it. */
    private function fromAddress(): string
    {
        return $this->usesSmtp() ? (string) $this->smtp['username'] : $this->config['from'];
    }

    /** True when the message was accepted for delivery. */
    public function send(array $data, string $requestId): bool
    {
        $body = $this->body($data, $requestId);
        $subject = sprintf('%s — %s (%s)', $this->config['subject_prefix'], $data['name'], $data['company']);

        $headers = implode("\r\n", [
            sprintf('From: %s <%s>', $this->encodeName($this->config['from_name']), $this->fromAddress()),
            // Already validated, so replying in the inbox reaches the lead.
            sprintf('Reply-To: %s <%s>', $this->encodeName($data['name']), $data['email']),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            'X-Request-Id: ' . $requestId,
            'Auto-Submitted: auto-generated',
        ]);

        return $this->deliver($this->config['to'], $this->encodeSubject($subject), $body, $headers);
    }

    /**
     * Through the mailbox when a login is configured, otherwise mail(). If SMTP
     * fails, mail() is still tried so a login problem is not a lost enquiry.
     */
    private function deliver(string $to, string $subject, string $body, string $headers): bool
    {
        $this->lastError = '';

        if ($this->usesSmtp()) {
            $smtp = new Smtp($this->smtp);
            if ($smtp->send($to, $subject, $body, $headers)) {
                return true;
            }
            $this->lastError = $smtp->lastError;
        }

        // mail() can be switched off entirely in a hosting plan without mail,
        // and calling it then is a fatal error rather than a false.
        if (!function_exists('mail')) {
            return false;
        }

        return @mail(
            $to,
            $subject,
            $body,
            $headers,
            // Sets the envelope sender so SPF lines up with the From domain.
            '-f' . $this->config['from'],
        );
    }

    private function body(array $data, string $requestId): string
    {
        $lines = ['New enquiry from the Clickfield Media website.', ''];

        foreach ($this->fields as $key => $label) {
            $lines[] = str_pad($label . ':', 22) . ($data[$key] ?? '');
        }

        $lines[] = '';
        $lines[] = 'Submitted: ' . date('D j M Y, H:i') . ' IST';
        $lines[] = 'Reference: ' . $requestId;
        $lines[] = 'Reply to this email to answer them directly.';

        return implode("\n", $lines);
    }

    /** RFC 2047 for the subject, so rupee signs and dashes survive. */
    private function encodeSubject(string $subject): string
    {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    /**
     * A display name inside a header. Quoted when plain, encoded when it has
     * anything non-ASCII, and stripped of the characters that would let a
     * name close the header and start a new one.
     */
    private function encodeName(string $name): string
    {
        $name = str_replace(["\r", "\n", '"', '<', '>'], '', $name);
        return mb_check_encoding($name, 'ASCII')
            ? '"' . $name . '"'
            : mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    }
}
