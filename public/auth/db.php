<?php
/**
 * SECURITY: Database credentials loaded from environment variables.
 *
 * Previously, credentials were hardcoded as plain strings in this file,
 * which means anyone who gains read access to the source code (e.g., via
 * a misconfigured server, version control leak, or directory traversal)
 * would immediately have the database password.
 *
 * By reading from environment variables (set in the server's environment
 * or a .env file outside the web root), credentials are never stored in
 * the codebase itself.
 *
 * SECURITY: The .env file is listed in .gitignore so it is never committed
 * to version control.
 */

// Load .env values if not already in environment (for XAMPP/shared hosting)
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile) && empty(getenv('DB_HOST'))) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

$host = getenv('DB_HOST')     ?: 'localhost';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$db   = getenv('DB_DATABASE') ?: 'museobaler';
$port = (int)(getenv('DB_PORT') ?: 3306);

$con = mysqli_connect($host, $user, $pass, $db, $port);
if (!$con) {
    // SECURITY: Do not expose the actual connection error to the client.
    // Log it server-side instead.
    error_log('DB connection failed: ' . mysqli_connect_error());
    http_response_code(503);
    echo json_encode(['error' => 'service_unavailable']);
    exit;
}

// SECURITY: Set charset to utf8mb4 to prevent charset-based injection attacks
mysqli_set_charset($con, 'utf8mb4');

// Clocks. The whole app runs on APP_TIMEZONE (Manila), but PHP here defaults
// to UTC and MySQL's NOW() follows whatever the server's OS clock is set to.
// Pin both, or a survey filed at 1 AM lands on yesterday's ARTA report and
// the "new visit day" check flips eight hours early on a UTC server. Manila
// has no daylight saving, so a fixed offset is exact and needs no tz tables.
$tz = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
date_default_timezone_set($tz);
$offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
mysqli_query($con, "SET time_zone = '" . mysqli_real_escape_string($con, $offset) . "'");
