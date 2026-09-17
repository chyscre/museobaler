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
