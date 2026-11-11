<?php
declare(strict_types=1);

/**
 * File-backed rate limiting, sized for shared hosting where APCu or Redis may
 * not exist.
 *
 * Two buckets, because "how many times did you press the button" and "how many
 * enquiries did you actually send" deserve different ceilings. Counting them
 * together would mean somebody who mistypes their email twice is locked out
 * before they ever get a valid submission through, which reads to them as the
 * form being broken.
 *
 *   attempt  every request that gets past the honeypot. Generous: it exists
 *            only to stop something hammering the endpoint.
 *   submit   consumed once a submission has passed validation and is about to
 *            be emailed. Strict: this is what stops duplicate leads.
 *
 * Addresses are hashed before they are used as a filename, so the raw IP is
 * never written to disk.
 */
final class RateLimiter
{
    public function __construct(private Storage $storage)
    {
    }

    /**
     * Take one slot from a bucket. Returns null when the request may proceed,
     * or an array describing the rule that was hit.
     *
     * A blocked request is not recorded: retrying must not keep extending the
     * penalty, or a visitor who taps twice is locked out for far longer than
     * the rule says.
     */
    public function consume(string $bucket, string $ip, array $rules): ?array
    {
        if ($rules === []) {
            return null;
        }

        $this->storage->subdir('ratelimit');
        $longest = max(array_column($rules, 'window'));
        $key = sprintf(
            'ratelimit/%s-%s.json',
            preg_replace('/[^a-z]/', '', $bucket),
            substr(hash('sha256', $ip . '|clickfield'), 0, 32),
        );

        return $this->storage->withJson($key, static function (?array $state) use ($rules, $longest) {
            // Storage could not take a lock. Let the request through rather
            // than refusing a real enquiry over a disk problem.
            if ($state === null) {
                return null;
            }

            $now = time();
            $hits = array_values(array_filter(
                array_map('intval', $state['hits'] ?? []),
                static fn(int $t): bool => $t > $now - $longest,
            ));

            foreach ($rules as $rule) {
                $inWindow = array_filter($hits, static fn(int $t): bool => $t > $now - $rule['window']);

                if (count($inWindow) >= $rule['max']) {
                    // min() raises on an empty array; a max of 0 would be the
                    // only way to get here with nothing in the window.
                    $oldest = $inWindow === [] ? $now : min($inWindow);

                    return [
                        ['hits' => $hits],
                        [
                            'label' => $rule['label'],
                            'max' => $rule['max'],
                            'retry_after' => max(1, ($oldest + $rule['window']) - $now),
                        ],
                    ];
                }
            }

            $hits[] = $now;
            // Cap the array so a determined flooder cannot grow the file.
            if (count($hits) > 200) {
                $hits = array_slice($hits, -200);
            }

            return [['hits' => $hits], null];
        });
    }

    /**
     * A ceiling across everyone, so a distributed flood cannot fill the disk
     * or the inbox. A fixed window, which is cheaper than a sliding one and
     * precise enough for a limit real traffic should never approach.
     */
    public function consumeGlobal(array $rule, string $name = 'global'): ?array
    {
        $file = 'ratelimit-' . preg_replace('/[^a-z]/', '', $name) . '.json';
        return $this->storage->withJson($file, static function (?array $state) use ($rule) {
            if ($state === null) {
                return null;
            }

            $now = time();
            $start = (int) ($state['start'] ?? 0);
            $count = (int) ($state['count'] ?? 0);

            if ($now - $start >= $rule['window']) {
                $start = $now;
                $count = 0;
            }

            if ($count >= $rule['max']) {
                return [
                    ['start' => $start, 'count' => $count],
                    [
                        'label' => 'hour',
                        'max' => $rule['max'],
                        'retry_after' => max(1, ($start + $rule['window']) - $now),
                    ],
                ];
            }

            return [['start' => $start, 'count' => $count + 1], null];
        });
    }

    /**
     * Occasionally clear out counter files nobody has touched. Runs on about
     * one request in fifty, so it costs nothing on a normal submission.
     */
    public function maybeSweep(int $longestWindow): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $this->storage->sweep('ratelimit', $longestWindow * 2);
    }
}
