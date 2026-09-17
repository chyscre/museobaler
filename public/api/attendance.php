<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '_rate_limit.php';
apiRateLimit(60, 60);

include '../auth/db.php';

// ── PATCH: Claim anonymous OR log exit ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data  = json_decode(file_get_contents('php://input'), true) ?: [];
    $aid   = isset($data['attendance_id']) ? (int)$data['attendance_id'] : 0;
    $event = $data['event'] ?? 'claim';
    $today = date('Y-m-d');

    if ($aid <= 0) { echo json_encode(['error' => 'missing_params']); exit; }

    // ── Exit event ────────────────────────────────────────────────────────────
    if ($event === 'exit') {
        $mins = isset($data['duration_mins']) ? (int)$data['duration_mins'] : null;

        // The app cannot watch location once it is backgrounded, so instead of
        // a single exit at the end it marks the visitor as still present every
        // time it is hidden. That means this runs several times per visit with
        // a growing duration, and exited_at is really "last confirmed on site"
        // until the visit genuinely ends. Keeping the longest duration stops a
        // late fragment — an app reopened in the car park, say — from
        // truncating a two-hour visit down to two minutes.
        if ($mins !== null) {
            $stmt = mysqli_prepare($con,
                "UPDATE attendances
                    SET exited_at     = NOW(),
                        duration_mins = GREATEST(COALESCE(duration_mins, 0), ?),
                        updated_at    = NOW()
                  WHERE attendance_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $mins, $aid);
        } else {
            $stmt = mysqli_prepare($con,
                "UPDATE attendances SET exited_at=NOW(), updated_at=NOW() WHERE attendance_id=?"
            );
            mysqli_stmt_bind_param($stmt, 'i', $aid);
        }

        mysqli_stmt_execute($stmt);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Claim anonymous record after registration ─────────────────────────────
    $vid  = isset($data['visitor_id'])    ? (int)$data['visitor_id']    : 0;
    $name = trim($data['visitor_name'] ?? '');

    if ($vid <= 0) { echo json_encode(['error' => 'missing_params']); exit; }

    $chk = mysqli_prepare($con,
        "SELECT attendance_id FROM attendances WHERE attendance_id=? AND visit_date=? AND visitor_id IS NULL LIMIT 1"
    );
    mysqli_stmt_bind_param($chk, 'is', $aid, $today);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);

    if (mysqli_stmt_num_rows($chk) === 0) {
        echo json_encode(['ok' => true, 'claimed' => false]); exit;
    }

    $stmt = mysqli_prepare($con,
        "UPDATE attendances SET visitor_id=?, visitor_name=?, method='registered', updated_at=NOW() WHERE attendance_id=?"
    );
    mysqli_stmt_bind_param($stmt, 'isi', $vid, $name, $aid);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true, 'claimed' => true]);
    exit;
}

// ── POST: Log attendance ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $vid      = isset($data['visitor_id']) ? (int)$data['visitor_id'] : null;
    $name     = trim($data['visitor_name'] ?? '');
    $lat      = isset($data['latitude'])   ? (float)$data['latitude']  : null;
    $lng      = isset($data['longitude'])  ? (float)$data['longitude'] : null;
    $accuracy = isset($data['accuracy'])   ? (int)$data['accuracy']    : null;
    $method   = 'geofence';
    $today    = date('Y-m-d');

    // Resolve visitor name from DB if visitor_id given
    if ($vid !== null && $vid > 0) {
        $chk = mysqli_prepare($con,
            "SELECT visitor_id, CONCAT(first_name,' ',last_name) as full_name FROM visitors WHERE visitor_id=? LIMIT 1"
        );
        mysqli_stmt_bind_param($chk, 'i', $vid);
        mysqli_stmt_execute($chk);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        if ($row) {
            $name = $row['full_name'];
        } else {
            $vid = null;
        }
    } else {
        $vid = null;
    }

    // Check if already logged today (match by visitor_id if known, or any anonymous record from same IP)
    if ($vid !== null) {
        $chk2 = mysqli_prepare($con,
            "SELECT attendance_id FROM attendances WHERE visitor_id=? AND visit_date=? LIMIT 1"
        );
        mysqli_stmt_bind_param($chk2, 'is', $vid, $today);
        mysqli_stmt_execute($chk2);
        $chk2res = mysqli_fetch_assoc(mysqli_stmt_get_result($chk2));
        if ($chk2res) {
            echo json_encode(['ok' => true, 'already_logged' => true, 'attendance_id' => (int)$chk2res['attendance_id']]);
            exit;
        }
    }

    $stmt = mysqli_prepare($con,
        "INSERT INTO attendances (visitor_id, visitor_name, latitude, longitude, accuracy, method, visit_date, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    mysqli_stmt_bind_param($stmt, 'isdddss', $vid, $name, $lat, $lng, $accuracy, $method, $today);

    if (mysqli_stmt_execute($stmt)) {
        $aid = mysqli_insert_id($con);
        echo json_encode(['ok' => true, 'already_logged' => false, 'attendance_id' => $aid]);
    } else {
        error_log('Attendance insert error: ' . mysqli_error($con));
        echo json_encode(['error' => 'failed']);
    }
    exit;
}

// ── GET: Daily count ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $today = date('Y-m-d');
    $r  = mysqli_query($con, "SELECT COUNT(*) c FROM attendances WHERE visit_date='$today'");
    $r2 = mysqli_query($con, "SELECT COUNT(*) c FROM attendances");
    echo json_encode([
        'today' => (int)mysqli_fetch_assoc($r)['c'],
        'total' => (int)mysqli_fetch_assoc($r2)['c'],
    ]);
    exit;
}
