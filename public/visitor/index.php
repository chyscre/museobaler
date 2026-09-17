<?php
// Entry point for QR codes on the display cases: /visitor/?scan=EXH-2026-001.
// Hands the code to the app through sessionStorage and gets out of the way.
$scan  = isset($_GET['scan'])  ? htmlspecialchars(trim($_GET['scan']), ENT_QUOTES) : '';
// ?debug=1 draws the viewport badge — carry it across the redirect so it can be
// used from a QR link while testing on a real handset.
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2D5016">
<title>Museo de Baler</title>
<style>html,body{height:100%;margin:0;background:#2D5016}</style>
</head>
<body>
<script>
<?php if ($scan): ?>sessionStorage.setItem('mb_pending_scan', '<?php echo $scan; ?>');
<?php endif; ?>window.location.replace('./index.html<?php echo $debug ? '?debug=1' : ''; ?>');
</script>
</body>
</html>
