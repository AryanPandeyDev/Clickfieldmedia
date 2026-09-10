<?php
declare(strict_types=1);

/**
 * Decides where the API keeps leads, logs and rate-limit counters, and does
 * the small amount of locked file work the rest of the code needs.
 *
 * Preference order matters. Anything written here is business data, and a
 * misconfigured host that stops applying .htaccess would otherwise expose it,
 * so we try to sit above the web root first and only fall back inside it.
 */
final class Storage
{
    private string $dir;

    public function __construct(string $dirName)
    {
        $this->dir = $this->resolve($dirName);
    }

    /** Absolute path of the storage directory. */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Absolute path to a file inside storage. */
    public function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    /** True when we had to fall back to a directory inside the web root. */
    public function isInsideWebRoot(): bool
    {
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null;
        $here = realpath($this->dir) ?: $this->dir;
        return $root !== null && str_starts_with($here, $root);
    }

    /**
     * One directory above the web root if that is writable, otherwise a
     * hidden directory beside the API that .htaccess denies.
     */
    private function resolve(string $dirName): string
    {
        $candidates = [];

        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null;
        if ($root !== null) {
            $parent = dirname($root);
            // Only worth trying when it really is a level up, not "/".
            if ($parent !== $root && is_dir($parent)) {
                $candidates[] = $parent . DIRECTORY_SEPARATOR . $dirName;
            }
        }

        // Last resort: next to the API, protected by api/.htaccess.
        $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';

        foreach ($candidates as $candidate) {
            if ($this->prepare($candidate)) {
                return $candidate;
            }
        }

        // Nothing was writable. Hand back the local one anyway so callers can
        // fail quietly per-write rather than the whole request dying here.
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    }

    /** Create the directory if needed and confirm we can actually write. */
    private function prepare(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            return false;
        }
        if (!is_writable($path)) {
            return false;
        }

        // Belt and braces for the in-web-root fallback: even if the parent
        // .htaccess is ignored, this one denies the directory outright.
        $guard = $path . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($guard)) {
            @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }

        return true;
    }

    /**
     * Append a line to a file under an exclusive lock, rotating first if it
     * has grown past the cap. Never throws: losing a log line must not cost
     * us the enquiry that was being logged.
     */
    public function append(string $name, string $line, int $maxBytes = 0): bool
    {
        $file = $this->path($name);

        if ($maxBytes > 0 && @filesize($file) > $maxBytes) {
            @rename($file, $file . '.1');
        }

        $handle = @fopen($file, 'a');
        if ($handle === false) {
            return false;
        }

        @flock($handle, LOCK_EX);
        $ok = @fwrite($handle, $line) !== false;
        @fflush($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);

        return $ok;
    }

    /**
     * Read JSON state, hand it to $mutate, and write back what comes out, all
     * while holding one exclusive lock. This is how the rate limiter avoids
     * two simultaneous requests each reading the same stale count.
     */
    public function withJson(string $name, callable $mutate): mixed
    {
        $file = $this->path($name);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Cannot lock, so cannot count. Caller decides what that means.
            return $mutate(null);
        }

        @flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle) ?: '';
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            $state = [];
        }

        [$state, $result] = $mutate($state);

        if ($state !== null) {
            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
            @fflush($handle);
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
        @chmod($file, 0600);

        return $result;
    }

    /** Delete files in a subdirectory that have not been touched recently. */
    public function sweep(string $subdir, int $olderThanSeconds): void
    {
        $path = $this->path($subdir);
        if (!is_dir($path)) {
            return;
        }
        $cutoff = time() - $olderThanSeconds;
        foreach ((glob($path . DIRECTORY_SEPARATOR . '*') ?: []) as $file) {
            if (is_file($file) && @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /** Ensure a subdirectory exists inside storage. */
    public function subdir(string $name): string
    {
        $path = $this->path($name);
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        return $path;
    }
}
