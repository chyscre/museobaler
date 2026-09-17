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

echo json_encode(['info' => $info, 'halls' => $halls]);
