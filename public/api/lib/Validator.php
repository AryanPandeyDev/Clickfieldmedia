<?php
declare(strict_types=1);

/**
 * Turns raw POST input into either a clean set of values or the first problem
 * worth telling the visitor about.
 *
 * Error copy is written to be actionable on screen: it names the field in the
 * words used on the form and says what is wrong, rather than "invalid input".
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
     * Returns ['data' => [...]] on success, or ['error' => [...]] describing
     * the first field that failed, ready to hand to Http::failure().
     */
    public function validate(array $input): array
    {
        $data = [];

        foreach ($this->fields as $key => $label) {
            $raw = $input[$key] ?? '';

            // An array here means someone posted name[]=… to probe the code.
            if (!is_string($raw)) {
                return ['error' => [
                    'code' => 'malformed_field',
                    'field' => $key,
                    'message' => "The “{$label}” field was not sent correctly. Please reload the page and try again.",
                ]];
            }

            $value = $this->clean($raw);

            if ($value === '' && $key !== 'message') {
                return ['error' => [
                    'code' => 'missing_field',
                    'field' => $key,
                    'message' => self::MESSAGES[$key] ?? "Please fill in the “{$label}” field.",
                ]];
            }

            // Measured after trimming, so trailing whitespace alone never
            // pushes an otherwise fine value over the limit.
            if (mb_strlen(trim($raw)) > $this->maxLen) {
                return ['error' => [
                    'code' => 'field_too_long',
                    'field' => $key,
                    'message' => "That “{$label}” is longer than we can accept. Please shorten it.",
                ]];
            }

            $data[$key] = $value;
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['error' => [
                'code' => 'invalid_email',
                'field' => 'email',
                'message' => 'That email address does not look right. Please check it and try again.',
            ]];
        }

        if (!in_array($data['service'], ['Marketing', 'Software', 'Marketing + software', 'Help me decide'], true)) {
            return ['error' => ['code' => 'invalid_service', 'field' => 'service', 'message' => 'Please choose one of the available services.']];
        }

        // Enough digits to be a real number, ignoring spaces, +, dashes.
        if (preg_match_all('/\d/', $data['phone']) < 7) {
            return ['error' => [
                'code' => 'invalid_phone',
                'field' => 'phone',
                'message' => 'That phone number looks too short. Please include the full number.',
            ]];
        }

        return ['data' => $data];
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
