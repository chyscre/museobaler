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

// One .env reader for the whole raw-PHP side (api/_env.php). This file used
// to carry its own copy of the parse, skipped whenever DB_HOST happened to be
// set already - so a server that exported only that one variable read none
// of the others, and the two copies had started to drift.
require_once dirname(__DIR__) . '/api/_env.php';

$host = apiEnv('DB_HOST', 'localhost');
$user = apiEnv('DB_USERNAME', 'root');
$pass = apiEnv('DB_PASSWORD', '');
$db   = apiEnv('DB_DATABASE', 'museobaler');
$port = (int) apiEnv('DB_PORT', '3306');

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
$tz = apiEnv('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set($tz);
$offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
mysqli_query($con, "SET time_zone = '" . mysqli_real_escape_string($con, $offset) . "'");
