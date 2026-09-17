<?php
header('Content-Type: application/json');

// SECURITY: allow-listed origins only, and preflight ends here.
require_once '_cors.php';
apiCors('GET, OPTIONS');

// SECURITY: Rate limiting — max 60 requests per minute per IP
require_once '_rate_limit.php';
apiRateLimit(60, 60);

include '../auth/db.php';

$result = mysqli_query($con,
    "SELECT notif_id, title, body, type, created_at FROM notifications WHERE is_active=1 ORDER BY created_at DESC LIMIT 20"
);
$notifs = [];
while ($r = mysqli_fetch_assoc($result)) {
    $notifs[] = [
        'id'         => (int)$r['notif_id'],
        'title'      => $r['title'],
        'body'       => $r['body'],
        'type'       => $r['type'],
        'created_at' => $r['created_at'],
    ];
}
echo json_encode($notifs);
