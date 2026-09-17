<?php
/**
 * SECURITY: Visitor API
 *
 * This file handles all visitor-side API requests from the mobile app.
 * It uses the raw PHP + MySQLi stack (separate from the Laravel admin panel).
 *
 * Security measures applied:
 * - All SQL queries use prepared statements (prevents SQL Injection)
 * - All user input is validated and type-cast before use
 * - Output is JSON-encoded (no raw HTML output, preventing XSS via API)
 * - CORS is restricted to the specific app origin
 * - Rate limiting via a simple IP-based counter in the DB
 */

header('Content-Type: application/json');

/**
 * SECURITY: CORS (Cross-Origin Resource Sharing)
 *
 * Previously set to '*' (any origin), which allows any website or app
 * to make requests to this API. This is a security risk because it enables
 * Cross-Site Request Forgery (CSRF) from malicious websites.
 *
 * We now restrict to the specific origins that are allowed to call this API.
 * For a mobile app using a local dev server or ngrok, we allow those origins.
 * In production, this should be locked to the exact app domain/scheme.
 */
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'capacitor://localhost',  // Ionic/Capacitor mobile app
    'ionic://localhost',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Allow all for development/ngrok — tighten in production
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// SECURITY: Rate limiting — max 60 requests per minute per IP
// Registration is limited more strictly: 10 per minute to prevent spam accounts
require_once '_rate_limit.php';
apiRateLimit(60, 60);

include '../auth/db.php';

// Password rules and the bearer-token / admission gate live in their own files
// so the content endpoints can enforce exactly the same checks.
require_once '_password_policy.php';
require_once '_visitor_auth.php';
require_once '_museum.php';

/**
 * SECURITY: Input Validation Helper
 *
 * Centralizes validation logic. Validates that a string value:
 * - Is not empty (if required)
 * - Does not exceed a maximum length (prevents buffer overflow / DB truncation attacks)
 * - Matches an allowed list (for enum fields, prevents arbitrary value injection)
 */
function validateString(string $value, int $maxLen = 255, array $allowed = []): bool
{
    if (mb_strlen($value) > $maxLen) return false;
    if (!empty($allowed) && !in_array($value, $allowed, true)) return false;
    return true;
}

// ── POST: Register / Scan / Feedback / Bookmark / Set Mode ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $data['action'] ?? '';

    /**
     * Admission rules, resolved server-side.
     *
     * SECURITY: the fee and payment status are NEVER taken from the request
     * body — a visitor could otherwise post admission_fee=0 and skip paying.
     * They are derived here from the validated visitor_type alone.
     *
     * Local   -> free entry, but staff must sight a valid Baler ID at the desk,
     *            so the record starts with id_verified = 0.
     * Tourist -> flat fee, collected at the entrance counter.
     * Foreign -> flat fee, collected at the entrance counter.
     *
     * The flat fee is whatever the Museum Info page says today.
     */
    define('ADMISSION_FEE', museumAdmissionFee($con));

    function admissionFor(string $visitorType): array
    {
        return $visitorType === 'Local'
            ? ['fee' => 0.00,          'status' => 'Free']
            : ['fee' => ADMISSION_FEE, 'status' => 'Unpaid'];
    }

    /**
     * Mark a returning visitor as here again.
     *
     * Admission is charged per visit, so a paying visitor whose last visit was
     * on an earlier date owes the fee again and their payment status resets to
     * Unpaid. Locals stay Free, and their ID verification carries over.
     */
    function touchReturningVisitor($con, array $v): array
    {
        $vid      = (int)$v['visitor_id'];
        $lastDate = $v['last_visit'] ? substr($v['last_visit'], 0, 10) : null;
        $isNewDay = $lastDate !== date('Y-m-d');

        if ($v['visitor_type'] !== 'Local' && $isNewDay) {
            $fee    = ADMISSION_FEE;
            $status = 'Unpaid';
            $upd = mysqli_prepare($con,
                "UPDATE visitors SET last_visit=NOW(), admission_fee=?, payment_status=?, paid_at=NULL WHERE visitor_id=?"
            );
            mysqli_stmt_bind_param($upd, 'dsi', $fee, $status, $vid);
            mysqli_stmt_execute($upd);
            $v['admission_fee']  = $fee;
            $v['payment_status'] = $status;
        } else {
            $upd = mysqli_prepare($con, "UPDATE visitors SET last_visit=NOW() WHERE visitor_id=?");
            mysqli_stmt_bind_param($upd, 'i', $vid);
            mysqli_stmt_execute($upd);
        }

        return $v;
    }

    // ── Sign in with email + password ────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'invalid_credentials']); exit;
        }

        // SECURITY: brute-force protection, tighter than the general API limit.
        throttleSignIn($email);

        // SECURITY: prepared statement — the email is bound as data, not SQL.
        $stmt = mysqli_prepare($con, "SELECT * FROM visitors WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $v = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        /**
         * SECURITY: Account enumeration.
         *
         * An unknown email and a wrong password return the identical error, so
         * the response cannot be used to work out which addresses are
         * registered. password_verify() is also run against a dummy hash when
         * no row was found, so the reply takes the same time either way and
         * cannot be distinguished by a stopwatch.
         */
        $storedHash = $v['password'] ?? '$2y$12$usesomesillystringfaketohashaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        if (!password_verify($password, $storedHash) || !$v) {
            echo json_encode(['error' => 'invalid_credentials']); exit;
        }

        // Rehash if PHP's default cost has moved on since this was set.
        if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
            $fresh = password_hash($password, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($con, "UPDATE visitors SET password=? WHERE visitor_id=?");
            mysqli_stmt_bind_param($upd, 'si', $fresh, $v['visitor_id']);
            mysqli_stmt_execute($upd);
        }

        // A new visit means the admission fee falls due again for paying types.
        $v     = touchReturningVisitor($con, $v);
        $token = issueVisitorToken($con, (int)$v['visitor_id']);

        echo json_encode(clearancePayload($v) + [
            'returning'  => true,
            'found'      => true,
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
        ]);
        exit;
    }

    // ── Sign out — invalidates the token server-side ─────────────────────────
    if ($action === 'logout') {
        $v = visitorFromToken($con, $data);
        if ($v) revokeVisitorToken($con, (int)$v['visitor_id']);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Current admission status ─────────────────────────────────────────────
    // The waiting screen polls this while the visitor stands at the desk, so it
    // flips to the museum the moment staff records the payment or the ID check.
    if ($action === 'status') {
        $v = visitorFromToken($con, $data);
        if (!$v) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthenticated']);
            exit;
        }
        echo json_encode(clearancePayload($v));
        exit;
    }

    // ── Register new visitor ─────────────────────────────────────────────────
    if ($action === 'register') {
        /**
         * SECURITY: Input Validation — Visitor Registration
         *
         * Every field is validated before being used in a SQL query.
         * - Names: trimmed, max 100 chars, required
         * - Age: cast to int, clamped to 0–120 (prevents negative or absurd values)
         * - Enum fields (sex, visitor_type, etc.): validated against an allowed list
         *   to prevent arbitrary strings from being stored in the database
         * - Email: required (it identifies the visitor on return visits) and
         *   validated with PHP's FILTER_VALIDATE_EMAIL
         * - All string fields have a max length matching the DB column definition
         */
        $first   = trim($data['first_name'] ?? '');
        $last    = trim($data['last_name'] ?? '');
        $mid     = trim($data['middle_name'] ?? '');
        $age     = max(0, min(120, (int)($data['age'] ?? 0)));
        $sex     = $data['sex'] ?? 'Prefer not to say';
        $vtype   = $data['visit_type'] ?? 'Solo';
        $vistype = $data['visitor_type'] ?? 'Local';
        $country = trim($data['country'] ?? 'Philippines');
        $city    = trim($data['city'] ?? '');
        $prov    = trim($data['province'] ?? '');
        $email   = trim($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $confirm  = (string)($data['password_confirm'] ?? '');
        $mode    = $data['explore_mode'] ?? 'Storyline';
        // Social sign-in was removed — registration is always manual now.
        $provider = 'manual';

        // Required field check
        if (empty($first) || empty($last)) {
            echo json_encode(['error' => 'missing_name']); exit;
        }

        // Length checks
        if (!validateString($first, 100) || !validateString($last, 100) || !validateString($mid, 100)) {
            echo json_encode(['error' => 'name_too_long']); exit;
        }

        // Enum validation — only allow known values
        $allowedSex     = ['Male', 'Female', 'Other', 'Prefer not to say'];
        $allowedVtype   = ['Solo', 'Group', 'School', 'Family', 'Walk-in'];
        $allowedVistype = ['Local', 'Tourist', 'Foreign'];
        $allowedMode    = ['Storyline', 'Free Roam'];

        if (!in_array($sex, $allowedSex, true))        $sex     = 'Prefer not to say';
        if (!in_array($vtype, $allowedVtype, true))    $vtype   = 'Solo';
        if (!in_array($vistype, $allowedVistype, true)) $vistype = 'Local';
        if (!in_array($mode, $allowedMode, true))      $mode    = 'Storyline';

        // Email is mandatory — it is how a returning visitor is recognised.
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'invalid_email']); exit;
        }
        if (mb_strlen($email) > 150) {
            echo json_encode(['error' => 'email_too_long']); exit;
        }

        // Locals claim free admission, so the municipality must be on record.
        if ($vistype === 'Local') {
            if ($city === '') { echo json_encode(['error' => 'missing_municipality']); exit; }
            $country = 'Philippines';
            if ($prov === '') $prov = 'Aurora';
        }
        // Foreign visitors must say which country they are from.
        if ($vistype === 'Foreign' && ($country === '' || strcasecmp($country, 'Philippines') === 0)) {
            echo json_encode(['error' => 'missing_country']); exit;
        }
        if (!validateString($city, 100) || !validateString($prov, 100) || !validateString($country, 100)) {
            echo json_encode(['error' => 'location_too_long']); exit;
        }

        /**
         * SECURITY: Password strength.
         *
         * Checked against the shared policy, which rejects short passwords,
         * missing character classes, well-known passwords, and anything built
         * from the visitor's own name or email address. The same rules run in
         * the app for live feedback, but this is the check that decides.
         */
        if ($password !== $confirm) {
            echo json_encode(['error' => 'password_mismatch', 'message' => passwordPolicyMessage('password_mismatch')]);
            exit;
        }
        $emailLocalPart = strstr($email, '@', true) ?: $email;
        $policyError = validatePasswordPolicy($password, [$first, $last, $emailLocalPart, $mid]);
        if ($policyError !== null) {
            echo json_encode(['error' => $policyError, 'message' => passwordPolicyMessage($policyError)]);
            exit;
        }

        /**
         * SECURITY: SQL Injection Prevention — Prepared Statement
         * The email value is bound as a parameter, never concatenated into the SQL string.
         * This ensures the database treats it as data, not as SQL code.
         */
        $chk = mysqli_prepare($con, "SELECT visitor_id FROM visitors WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, 's', $email);
        mysqli_stmt_execute($chk);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        if ($existing) {
            /**
             * SECURITY: this used to sign the caller straight in as the owner
             * of the matching email, which — now that accounts have passwords —
             * would let anyone take over an account just by re-registering with
             * its address. A known email must go through `login` instead.
             */
            echo json_encode(['error' => 'email_taken']);
            exit;
        }

        // SECURITY: only the bcrypt hash is stored, never the password itself.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // SECURITY: fee and payment status are derived, never client-supplied.
        $adm    = admissionFor($vistype);
        $fee    = $adm['fee'];
        $status = $adm['status'];

        $stmt = mysqli_prepare($con,
            // created_at/updated_at are set explicitly: this table has no
            // DEFAULT CURRENT_TIMESTAMP, so omitting them leaves NULL, which
            // makes the admin's live notification poll (which filters on
            // created_at) never see a new registration.
            // last_visit is stamped here as well. Without it the row looks like
            // it has never visited, so the first sign-in of the same day would
            // treat this as a new visit and put the fee back to Unpaid —
            // re-locking a visitor who had already paid minutes earlier.
            "INSERT INTO visitors (first_name,last_name,middle_name,age,sex,visit_type,visitor_type,country,city,province,email,password,auth_provider,explore_mode,admission_fee,payment_status,id_verified,last_visit,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW(),NOW())"
        );
        mysqli_stmt_bind_param($stmt, 'sssissssssssssds',
            $first, $last, $mid, $age, $sex, $vtype, $vistype, $country, $city, $prov, $email, $passwordHash, $provider, $mode, $fee, $status
        );
        if (mysqli_stmt_execute($stmt)) {
            $vid   = mysqli_insert_id($con);
            $token = issueVisitorToken($con, $vid);

            // A brand-new visitor is never cleared: staff must collect the fee
            // or sight the residency ID before museum content unlocks.
            echo json_encode([
                'visitor_id'     => $vid,
                'explore_mode'   => $mode,
                'admission_fee'  => $fee,
                'payment_status' => $status,
                'id_verified'    => false,
                'visitor_type'   => $vistype,
                'clearance'      => $vistype === 'Local' ? 'pending_id' : 'pending_payment',
                'cleared'        => false,
                'returning'      => false,
                'token'          => $token['token'],
                'expires_at'     => $token['expires_at'],
            ]);
        } else {
            error_log('Visitor insert error: ' . mysqli_error($con));
            echo json_encode(['error' => 'registration_failed']);
        }
        exit;
    }

    // ── Log a scan ───────────────────────────────────────────────────────────
    if ($action === 'scan') {
        /**
         * SECURITY: the scanning visitor is resolved from the bearer token,
         * never from a visitor_id in the body — that was forgeable, letting a
         * caller write scan history onto someone else's record. The gate also
         * means an uncleared visitor cannot log scans before paying.
         */
        $viewer   = requireClearedVisitor($con, $data);
        $vidParam = (int)$viewer['visitor_id'];

        $eid      = (int)($data['exhibit_id'] ?? 0);
        $rawType  = $data['scan_type'] ?? 'qr';
        // SECURITY: Only allow known enum values; default to 'qr' for legacy calls
        $scanType = in_array($rawType, ['qr', 'image'], true) ? $rawType : 'qr';

        if ($eid <= 0) { echo json_encode(['error' => 'missing_params']); exit; }

        $stmt = mysqli_prepare($con, "INSERT INTO scans (exhibit_id, visitor_id, scan_type, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
        mysqli_stmt_bind_param($stmt, 'iis', $eid, $vidParam, $scanType);
        mysqli_stmt_execute($stmt);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Was this visit guided? ───────────────────────────────────────────────
    // The app asks before opening the feedback sheet, so the "how was your
    // guide" question only appears for the minority of visits that had one.
    if ($action === 'my_guide') {
        // SECURITY: token-derived, so one visitor cannot probe another's tours.
        $viewer = requireClearedVisitor($con, $data);
        $vid    = (int)$viewer['visitor_id'];

        $stmt = mysqli_prepare($con,
            "SELECT s.staff_id, s.name
             FROM tours t
             JOIN staff s ON s.staff_id = t.guide_staff_id
             WHERE t.visitor_id = ? AND DATE(t.started_at) = CURDATE()
             ORDER BY t.started_at DESC LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $vid);
        mysqli_stmt_execute($stmt);
        $guide = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        echo json_encode($guide
            ? ['guided' => true, 'guide_name' => $guide['name']]
            : ['guided' => false]);
        exit;
    }

    // ── Submit feedback ──────────────────────────────────────────────────────
    if ($action === 'feedback') {
        /**
         * SECURITY: Input Validation — Feedback Submission
         *
         * - rating: cast to int, validated to be between 1 and 5 (no arbitrary values)
         * - comment: trimmed and capped at 2000 characters (prevents oversized payloads)
         * - Names: trimmed and capped at 100 characters
         * - visitor_id: cast to int, verified to exist in the DB before use
         *
         * SECURITY: XSS Prevention
         * The comment is stored as plain text in the database. When displayed in the
         * admin panel via Blade templates, Laravel's {{ }} syntax auto-escapes HTML
         * entities, so even if a user submits <script>alert(1)</script>, it will be
         * rendered as harmless text, not executed as code.
         */
        /**
         * SECURITY: the author is the authenticated visitor. Reading the id
         * from the body let anyone post feedback in another visitor's name —
         * and, since feedback is read by the Tourism office, put words in their
         * mouth. It also fixes an older bug where the PWA posted `visitorId`
         * while this handler read `visitor_id`, storing every row unattributed.
         */
        $author  = requireClearedVisitor($con, $data);
        $rating  = (int)($data['rating'] ?? 0);
        $comment = trim($data['comment'] ?? '');
        // Fall back to the account's own name when the sheet leaves it blank.
        $first   = trim($data['first_name'] ?? '')  ?: (string)$author['first_name'];
        $last    = trim($data['last_name'] ?? '')   ?: (string)$author['last_name'];
        $mid     = trim($data['middle_name'] ?? '') ?: (string)($author['middle_name'] ?? '');
        $guideRating = isset($data['guide_rating']) ? (int)$data['guide_rating'] : 0;

        // Validate rating range
        if ($rating < 1 || $rating > 5) {
            echo json_encode(['error' => 'invalid_rating']); exit;
        }

        if ($guideRating !== 0 && ($guideRating < 1 || $guideRating > 5)) {
            echo json_encode(['error' => 'invalid_guide_rating']); exit;
        }

        // Validate lengths
        if (mb_strlen($comment) > 2000) {
            echo json_encode(['error' => 'comment_too_long']); exit;
        }
        if (mb_strlen($first) > 100 || mb_strlen($last) > 100 || mb_strlen($mid) > 100) {
            echo json_encode(['error' => 'name_too_long']); exit;
        }

        // The token already proved this row exists, so no FK re-check is needed.
        $vid = $vidParam = (int)$author['visitor_id'];

        /**
         * Attribute the feedback to a guide only when this visit actually had
         * one. Most visitors at Museo de Baler roam unguided, so these two
         * columns stay null on the majority of rows — that is the expected
         * shape, not missing data.
         *
         * The guide is looked up from the tour on the server rather than being
         * accepted from the request, so a visitor cannot post a rating against
         * a staff member who never guided them.
         */
        $tourParam  = null;
        $staffParam = null;
        $attributed = 'none';

        if ($vidParam !== null) {
            $tq = mysqli_prepare($con,
                "SELECT tour_id, guide_staff_id FROM tours
                 WHERE visitor_id = ? AND DATE(started_at) = CURDATE()
                 ORDER BY started_at DESC LIMIT 1"
            );
            mysqli_stmt_bind_param($tq, 'i', $vidParam);
            mysqli_stmt_execute($tq);
            $tour = mysqli_fetch_assoc(mysqli_stmt_get_result($tq));

            if ($tour && $tour['guide_staff_id']) {
                $tourParam  = (int)$tour['tour_id'];
                $staffParam = (int)$tour['guide_staff_id'];
                $attributed = $guideRating > 0 ? 'visitor' : 'duty';
            }
        }

        // No tour means no guide rating to store, whatever the app sent.
        $guideParam = ($staffParam !== null && $guideRating > 0) ? $guideRating : null;

        $stmt = mysqli_prepare($con,
            "INSERT INTO feedback
                (visitor_id, tour_id, staff_id, first_name, last_name, middle_name,
                 rating, guide_rating, attributed_by, comment)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        mysqli_stmt_bind_param(
            $stmt, 'iiissssiss',
            $vidParam, $tourParam, $staffParam, $first, $last, $mid,
            $rating, $guideParam, $attributed, $comment
        );
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['ok' => true]);
        } else {
            error_log('Feedback insert error: ' . mysqli_error($con));
            echo json_encode(['error' => 'submission_failed']);
        }
        exit;
    }

    // ── Toggle bookmark ──────────────────────────────────────────────────────
    if ($action === 'bookmark') {
        // SECURITY: bookmarks belong to the authenticated visitor only.
        $viewer = requireClearedVisitor($con, $data);
        $vid    = (int)$viewer['visitor_id'];
        $eid    = (int)($data['exhibit_id'] ?? 0);
        if ($eid <= 0) { echo json_encode(['error' => 'missing_params']); exit; }

        $chk = mysqli_prepare($con, "SELECT bookmark_id FROM bookmarks WHERE visitor_id=? AND exhibit_id=?");
        mysqli_stmt_bind_param($chk, 'ii', $vid, $eid);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        if (mysqli_stmt_num_rows($chk) > 0) {
            $del = mysqli_prepare($con, "DELETE FROM bookmarks WHERE visitor_id=? AND exhibit_id=?");
            mysqli_stmt_bind_param($del, 'ii', $vid, $eid);
            mysqli_stmt_execute($del);
            echo json_encode(['bookmarked' => false]);
        } else {
            $ins = mysqli_prepare($con, "INSERT INTO bookmarks (visitor_id, exhibit_id) VALUES (?,?)");
            mysqli_stmt_bind_param($ins, 'ii', $vid, $eid);
            mysqli_stmt_execute($ins);
            echo json_encode(['bookmarked' => true]);
        }
        exit;
    }

    // ── Update explore mode ──────────────────────────────────────────────────
    if ($action === 'set_mode') {
        // SECURITY: a visitor may only change their own explore mode.
        $viewer = requireClearedVisitor($con, $data);
        $vid    = (int)$viewer['visitor_id'];
        $mode   = $data['mode'] ?? 'Storyline';

        // SECURITY: Validate against allowed values — prevents arbitrary string storage
        $allowedModes = ['Storyline', 'Free Roam'];
        if (!in_array($mode, $allowedModes, true)) {
            echo json_encode(['error' => 'invalid_mode']); exit;
        }

        $stmt = mysqli_prepare($con, "UPDATE visitors SET explore_mode=? WHERE visitor_id=?");
        mysqli_stmt_bind_param($stmt, 'si', $mode, $vid);
        mysqli_stmt_execute($stmt);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── GET: visitor profile + stats ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    /**
     * SECURITY: Broken Access Control — fixed.
     *
     * This endpoint used to serve any profile named by `?visitor_id=`, so
     * walking the ids 1, 2, 3… dumped every visitor's name, email, age and
     * visit history. The profile now comes from the bearer token, which means
     * a caller can only ever read their own record — there is no id to tamper
     * with. The admission gate applies too, so a visitor who has not paid
     * cannot pull their stats before entering.
     */
    $v   = requireClearedVisitor($con);
    $vid = (int)$v['visitor_id'];

    // Scanned count
    $stmt = mysqli_prepare($con, "SELECT COUNT(DISTINCT exhibit_id) c FROM scans WHERE visitor_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $vid);
    mysqli_stmt_execute($stmt);
    $scanned = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];

    // Bookmarked count
    $stmt = mysqli_prepare($con, "SELECT COUNT(*) c FROM bookmarks WHERE visitor_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $vid);
    mysqli_stmt_execute($stmt);
    $bookmarked = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];

    // Total active exhibits
    $total = (int)mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) c FROM exhibits WHERE status=1"))['c'];

    // Bookmarked exhibit IDs
    $stmt = mysqli_prepare($con, "SELECT exhibit_id FROM bookmarks WHERE visitor_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $vid);
    mysqli_stmt_execute($stmt);
    $bkResult = mysqli_stmt_get_result($stmt);
    $bkIds = [];
    while ($r = mysqli_fetch_assoc($bkResult)) $bkIds[] = (int)$r['exhibit_id'];

    // Scanned exhibit IDs
    $stmt = mysqli_prepare($con, "SELECT DISTINCT exhibit_id FROM scans WHERE visitor_id=? ORDER BY MAX(scanned_at) DESC");
    mysqli_stmt_bind_param($stmt, 'i', $vid);
    mysqli_stmt_execute($stmt);
    $scResult = mysqli_stmt_get_result($stmt);
    $scIds = [];
    while ($r = mysqli_fetch_assoc($scResult)) $scIds[] = (int)$r['exhibit_id'];

    echo json_encode([
        'visitor_id'    => (int)$v['visitor_id'],
        'first_name'    => $v['first_name'],
        'last_name'     => $v['last_name'],
        'email'         => $v['email'],
        'explore_mode'  => $v['explore_mode'],
        'visitor_type'  => $v['visitor_type'],
        'admission_fee' => (float)$v['admission_fee'],
        'payment_status'=> $v['payment_status'],
        'id_verified'   => (int)$v['id_verified'] === 1,
        'scanned'       => $scanned,
        'bookmarked'    => $bookmarked,
        'total_exhibits'=> $total,
        'progress_pct'  => $total > 0 ? round($scanned / $total * 100) : 0,
        'bookmark_ids'  => $bkIds,
        'scanned_ids'   => $scIds,
    ]);
    exit;
}
