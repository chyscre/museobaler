<?php
// Entry point for QR codes on the display cases: /visitor/?scan=EXH-2026-001.
// Hands the code to the app through sessionStorage and gets out of the way.
//
// The code is reduced to the characters an exhibit code is made of, which is
// the same rule the service worker applies when it answers this page from the
// cache (sw.js, scanRedirect). htmlspecialchars() was doing this job before
// and did block injection - entities are not decoded inside a <script> - but
// it leaves a backslash alone, and a trailing one escapes the quote that ends
// the string below. The script then dies of a syntax error before it reaches
// the redirect, so a smudged or mistyped label opened a blank green page with
// no way forward. A whitelist cannot produce that, and there is nothing left
// in it to escape.
$scan  = isset($_GET['scan']) ? preg_replace('/[^A-Za-z0-9._-]/', '', trim($_GET['scan'])) : '';
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
