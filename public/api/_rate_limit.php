<?php
/**
 * SECURITY: API Rate Limiter (Additional Security Feature #2)
 *
 * PURPOSE:
 * Limits how many requests a single IP address can make to the visitor API
 * within a time window. This prevents:
 *
 *   1. Denial of Service (DoS) — an attacker flooding the API with thousands
 *      of requests per second, overwhelming the server and making it unavailable
 *      to real visitors.
 *
 *   2. Data harvesting — an automated script scraping all exhibit data or
 *      visitor records by making rapid sequential requests.
 *
 *   3. Spam registration — a bot registering thousands of fake visitor accounts
 *      to pollute the visitor records database.
 *
 * HOW IT WORKS:
 * Uses a simple file-based counter stored in the system temp directory.
 * Each IP gets one file PER ENDPOINT that tracks request count and window
 * start time. If the count exceeds the limit within the window, the request
 * is rejected with HTTP 429 Too Many Requests.
 *
 * WHY PER ENDPOINT:
 * A single shared counter meant the endpoints competed for one budget. Image
 * search samples the camera every few seconds, so it alone consumed most of
 * the allowance and then starved ordinary browsing — exhibits and museum info
 * started failing for a visitor who had done nothing wrong. Separate buckets
 * let each endpoint's limit mean what it says.
 *
 * KNOWN LIMITATION:
 * Buckets are keyed by IP, so every visitor behind the museum's Wi-Fi NAT
 * shares one budget. Limits therefore have to be set high enough for the whole
 * floor, not for one phone. Keying on a client-supplied device id would fix
 * that but would also let an attacker mint unlimited buckets, defeating the
 * DoS protection this exists for.
 *
 * LIMITS (configurable per call site):
 * - 60 requests per 60 seconds per IP address, per endpoint
 *
 * USAGE: include this file at the top of each API endpoint.
 */

function apiRateLimit(int $maxRequests = 60, int $windowSeconds = 60, ?string $bucket = null): void
{
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // SECURITY: Sanitize IP to prevent path traversal in the filename
    $safeIp  = preg_replace('/[^a-fA-F0-9:.]/', '_', $ip);

    // Defaults to the calling endpoint's filename so each API gets its own
    // budget without every call site having to name itself.
    $bucket     = $bucket ?? basename($_SERVER['SCRIPT_NAME'] ?? 'api', '.php');
    $safeBucket = preg_replace('/[^a-zA-Z0-9_-]/', '_', $bucket);

    $file = sys_get_temp_dir() . '/rl_museobaler_' . $safeBucket . '_' . md5($safeIp) . '.json';

    $now  = time();
    $data = ['count' => 0, 'start' => $now];

    if (file_exists($file)) {
        $stored = json_decode(file_get_contents($file), true);
        if ($stored && ($now - $stored['start']) < $windowSeconds) {
            $data = $stored;
        }
        // else: window expired, reset
    }

    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $maxRequests) {
        $retryAfter = $windowSeconds - ($now - $data['start']);
        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode([
            'error'       => 'rate_limit_exceeded',
            'message'     => 'Too many requests. Please slow down.',
            'retry_after' => $retryAfter,
        ]);
        exit;
    }
}
