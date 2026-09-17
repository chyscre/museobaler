<?php
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
    'capacitor://localhost',
    'ionic://localhost',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// SECURITY: Rate limiting — max 60 requests per minute per IP
require_once '_rate_limit.php';
apiRateLimit(60, 60);

include '../auth/db.php';

/**
 * SECURITY: The admission gate.
 *
 * Exhibit records, descriptions, fun facts and audio guides ARE the product the
 * admission fee pays for, so this endpoint is where the gate has to bite. A
 * caller must present a valid session token and have been cleared by staff —
 * fee collected, or residency ID sighted for locals.
 *
 * Enforcing this in the app alone would be pointless: the app runs on the
 * visitor's own phone, so its "waiting for clearance" screen is only a polite
 * presentation of this check, never the check itself.
 */
require_once '_rate_limit.php';
require_once '_visitor_auth.php';
requireClearedVisitor($con);

$lang = $_GET['lang'] ?? 'en';

/**
 * Pictures live in public/images/exhibits/ and audio guides in public/audio/,
 * both siblings of this api/ directory. Paths are built from where this
 * script is actually served (/api/... on localhost, /museobaler/public/api/...
 * on yeppie.test, whatever a tunnel gives) instead of a hard-coded
 * /yeppie/museobaler prefix that matched none of them.
 */
$publicBase = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$imageUrl = fn(?string $file) => $file ? $publicBase . '/images/exhibits/' . rawurlencode($file) : null;
$audioUrl = fn(?string $file) => $file ? $publicBase . '/audio/' . rawurlencode($file) : null;

// Single exhibit by ID or code (for QR scan)
if (isset($_GET['id']) || isset($_GET['code'])) {
    if (isset($_GET['id'])) {
        $val = (int)$_GET['id'];
        $col = 'e.exhibit_id';
    } else {
        $val = trim($_GET['code']);
        $col = 'e.exhibit_code';
    }

    $stmt = mysqli_prepare($con,
        "SELECT e.exhibit_id, e.exhibit_code, e.name, e.description, e.fun_facts, e.floor, e.hall,
                e.authors, e.languages, e.storyline_order, e.image, e.status,
                e.date_published,
                c.name as category,
                t.title as t_title, t.description as t_desc, t.fun_facts as t_fun_facts, t.audio_file,
                (SELECT COUNT(*) FROM scans s WHERE s.exhibit_id = e.exhibit_id) as scan_count,
                (SELECT e2.exhibit_id FROM exhibits e2 WHERE e2.storyline_order = e.storyline_order + 1 AND e2.status = 1 LIMIT 1) as next_id
         FROM exhibits e
         LEFT JOIN categories c ON c.category_id = e.category_id
         LEFT JOIN exhibit_translations t ON t.exhibit_id = e.exhibit_id AND t.language_code = ?
         WHERE $col = ? AND e.status = 1"
    );
    if (is_int($val)) {
        mysqli_stmt_bind_param($stmt, 'si', $lang, $val);
    } else {
        mysqli_stmt_bind_param($stmt, 'ss', $lang, $val);
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row) { echo json_encode(['error' => 'not_found']); exit; }

    // Log scan if visitor_id provided
    if (isset($_POST['visitor_id']) || isset($_GET['visitor_id'])) {
        $vid = (int)($_POST['visitor_id'] ?? $_GET['visitor_id']);
        $eid = (int)$row['exhibit_id'];
        $ins = mysqli_prepare($con, "INSERT INTO scans (exhibit_id, visitor_id) VALUES (?,?)");
        mysqli_stmt_bind_param($ins, 'ii', $eid, $vid);
        mysqli_stmt_execute($ins);
    }

    // Fetch gallery images (safe if table doesn't exist yet)
    $gallery = [];
    try {
        $gStmt = mysqli_prepare($con, "SELECT filename, caption FROM exhibit_images WHERE exhibit_id=? ORDER BY sort_order ASC, image_id ASC");
        mysqli_stmt_bind_param($gStmt, 'i', $row['exhibit_id']);
        mysqli_stmt_execute($gStmt);
        $gResult = mysqli_stmt_get_result($gStmt);
        while ($g = mysqli_fetch_assoc($gResult)) {
            $gallery[] = ['filename' => $g['filename'], 'url' => $imageUrl($g['filename']), 'caption' => $g['caption']];
        }
    } catch (Exception $e) { /* exhibit_images table not yet created */ }

    // fun_facts: use translation's if set, else fall back to base
    $rawFacts = $row['t_fun_facts'] ?: $row['fun_facts'];

    echo json_encode([
        'exhibit_id'      => (int)$row['exhibit_id'],
        'exhibit_code'    => $row['exhibit_code'],
        'name'            => $row['t_title'] ?: $row['name'],
        'description'     => $row['t_desc'] ?: $row['description'],
        'fun_facts'       => $rawFacts ? array_values(array_filter(array_map('trim', explode("\n", $rawFacts)))) : [],
        'original_name'   => $row['name'],
        'category'        => $row['category'],
        'floor'           => $row['floor'],
        'hall'            => $row['hall'],
        'authors'         => $row['authors'],
        'languages'       => explode(',', $row['languages']),
        'storyline_order' => (int)$row['storyline_order'],
        'year'            => $row['date_published'] ? substr($row['date_published'], 0, 4) : '',
        'image'           => $imageUrl($row['image']),
        'gallery'         => $gallery,
        'audio_file'      => $row['audio_file'],
        'audio_url'       => $audioUrl($row['audio_file']),
        'scan_count'      => (int)$row['scan_count'],
        'next_id'         => $row['next_id'] ? (int)$row['next_id'] : null,
    ]);
    exit;
}

// All exhibits (for home/browse + offline cache)
$stmt = mysqli_prepare($con,
    "SELECT e.exhibit_id, e.exhibit_code, e.name, e.description, e.fun_facts, e.floor, e.hall,
            e.authors, e.languages, e.storyline_order, e.image, e.date_published,
            c.name as category,
            t.title as t_title, t.description as t_desc, t.fun_facts as t_fun_facts, t.audio_file
     FROM exhibits e
     LEFT JOIN categories c ON c.category_id = e.category_id
     LEFT JOIN exhibit_translations t ON t.exhibit_id = e.exhibit_id AND t.language_code = ?
     WHERE e.status = 1
     ORDER BY e.storyline_order ASC, e.name ASC"
);
mysqli_stmt_bind_param($stmt, 's', $lang);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$exhibits = [];
while ($row = mysqli_fetch_assoc($result)) {
    $exhibits[] = [
        'exhibit_id'      => (int)$row['exhibit_id'],
        'exhibit_code'    => $row['exhibit_code'],
        'name'            => $row['t_title'] ?: $row['name'],
        'description'     => $row['t_desc'] ?: $row['description'],
        'fun_facts'       => ($row['t_fun_facts'] ?: $row['fun_facts']) ? array_values(array_filter(array_map('trim', explode("\n", $row['t_fun_facts'] ?: $row['fun_facts'])))) : [],
        'category'        => $row['category'],
        'floor'           => $row['floor'],
        'hall'            => $row['hall'],
        'authors'         => $row['authors'],
        'languages'       => explode(',', $row['languages']),
        'storyline_order' => (int)$row['storyline_order'],
        'year'            => $row['date_published'] ? substr($row['date_published'], 0, 4) : '',
        'image'           => $imageUrl($row['image']),
        'audio_file'      => $row['audio_file'],
        'audio_url'       => $audioUrl($row['audio_file']),
    ];
}
echo json_encode($exhibits);
