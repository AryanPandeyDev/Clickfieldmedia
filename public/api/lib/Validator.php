<?php
declare(strict_types=1);

/**
 * Turns raw POST input into either a clean set of values or the first problem
 * worth telling the visitor about.
 *
 * Error copy is written to be actionable on screen: it names the field in the
 * words used on the form and says what is wrong, rather than "invalid input".
 *
 * Every field is cleaned before any of them is judged, so a rejected
 * submission still comes back with everything the visitor managed to type.
 * The endpoint records that, which is the difference between a half-filled
 * form being a lead we can still chase and a lead we never knew about.
 */
final class Validator
{
    /** Problems phrased for the person filling the form in. */
    private const MESSAGES = [
        'name' => 'Please add your name so we know who to reply to.',
        'email' => 'Please add a work email so we can reply.',
        'phone' => 'Please add a phone number in case we need a detail clarified.',
        'company' => 'Please tell us the project or firm name.',
        'service' => 'Please choose what you need help with.',
    ];

    public function __construct(
        private array $fields,
        private int $maxLen,
    ) {
    }

    /**
     * Returns ['data' => [...]] on success, or ['error' => [...], 'data' => [...]]
     * describing the first field that failed, ready to hand to Http::failure(),
     * alongside whatever was readable.
     */
    public function validate(array $input): array
    {
        $data = [];
        $raw = [];
        $malformed = [];

        // --- Pass one: read everything, judge nothing -----------------------

        foreach ($this->fields as $key => $label) {
            $value = $input[$key] ?? '';

            // An array here means someone posted name[]=… to probe the code.
            // Recorded as blank so the rest of the form still comes through.
            if (!is_string($value)) {
                $malformed[$key] = true;
                $raw[$key] = '';
                $data[$key] = '';
                continue;
            }

            $raw[$key] = $value;
            $data[$key] = $this->clean($value);
        }

        // --- Pass two: the first problem, in the order of the form ----------

        foreach ($this->fields as $key => $label) {
            if (isset($malformed[$key])) {
                return $this->reject($data, 'malformed_field', $key, "The “{$label}” field was not sent correctly. Please reload the page and try again.");
            }

            if ($data[$key] === '' && $key !== 'message') {
                return $this->reject($data, 'missing_field', $key, self::MESSAGES[$key] ?? "Please fill in the “{$label}” field.");
            }

            // Measured after trimming, so trailing whitespace alone never
            // pushes an otherwise fine value over the limit.
            if (mb_strlen(trim($raw[$key])) > $this->maxLen) {
                return $this->reject($data, 'field_too_long', $key, "That “{$label}” is longer than we can accept. Please shorten it.");
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->reject($data, 'invalid_email', 'email', 'That email address does not look right. Please check it and try again.');
        }

        if (!in_array($data['service'], ['Marketing', 'Software', 'Marketing + software', 'Help me decide'], true)) {
            return $this->reject($data, 'invalid_service', 'service', 'Please choose one of the available services.');
        }

        // Enough digits to be a real number, ignoring spaces, +, dashes.
        if (preg_match_all('/\d/', $data['phone']) < 7) {
            return $this->reject($data, 'invalid_phone', 'phone', 'That phone number looks too short. Please include the full number.');
        }

        return ['data' => $data];
    }

    /** A refusal, carrying the readable part of the submission with it. */
    private function reject(array $data, string $code, string $field, string $message): array
    {
        return [
            'data' => $data,
            'error' => ['code' => $code, 'field' => $field, 'message' => $message],
        ];
    }

    /**
     * Collapse whitespace, strip anything that could forge a mail header, and
     * cap the length.
     *
     * The regex runs without /u on purpose. With it, PCRE returns null for any
     * subject that is not valid UTF-8 — which the ?? '' below would turn into
     * an empty field, so one stray byte from a phone keyboard would read as a
     * blank form rather than the value the visitor typed.
     */
    private function clean(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);

        // Drop anything that is not valid UTF-8 rather than failing on it.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return mb_substr($value, 0, $this->maxLen);
    }
}
