<?php
declare(strict_types=1);

/**
 * The two emails behind the hiring form: the 6-digit code sent to the
 * applicant, and the finished application, CV attached, sent to the inbox.
 *
 * Each method reports whether the message was accepted: by the mailbox's SMTP
 * server when a login is configured (see api/secrets.php), else by mail().
 */
final class HiringMailer
{
    /** Why the last SMTP attempt failed, for the log. Empty when it did not. */
    public string $lastError = '';

    public function __construct(
        private array $config,
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

    /**
     * Through the mailbox when a login is configured, otherwise mail(). If
     * SMTP fails, mail() is still tried so a login problem is not an outage.
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
        if (!function_exists('mail')) {
            return false;
        }
        return @mail($to, $subject, $body, $headers, '-f' . $this->config['from']);
    }

    /** The code email. Short, so the code is readable in a notification preview. */
    public function sendCode(string $email, string $name, string $code, int $ttlMinutes): bool
    {
        $first = explode(' ', $name)[0];
        $body = implode("\n", [
            "Hi {$first},",
            '',
            "Your Clickfield Media verification code is: {$code}",
            '',
            "Enter it on the application page to send your application. It expires in {$ttlMinutes} minutes.",
            '',
            'If you did not apply for a job at Clickfield Media, you can ignore this email.',
        ]);

        $headers = implode("\r\n", [
            sprintf('From: %s <%s>', $this->encodeName($this->config['from_name']), $this->fromAddress()),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ]);

        return $this->deliver($email, $this->encodeSubject("{$code} is your Clickfield Media verification code"), $body, $headers);
    }

    /**
     * The application itself. Sent as multipart/mixed so the CV arrives as a
     * normal attachment. Reply-To is the applicant, so replying reaches them.
     */
    public function sendApplication(array $data, array $fields, string $cvPath, string $cvName, string $requestId): bool
    {
        $cv = @file_get_contents($cvPath);
        if ($cv === false) {
            return false;
        }

        $boundary = 'cfm-' . bin2hex(random_bytes(12));
        $subject = sprintf('%s — %s (%s)', $this->config['subject_prefix'], $data['name'], $data['role']);

        $headers = implode("\r\n", [
            sprintf('From: %s <%s>', $this->encodeName($this->config['from_name']), $this->fromAddress()),
            sprintf('Reply-To: %s <%s>', $this->encodeName($data['name']), $data['email']),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-Request-Id: ' . $requestId,
            'Auto-Submitted: auto-generated',
        ]);

        $safeName = $this->attachmentName($cvName);
        $body = implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($this->body($data, $fields, $requestId))),
            '--' . $boundary,
            'Content-Type: ' . $this->mimeType($safeName) . '; name="' . $safeName . '"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="' . $safeName . '"',
            '',
            chunk_split(base64_encode($cv)),
            '--' . $boundary . '--',
            '',
        ]);

        return $this->deliver($this->config['to'], $this->encodeSubject($subject), $body, $headers);
    }

    /** $fields is the listing's own label map, so each role reads its own way. */
    private function body(array $data, array $fields, string $requestId): string
    {
        $lines = ['New job application from clickfieldmedia.com.', 'The applicant confirmed their email address with a code.', ''];

        foreach ($fields as $key => $label) {
            if ($key === 'message') {
                continue;
            }
            $lines[] = str_pad($label . ':', 20) . (($data[$key] ?? '') !== '' ? $data[$key] : '—');
        }

        $lines[] = '';
        $lines[] = ($fields['message'] ?? 'Message') . ':';
        $lines[] = ($data['message'] ?? '') !== '' ? $data['message'] : '—';
        $lines[] = '';
        $lines[] = 'CV attached.';
        $lines[] = 'Submitted: ' . date('D j M Y, H:i') . ' IST';
        $lines[] = 'Reference: ' . $requestId;
        $lines[] = 'Reply to this email to answer the applicant directly.';

        return implode("\n", $lines);
    }

    /** ASCII only, no quotes or path parts, keeps the extension. */
    private function attachmentName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $base) ?? '';
        $base = trim(substr($base, 0, 80)) ?: 'CV';
        return $base . '.' . $ext;
    }

    private function mimeType(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    private function encodeSubject(string $subject): string
    {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    private function encodeName(string $name): string
    {
        $name = str_replace(["\r", "\n", '"', '<', '>'], '', $name);
        return mb_check_encoding($name, 'ASCII')
            ? '"' . $name . '"'
            : mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    }
}
