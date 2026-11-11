<?php
declare(strict_types=1);

/**
 * Checks a hiring application: the text fields and the uploaded CV.
 *
 * Same contract as Validator: ['data' => [...]] or ['error' => [...]] naming
 * the first field worth telling the applicant about.
 */
final class ApplicationValidator
{
    private const MESSAGES = [
        'name' => 'Please add your full name.',
        'email' => 'Please add your email address. We’ll send a code to it.',
        'phone' => 'Please add your phone number.',
        'city' => 'Please tell us which city you live in.',
        'experience' => 'Please choose your years of experience.',
        'job_title' => 'Please add your current or most recent job title.',
        'company' => 'Please add the company you work or last worked at.',
        'linkedin' => 'Please add a link to your LinkedIn profile.',
        'portfolio' => 'Please add a link to your showreel or portfolio.',
        'software' => 'Please tick the software you edit on.',
        'laptop' => 'Please tell us whether you have your own editing laptop.',
    ];

    /** First bytes of each accepted file type, so a renamed file is refused. */
    private const SIGNATURES = [
        'pdf' => ['%PDF-'],
        'docx' => ["PK\x03\x04"],
        'doc' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
    ];

    public function __construct(private array $config)
    {
    }

    public function validate(array $input): array
    {
        $data = [];

        foreach ($this->config['fields'] as $key => $label) {
            // A tick-list arrives as name[], so it is read here, before the
            // checks below, which all expect a single string.
            if (isset($this->config['multi'][$key])) {
                $data[$key] = $this->cleanMulti($input[$key] ?? [], $this->config['multi'][$key]);
                if ($data[$key] === '' && !in_array($key, $this->config['optional'], true)) {
                    return $this->error('missing_field', $key, self::MESSAGES[$key] ?? "Please answer the “{$label}” question.");
                }
                continue;
            }

            $raw = $input[$key] ?? '';
            if (!is_string($raw)) {
                return $this->error('malformed_field', $key, "The “{$label}” field was not sent correctly. Please reload the page and try again.");
            }

            $value = $key === 'message' ? $this->cleanMultiline($raw) : $this->clean($raw);

            if ($value === '' && !in_array($key, $this->config['optional'], true)) {
                return $this->error('missing_field', $key, self::MESSAGES[$key] ?? "Please fill in the “{$label}” field.");
            }
            if (mb_strlen(trim($raw)) > $this->config['max_len']) {
                return $this->error('field_too_long', $key, "That “{$label}” is longer than we can accept. Please shorten it.");
            }

            $data[$key] = $value;
        }

        if (!array_key_exists($data['role'], $this->config['roles'])) {
            return $this->error('invalid_role', null, 'This role is no longer open. Please reload the page.');
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->error('invalid_email', 'email', 'That email address does not look right. Please check it.');
        }
        if (preg_match_all('/\d/', $data['phone']) < 7) {
            return $this->error('invalid_phone', 'phone', 'That phone number looks too short. Please include the full number.');
        }
        if (!in_array($data['experience'], $this->config['experience'], true)) {
            return $this->error('invalid_experience', 'experience', 'Please choose your years of experience from the list.');
        }
        if ($data['notice'] !== '' && !in_array($data['notice'], $this->config['notice'], true)) {
            return $this->error('invalid_notice', 'notice', 'Please choose a notice period from the list.');
        }
        foreach ($this->config['choices'] ?? [] as $key => $allowed) {
            if ($data[$key] !== '' && !in_array($data[$key], $allowed, true)) {
                return $this->error('invalid_choice', $key, "Please choose one of the answers offered for “{$this->config['fields'][$key]}”.");
            }
        }

        // A link left blank has already been accepted or refused as a field
        // above, so only one that was filled in is checked here.
        foreach ($this->config['links'] ?? [] as $key => $rule) {
            if ($data[$key] === '') {
                continue;
            }
            if ($rule === 'linkedin' && !$this->isLinkedIn($data[$key])) {
                return $this->error('invalid_linkedin', $key, 'Please paste your LinkedIn profile link, for example https://www.linkedin.com/in/your-name');
            }
            if ($rule === 'any' && !$this->isLink($data[$key])) {
                return $this->error('invalid_link', $key, 'That link does not look right. Please paste the full web address, for example https://vimeo.com/your-reel');
            }
        }

        $data['email'] = strtolower($data['email']);

        return ['data' => $data];
    }

    /**
     * Returns ['file' => [tmp, name, ext]] or ['error' => [...]].
     */
    public function validateCv(mixed $file): array
    {
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return $this->error('missing_cv', 'cv', 'Please upload your CV.');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return $this->error('missing_cv', 'cv', 'Please upload your CV.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $this->error('cv_too_large', 'cv', 'Your CV is larger than 5 MB. Please upload a smaller file.');
            case UPLOAD_ERR_PARTIAL:
                return $this->error('cv_partial', 'cv', 'Your CV did not finish uploading. Please try again.');
            default:
                return $this->error('cv_upload_failed', 'cv', 'Your CV could not be uploaded. Please try again.');
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return $this->error('cv_upload_failed', 'cv', 'Your CV could not be uploaded. Please try again.');
        }

        $size = (int) @filesize($tmp);
        if ($size <= 0) {
            return $this->error('cv_empty', 'cv', 'That file is empty. Please choose your CV again.');
        }
        if ($size > $this->config['cv_max_bytes']) {
            return $this->error('cv_too_large', 'cv', 'Your CV is larger than 5 MB. Please upload a smaller file.');
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::SIGNATURES[$ext])) {
            return $this->error('cv_wrong_type', 'cv', 'Please upload your CV as a PDF or Word file.');
        }

        $head = (string) @file_get_contents($tmp, false, null, 0, 8);
        $matches = false;
        foreach (self::SIGNATURES[$ext] as $signature) {
            if (str_starts_with($head, $signature)) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return $this->error('cv_wrong_type', 'cv', 'That file does not look like a PDF or Word document. Please upload your CV again.');
        }

        return ['file' => ['tmp' => $tmp, 'name' => mb_substr($name, 0, 120), 'ext' => $ext]];
    }

    /** Any ordinary web address: a host with a dot, http(s) or nothing. */
    private function isLink(string $url): bool
    {
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && preg_match('/^[a-z0-9-]+(\\.[a-z0-9-]+)*\\.[a-z]{2,}$/i', $host) === 1;
    }

    /** Only the answers the page offered are kept, and in the order offered. */
    private function cleanMulti(mixed $raw, array $allowed): string
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return '';
        }
        $picked = [];
        foreach ($allowed as $option) {
            if (in_array($option, $raw, true)) {
                $picked[] = $option;
            }
        }
        return implode(', ', $picked);
    }

    private function isLinkedIn(string $url): bool
    {
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && preg_match('/(^|\.)linkedin\.com$/i', $host) === 1;
    }

    private function error(string $code, ?string $field, string $message): array
    {
        return ['error' => ['code' => $code, 'field' => $field, 'message' => $message]];
    }

    /** As Validator::clean. */
    private function clean(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return mb_substr($value, 0, $this->config['max_len']);
    }

    /** Keeps line breaks for the free-text answer; it never goes into a header. */
    private function cleanMultiline(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return mb_substr($value, 0, $this->config['max_len']);
    }
}
