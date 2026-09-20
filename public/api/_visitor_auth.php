<?php
/**
 * SECURITY: Visitor Session Tokens and the Admission Gate
 *
 * Two problems this file solves.
 *
 * 1. IDENTITY
 *    The visitor API used to take `visitor_id` straight from the request body
 *    and trust it. Anyone could post someone else's id and read or write their
 *    record. Sign-in now issues a random bearer token; the client sends it on
 *    every request and the server resolves the visitor from the token instead
 *    of from a number the client chose.
 *
 *    Only the SHA-256 of the token is stored. A leaked database therefore does
 *    not hand out working sessions, the same reason passwords are hashed.
 *
 * 2. THE ADMISSION GATE
 *    Museum content is only released once the front desk has cleared the
 *    visitor: locals must have had a residency ID sighted, everyone else must
 *    have paid the admission fee. Enforcing that in the app alone would be
 *    theatre — the app runs on the visitor's own phone, where localStorage can
 *    be edited freely. So the check lives here, in front of the endpoints that
 *    actually serve exhibits, and the app's waiting screen is just a friendly
 *    presentation of the same answer.
 */

// Sessions last a day. Long enough to cover a museum visit without forcing a
// re-login mid-tour, short enough that a token found on a shared or lost
// handset stops working quickly.
const VISITOR_TOKEN_TTL_SECONDS = 86400;

/**
 * Issue a fresh session token for a visitor and store its hash.
 * Returns the raw token — the only time it exists outside the client.
 */
function issueVisitorToken($con, int $visitorId): array
{
    // random_bytes() is a cryptographically secure source. rand()/uniqid()
    // are predictable and must never be used to mint a session token.
    $raw     = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $raw);
    $expires = date('Y-m-d H:i:s', time() + VISITOR_TOKEN_TTL_SECONDS);

    $stmt = mysqli_prepare($con, "UPDATE visitors SET api_token=?, token_expires_at=? WHERE visitor_id=?");
    mysqli_stmt_bind_param($stmt, 'ssi', $hash, $expires, $visitorId);
    mysqli_stmt_execute($stmt);

    return ['token' => $raw, 'expires_at' => $expires];
}

/** Clear a visitor's session so the token stops working immediately. */
function revokeVisitorToken($con, int $visitorId): void
{
    $stmt = mysqli_prepare($con, "UPDATE visitors SET api_token=NULL, token_expires_at=NULL WHERE visitor_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $visitorId);
    mysqli_stmt_execute($stmt);
}

/**
 * Read the bearer token from the request.
 *
 * Prefers the Authorization header. Falls back to a `token` query parameter
 * because the service worker's cached GETs and a couple of older call sites
 * cannot set headers.
 */
function visitorTokenFromRequest(?array $body = null): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $header = $value; break; }
        }
    }
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    if (!empty($body['token']) && is_string($body['token'])) {
        return trim($body['token']);
    }
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }
    return '';
}

/**
 * The visitor row, with today's group alongside it.
 *
 * A member of a party the desk registered is unlocked by the GROUP's payment,
 * not their own - they owe nothing themselves. So every place that decides
 * whether the door opens needs the group's standing on the same row. The
 * join is restricted to today: group_id is kept as history and can point at
 * last month's party, which must have no say in whether they get in now.
 *
 * Use this SELECT anywhere a visitor is loaded for a clearance decision.
 */
const VISITOR_WITH_GROUP_SQL =
    "SELECT v.*,
            g.group_id       AS g_group_id,
            g.payment_status AS g_payment_status,
            g.visitor_type   AS g_visitor_type,
            g.contact_name   AS g_contact_name,
            g.group_name     AS g_group_name,
            g.headcount      AS g_headcount
       FROM visitors v
  LEFT JOIN visit_groups g
         ON g.group_id = v.group_id AND g.visit_date = CURDATE()";

/** Re-read one visitor by id, with today's group. */
function loadVisitor($con, int $visitorId): ?array
{
    $stmt = mysqli_prepare($con, VISITOR_WITH_GROUP_SQL . " WHERE v.visitor_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $visitorId);
    mysqli_stmt_execute($stmt);

    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/**
 * Resolve the visitor behind a token, or null when it is missing, unknown or
 * expired. Expiry is enforced in SQL so a stale row can never authenticate.
 */
function visitorFromToken($con, ?array $body = null): ?array
{
    $raw = visitorTokenFromRequest($body);
    // A 64-char hex string is the only shape a real token has; reject anything
    // else before it reaches the database.
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) return null;

    $hash = hash('sha256', $raw);
    $stmt = mysqli_prepare($con,
        VISITOR_WITH_GROUP_SQL . " WHERE v.api_token = ? AND v.token_expires_at IS NOT NULL AND v.token_expires_at > NOW() LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);

    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

/** True when this row is a member of a group that is here today. */
function visitorInGroupToday(array $v): bool
{
    return !empty($v['g_group_id']);
}

/**
 * Whether the group a member belongs to gets them through the door.
 *
 * A paying group clears its members once the fee is collected. A Local
 * group clears them at once: the desk registered the party face to face,
 * looking at them, which is the residency check - there is no separate ID
 * step. Mirrors VisitGroup::clearsMembers() in the admin panel.
 */
function groupClearsMembers(array $v): bool
{
    return $v['g_visitor_type'] === 'Local' || $v['g_payment_status'] === 'Paid';
}

/**
 * Whether the front desk has cleared this visitor to enter.
 *
 * With a group today, the group's standing is theirs. On their own, locals
 * are admitted free but only once staff has sighted a residency ID, and
 * everyone else is admitted once the admission fee has been collected.
 * Returns one of: cleared | pending_id | pending_payment | pending_group.
 */
function visitorClearance(array $v): string
{
    if (visitorInGroupToday($v)) {
        return groupClearsMembers($v) ? 'cleared' : 'pending_group';
    }
    if ($v['visitor_type'] === 'Local') {
        return (int)$v['id_verified'] === 1 ? 'cleared' : 'pending_id';
    }
    return $v['payment_status'] === 'Paid' ? 'cleared' : 'pending_payment';
}

function visitorIsCleared(array $v): bool
{
    return visitorClearance($v) === 'cleared';
}

/**
 * The public shape of a visitor's admission state, safe to hand to the app.
 * Never includes the password or token hash.
 */
function clearancePayload(array $v): array
{
    $state = visitorClearance($v);

    return [
        'visitor_id'     => (int)$v['visitor_id'],
        'first_name'     => $v['first_name'],
        'last_name'      => $v['last_name'],
        'email'          => $v['email'],
        'age'            => $v['age'],
        'sex'            => $v['sex'],
        'visit_type'     => $v['visit_type'],
        'visitor_type'   => $v['visitor_type'],
        'city'           => $v['city'],
        'barangay'       => $v['barangay'] ?? null,
        'province'       => $v['province'],
        'country'        => $v['country'],
        'admission_fee'  => (float)$v['admission_fee'],
        'payment_status' => $v['payment_status'],
        'id_verified'    => (int)$v['id_verified'] === 1,
        'explore_mode'   => $v['explore_mode'],
        'clearance'      => $state,
        'cleared'        => $state === 'cleared',
        // Who they came with, so the app can say "you're with Maria's group"
        // and explain that the group's payment is what they are waiting on.
        'group'          => visitorInGroupToday($v) ? [
            'label'          => $v['g_group_name'] ?: $v['g_contact_name'] . "'s group",
            'headcount'      => (int)$v['g_headcount'],
            'payment_status' => $v['g_payment_status'],
        ] : null,
    ];
}

/**
 * Gate an endpoint: the caller must present a valid token AND have been
 * cleared by staff. Ends the request with 401 or 403 otherwise.
 *
 * 403 deliberately reports which step is outstanding — the visitor is standing
 * at the desk and needs to know whether to pay or to show an ID. It reveals
 * nothing an attacker could use, since a token is required to get this far.
 */
function requireClearedVisitor($con, ?array $body = null): array
{
    $v = visitorFromToken($con, $body);

    if (!$v) {
        http_response_code(401);
        echo json_encode([
            'error'   => 'unauthenticated',
            'message' => 'Please sign in to continue.',
        ]);
        exit;
    }

    if (!visitorIsCleared($v)) {
        $state = visitorClearance($v);
        http_response_code(403);
        echo json_encode([
            'error'     => 'not_cleared',
            'clearance' => $state,
            'message'   => match ($state) {
                'pending_payment' => 'Please pay the admission fee at the entrance counter. A staff member will confirm it.',
                'pending_group'   => 'Your group\'s admission has not been recorded yet. Once the person who signed you in pays at the counter, this unlocks by itself.',
                default           => 'Please present your residency ID at the entrance desk. A staff member will verify it.',
            },
            'visitor'   => clearancePayload($v),
        ]);
        exit;
    }

    return $v;
}

/**
 * SECURITY: Sign-in throttle.
 *
 * The generic IP rate limiter allows enough requests for ordinary browsing,
 * which is far too many password guesses. Sign-in attempts get their own much
 * tighter budget, keyed on the email being targeted as well as the IP so that
 * one attacker cannot lock out a whole museum's worth of visitors behind the
 * same NAT address, and so that spreading guesses across many emails from one
 * address is still caught.
 */
function throttleSignIn(string $email): void
{
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Per-account: 6 failed-or-not attempts a minute is far beyond human use.
    apiRateLimit(6, 60, 'login_acct_' . md5(mb_strtolower($email)));
    // Per-address: catches one host spraying one guess across many accounts.
    apiRateLimit(30, 300, 'login_ip_' . md5($ip));
}
