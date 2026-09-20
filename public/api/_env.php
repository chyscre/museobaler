<?php
/**
 * Reads values from the project .env for the raw-PHP API.
 *
 * The visitor API runs outside Laravel, so config() and env() are unavailable
 * here. auth/db.php has parsed .env by hand since the database credentials
 * were moved out of the source; this puts that same parse in one place so the
 * CORS allow-list can read settings without a second, drifting copy of it.
 *
 * Values already present in the real environment always win, so a server that
 * sets variables properly (Apache SetEnv, systemd, a container) is unaffected.
 */

function apiEnv(string $key, ?string $default = null): ?string
{
    static $loaded = false;

    if (!$loaded) {
        $loaded  = true;
        $envFile = dirname(__DIR__, 2) . '/.env';

        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (getenv($k) === false && !array_key_exists($k, $_ENV)) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
    }

    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? '';
    }

    return $value === '' ? $default : $value;
}

/**
 * A writable directory under storage/app for this API's own scratch files -
 * rate-limit counters, the reference-image hash cache. Returns the path with
 * no trailing slash, creating it on first use.
 *
 * Not sys_get_temp_dir(): that is one machine's /tmp, which a systemd unit
 * with PrivateTmp sees as its own empty copy, a tmp cleaner sweeps on a
 * schedule, and no second server shares. storage/app is already the
 * directory this app owns and the deploy script keeps across releases.
 */
function apiStoragePath(string $subdir = 'api-cache'): string
{
    $dir = dirname(__DIR__, 2) . '/storage/app/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
