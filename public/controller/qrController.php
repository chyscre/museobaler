<?php
// Suppress all errors/warnings so nothing corrupts PNG output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once dirname(__DIR__) . '/phpqrcode/phpqrcode.php';

/* ── SINGLE QR IMAGE  ?exhibit_id=N ─────────────────────────────────────────
   Returns a raw PNG — used as <img src="?exhibit_id=N">
   ─────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['exhibit_id'])) {

    $id = (int) $_GET['exhibit_id'];

    // Scan URL — built dynamically so it works on localhost, LAN IP, ngrok, etc.
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scanUrl = $scheme . '://' . $host . '/yeppie/museobaler/public/visitor/index.php?scan=';

    // Fetch exhibit code from DB
    include_once dirname(__DIR__) . '/auth/db.php';
    $stmt = mysqli_prepare($con, 'SELECT exhibit_code FROM exhibits WHERE exhibit_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row  = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $code = $row ? $row['exhibit_code'] : 'EXH-' . $id;

    $qrData = $scanUrl . urlencode($code);

    // Capture PNG output
    ob_start();
    QRcode::png($qrData, false, QR_ECLEVEL_H, 8, 2);
    $png = ob_get_clean();

    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: public, max-age=3600');
    echo $png;
    exit;
}

/* ── SINGLE QR PRINT PAGE  ?print=N ─────────────────────────────────────────
   Full HTML page with one QR card + print button
   ─────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['print'])) {

    include_once dirname(__DIR__) . '/auth/db.php';

    $id   = (int) $_GET['print'];
    $stmt = mysqli_prepare($con, 'SELECT exhibit_id, exhibit_code, name, hall, floor FROM exhibits WHERE exhibit_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $ex = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$ex) { echo 'Exhibit not found'; exit; }

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qrUrl   = htmlspecialchars($scheme . '://' . $host . '/yeppie/museobaler/public/visitor/index.php?scan=' . urlencode($ex['exhibit_code']));
    $imgSrc  = '?exhibit_id=' . $ex['exhibit_id'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QR — <?= htmlspecialchars($ex['exhibit_code']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Instrument Sans',sans-serif;background:#f9fafb;display:flex;flex-direction:column;align-items:center;padding:32px;min-height:100vh}
.toolbar{display:flex;gap:10px;margin-bottom:28px;align-self:flex-start}
.btn{padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn-green{background:#16a34a;color:#fff}
.btn-outline{background:#fff;color:#374151;border:1.5px solid #e5e7eb}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px 40px;text-align:center;max-width:360px;width:100%}
.card img{width:240px;height:240px;display:block;margin:0 auto}
.name{font-family:'Young Serif',serif;font-size:20px;color:#1a1a1a;margin-top:18px;line-height:1.3}
.code{font-size:13px;color:#16a34a;font-weight:700;margin-top:6px}
.loc{font-size:12px;color:#9ca3af;margin-top:4px}
.url{font-size:9px;color:#d1d5db;margin-top:10px;word-break:break-all;max-width:280px}
@media print{.toolbar{display:none}body{background:#fff;padding:20px}}
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn btn-green" onclick="window.print()">Print QR</button>
  <a href="?print_all=1" class="btn btn-outline" target="_blank">Print All QRs</a>
  <a href="javascript:window.close()" class="btn btn-outline">Close</a>
</div>
<div class="card">
  <img src="<?= $imgSrc ?>" width="240" height="240" alt="QR Code">
  <div class="name"><?= htmlspecialchars($ex['name']) ?></div>
  <div class="code"><?= htmlspecialchars($ex['exhibit_code']) ?></div>
  <div class="loc"><?= htmlspecialchars($ex['floor']) ?> · <?= htmlspecialchars($ex['hall']) ?></div>
  <div class="url"><?= $qrUrl ?></div>
</div>
</body>
</html>
    <?php
    exit;
}

/* ── ALL QR PRINT PAGE  ?print_all=1 ────────────────────────────────────────
   Grid of all active exhibit QR codes
   ─────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['print_all'])) {

    include_once dirname(__DIR__) . '/auth/db.php';

    $exhibits = mysqli_query($con,
        'SELECT exhibit_id, exhibit_code, name, hall, floor
         FROM exhibits
         WHERE status = 1
         ORDER BY storyline_order ASC, name ASC'
    );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All QR Codes — Museo de Baler</title>
<link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Instrument Sans',sans-serif;background:#f9fafb;padding:32px}
h1{font-family:'Young Serif',serif;font-size:22px;color:#1a1a1a;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:24px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.qr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;text-align:center;break-inside:avoid}
.qr-card img{width:180px;height:180px;display:block;margin:0 auto}
.qr-name{font-family:'Young Serif',serif;font-size:14px;color:#1a1a1a;margin-top:10px;line-height:1.3}
.qr-code{font-size:11px;color:#16a34a;font-weight:700;margin-top:4px}
.qr-loc{font-size:11px;color:#9ca3af;margin-top:2px}
.toolbar{display:flex;gap:10px;align-items:center;margin-bottom:24px}
.btn{padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn-green{background:#16a34a;color:#fff}
.btn-outline{background:#fff;color:#374151;border:1.5px solid #e5e7eb}
@media print{.toolbar{display:none}body{background:#fff;padding:16px}.grid{gap:12px}}
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn btn-green" onclick="window.print()">Print All QR Codes</button>
  <a href="javascript:window.close()" class="btn btn-outline">Close</a>
</div>
<h1>Museo de Baler — Exhibit QR Codes</h1>
<p class="sub">Scan any code with the Museo de Baler visitor app.</p>
<div class="grid">
<?php while ($ex = mysqli_fetch_assoc($exhibits)): ?>
<div class="qr-card">
  <img src="?exhibit_id=<?= $ex['exhibit_id'] ?>" width="180" height="180" alt="QR">
  <div class="qr-name"><?= htmlspecialchars($ex['name']) ?></div>
  <div class="qr-code"><?= htmlspecialchars($ex['exhibit_code']) ?></div>
  <div class="qr-loc"><?= htmlspecialchars($ex['floor']) ?> · <?= htmlspecialchars($ex['hall']) ?></div>
</div>
<?php endwhile; ?>
</div>
</body>
</html>
    <?php
    exit;
}
