<?php
declare(strict_types=1);

/**
 * Everything about reading the request and writing the reply.
 *
 * The response side is deliberately the only place that calls exit, so there
 * is exactly one path out of the API and it always sets the same headers.
 */
final class Http
{
    public function __construct(
        private string $requestId,
        private string $thankYou,
    ) {
    }

    // --- Request -----------------------------------------------------------

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** True when the caller asked for JSON rather than a page. */
    public function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Same-origin check. A browser sends Origin on every cross-origin POST, so
     * a mismatch means somebody else's page is posting here.
     *
     * The primary test is against the request's own host rather than a list.
     * A hard-coded allowlist is the kind of thing that silently refuses every
     * submission the day the site moves to a staging domain or gains a new
     * hostname, which looks exactly like the form being broken. Comparing
     * Origin to Host is correct wherever this is deployed, with the configured
     * list left as an extra allowance for anything genuinely cross-domain.
     *
     * A missing Origin is allowed: a plain form post from our own site may not
     * send one, and refusing those would break the no-JavaScript path.
     */
    public function originAllowed(array $allowed): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || $origin === 'null') {
            return true;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($originHost) || $originHost === '') {
            return false;
        }
        $originHost = strtolower($originHost);

        // Host carries a port where one is in use; Origin's host never does.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', $host)[0];
        if ($host !== '' && $originHost === $host) {
            return true;
        }

        foreach ($allowed as $candidate) {
            $candidateHost = parse_url((string) $candidate, PHP_URL_HOST);
            if (is_string($candidateHost) && strtolower($candidateHost) === $originHost) {
                return true;
            }
        }

        return false;
    }

    public function contentLength(): int
    {
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    }

    // --- Response ----------------------------------------------------------

    /**
     * Headers every reply gets. no-store is the important one: without it the
     * site's own .htaccess hands this endpoint a month-long public cache
     * lifetime, and a CDN or proxy is then free to store one visitor's error
     * and replay it to everybody who submits afterwards.
     */
    private function baseHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Accept, Origin');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-Id: ' . $this->requestId);
        header_remove('X-Powered-By');
    }

    /**
     * Success. JSON callers get a flag, plus anything in $extra; form posts get
     * the thank-you page.
     */
    public function success(array $extra = []): never
    {
        $this->baseHeaders();

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'ref' => $this->requestId,
                'redirect' => $this->thankYou,
            ] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $this->thankYou, true, 303);
        exit;
    }

    /**
     * Failure. The visitor gets a sentence they can act on plus the reference
     * that identifies this exact request in failures.log; the client also gets
     * a machine-readable code and, where relevant, the field to highlight.
     */
    public function failure(
        int $status,
        string $code,
        string $message,
        ?string $field = null,
        array $extraHeaders = [],
    ): never {
        $this->baseHeaders();
        foreach ($extraHeaders as $header) {
            header($header);
        }

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
            echo json_encode([
                'ok' => false,
                'code' => $code,
                'error' => $message,
                'field' => $field,
                'ref' => $this->requestId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->errorPage($message);
        exit;
    }

    /** The no-JavaScript failure page. Plain, but it offers a way through. */
    private function errorPage(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $ref = htmlspecialchars($this->requestId, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>That did not send &middot; Clickfield Media</title>
        <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a2541;color:#fff;
        font:16px/1.6 system-ui,sans-serif;padding:2rem;text-align:center}
        div{max-width:34rem}h1{font-size:1.6rem;margin:0 0 .75rem}p{color:rgba(255,255,255,.72);margin:.5rem 0}
        a{color:#e95d15}code{font:13px/1 ui-monospace,monospace;color:rgba(255,255,255,.5)}</style>
        </head><body><div>
        <h1>That did not send</h1><p>{$safe}</p>
        <p>You can email <a href="mailto:info@clickfieldmedia.com">info@clickfieldmedia.com</a>
        or call <a href="tel:+916393735033">+91 63937 35033</a> instead.</p>
        <p><a href="/">Back to the site</a></p>
        <p><code>Reference {$ref}</code></p></div></body></html>
        HTML;
    }
}
