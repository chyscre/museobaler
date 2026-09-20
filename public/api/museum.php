<?php
header('Content-Type: application/json');

// SECURITY: allow-listed origins only, and preflight ends here.
require_once '_cors.php';
apiCors('GET, POST, OPTIONS');

// SECURITY: Rate limiting — max 60 requests per minute per IP
require_once '_rate_limit.php';
apiRateLimit(60, 60);

include '../auth/db.php';
require_once '_museum.php';

$info  = [];
$halls = [];
try {
    $r = mysqli_query($con, "SELECT * FROM museum_info LIMIT 1");
    if ($r) $info = mysqli_fetch_assoc($r) ?: [];
    $r2 = mysqli_query($con, "SELECT * FROM museum_halls ORDER BY sort_order ASC");
    if ($r2) $halls = mysqli_fetch_all($r2, MYSQLI_ASSOC);
} catch (Exception $e) {}

// The admission line is generated from the fee, never typed, so the About
// screen, the sign-up fee box and the desk can never disagree.
$fee = museumAdmissionFee($con);
$info['admission_fee'] = $fee;
$info['admission']     = museumAdmissionSentence($fee);

// Whether the visitor app must actually be inside the fence before it logs
// an entry. Decided here, not by the client looking at its own hostname: a
// production server reached by its LAN address is still production, and a
// switch the phone can flip for itself is not a switch. Same rule as the
// staff geofence (ATTENDANCE_GEOFENCE) - off only when VISITOR_GEOFENCE=false
// and APP_ENV is not production, so the entry/exit flow can be walked through
// at a desk that is nowhere near Baler.
$info['geofence_enforced'] = apiEnv('APP_ENV', 'production') === 'production'
    || strtolower((string) apiEnv('VISITOR_GEOFENCE', 'true')) !== 'false';

echo json_encode(['info' => $info, 'halls' => $halls]);
