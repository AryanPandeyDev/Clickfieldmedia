<?php
declare(strict_types=1);

/**
 * Builds and sends the enquiry email.
 *
 * mail() on shared hosting fails quietly and for reasons that have nothing to
 * do with the visitor, so this reports whether it worked and leaves the
 * decision about what to do with that to the caller.
 */
final class Mailer
{
    public function __construct(
        private array $config,
        private array $fields,
    ) {
    }

    /** True when the host accepted the message for delivery. */
    public function send(array $data, string $requestId): bool
    {
        // The function can be switched off entirely in a hosting plan without
        // mail, and calling it then is a fatal error rather than a false.
        if (!function_exists('mail')) {
            return false;
        }

        $body = $this->body($data, $requestId);
        $subject = sprintf('%s — %s (%s)', $this->config['subject_prefix'], $data['name'], $data['company']);

        $headers = implode("\r\n", [
            sprintf('From: %s <%s>', $this->encodeName($this->config['from_name']), $this->config['from']),
            // Already validated, so replying in the inbox reaches the lead.
            sprintf('Reply-To: %s <%s>', $this->encodeName($data['name']), $data['email']),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            'X-Request-Id: ' . $requestId,
            'Auto-Submitted: auto-generated',
        ]);

        return @mail(
            $this->config['to'],
            $this->encodeSubject($subject),
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
