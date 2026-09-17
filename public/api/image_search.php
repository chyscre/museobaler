<?php
/**
 * Image Search API
 *
 * Accepts a JPEG camera frame and returns the best-matching exhibit
 * using perceptual hashing (pHash). No frame is ever written to disk —
 * everything is processed in memory and discarded after the response.
 *
 * POST /api/image_search.php
 *   multipart field: frame  (JPEG, max 1 MB)
 *   optional field:  visitor_id  (int)
 *
 * Response:
 *   { exhibit_code, exhibit_id, confidence, candidates[] }
 */

header('Content-Type: application/json');

// SECURITY: allow-listed origins only (see _cors.php), and preflight ends here.
// This used to fall through to '*' for any unrecognised origin.
require_once '_cors.php';
apiCors('POST, OPTIONS');

// SECURITY: Rate limiting — image-search requests per minute per IP.
// This bucket is now separate from the other endpoints, so camera sampling can
// no longer starve ordinary browsing. The old ceiling of 30 was set when every
// request re-hashed every exhibit photo; with reference hashes cached, a
// request costs one frame hash, so the limit can sit high enough for several
// phones to share the museum's single NAT address. Visitors past that back off
// and resume rather than failing outright.
require_once '_rate_limit.php';
apiRateLimit(120, 60);

// ── Constants ─────────────────────────────────────────────────────────────────
const PHASH_SIZE       = 32;   // resize target (32x32 before DCT)
const PHASH_BITS       = 64;   // hash length in bits (8x8 DCT output)
const MAX_FRAME_BYTES  = 1048576; // 1 MB
// Confidence is rescaled before it leaves this file so that it means the same
// thing as the on-device model's probability — see normaliseConfidence().
// These two match LOW_CONF and HIGH_CONF in the visitor app, which applies
// one set of thresholds to whichever engine is running.
const MIN_CONFIDENCE   = 0.45; // below this → not even offered as a candidate
const HIGH_CONFIDENCE  = 0.75; // at or above → the app navigates on its own
const MAX_CANDIDATES   = 3;
const IMG_BASE_PATH    = __DIR__ . '/../images/exhibits/';

// ── Request validation ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

if (!isset($_FILES['frame'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_frame']);
    exit;
}

$file = $_FILES['frame'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'upload_error', 'code' => $file['error']]);
    exit;
}

if ($file['size'] > MAX_FRAME_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'frame_too_large']);
    exit;
}

// Validate MIME — must be an image
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_image_type']);
    exit;
}

// ── Load frame into memory (never written to a permanent path) ────────────────
$frameData = file_get_contents($file['tmp_name']);
// Immediately remove the temp upload — we work entirely in memory from here
@unlink($file['tmp_name']);

$frameImg = @imagecreatefromstring($frameData);
unset($frameData); // free memory

if (!$frameImg) {
    http_response_code(400);
    echo json_encode(['error' => 'unreadable_frame']);
    exit;
}

// ── Compute pHash of submitted frame ─────────────────────────────────────────
$frameHash = computePhash($frameImg);
imagedestroy($frameImg);

// ── Load exhibit images from DB ───────────────────────────────────────────────
include '../auth/db.php';

/**
 * SECURITY: The admission gate — see exhibits.php. Point-and-identify is a
 * paid feature of the tour, so it needs the same clearance check; without it a
 * visitor who never paid could still walk the museum identifying exhibits.
 */
require_once '_visitor_auth.php';
requireClearedVisitor($con);

// Fetch all active exhibits with their primary image and gallery images
$stmt = mysqli_prepare($con,
    "SELECT e.exhibit_id, e.exhibit_code, e.name, e.image as primary_image
     FROM exhibits e
     WHERE e.status = 1 AND e.image IS NOT NULL AND e.image != ''
     ORDER BY e.exhibit_id ASC"
);
mysqli_stmt_execute($stmt);
$exhibits = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Also load gallery images
$galleryStmt = mysqli_prepare($con,
    "SELECT ei.exhibit_id, ei.filename
     FROM exhibit_images ei
     INNER JOIN exhibits e ON e.exhibit_id = ei.exhibit_id
     WHERE e.status = 1
     ORDER BY ei.sort_order ASC, ei.image_id ASC"
);
mysqli_stmt_execute($galleryStmt);
$galleryRows = mysqli_fetch_all(mysqli_stmt_get_result($galleryStmt), MYSQLI_ASSOC);

// Group gallery images by exhibit_id
$galleryByExhibit = [];
foreach ($galleryRows as $row) {
    $galleryByExhibit[$row['exhibit_id']][] = $row['filename'];
}

// ── Match frame against each exhibit ─────────────────────────────────────────
// Reference hashes are cached across requests. Recomputing them per request
// meant re-reading and re-hashing every exhibit photo from disk on a loop that
// fires every couple of seconds per visitor, and the DCT is the expensive part
// (a few tens of thousands of cos() calls per image). The cache key includes
// the file's mtime and size, so replacing an exhibit photo invalidates just
// that entry on the next request — no restart, no manual clearing.
$hashCache  = loadHashCache();
$freshCache = [];

$results = [];

foreach ($exhibits as $exhibit) {
    $eid  = (int)$exhibit['exhibit_id'];
    $bestConfidence = 0.0;

    // Build list of images to try: primary first, then gallery
    $imagesToTry = [$exhibit['primary_image']];
    if (!empty($galleryByExhibit[$eid])) {
        $imagesToTry = array_merge($imagesToTry, $galleryByExhibit[$eid]);
    }

    foreach ($imagesToTry as $filename) {
        $path = IMG_BASE_PATH . $filename;

        // Also check Laravel storage path as fallback
        if (!file_exists($path)) {
            $path = __DIR__ . '/../../storage/app/public/exhibits/' . $filename;
        }
        if (!file_exists($path)) continue;

        $cacheKey = $path . '|' . filemtime($path) . '|' . filesize($path);

        if (isset($hashCache[$cacheKey])) {
            $refHash = (int) $hashCache[$cacheKey];
        } else {
            $refImg = @imagecreatefromstring(file_get_contents($path));
            if (!$refImg) continue;

            $refHash = computePhash($refImg);
            imagedestroy($refImg);
        }

        // Carried forward whether it was a hit or a miss, so entries for
        // images that no longer exist fall out instead of accumulating.
        $freshCache[$cacheKey] = $refHash;

        $confidence = normaliseConfidence(hashSimilarity($frameHash, $refHash));

        if ($confidence > $bestConfidence) {
            $bestConfidence = $confidence;
        }
    }

    if ($bestConfidence >= MIN_CONFIDENCE) {
        $results[] = [
            'exhibit_id'   => $eid,
            'exhibit_code' => $exhibit['exhibit_code'],
            'name'         => $exhibit['name'],
            'confidence'   => round($bestConfidence, 4),
        ];
    }
}

saveHashCache($freshCache);

// Sort by confidence descending
usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

// ── Build response ────────────────────────────────────────────────────────────
$topMatch   = $results[0] ?? null;
$candidates = array_slice($results, 0, MAX_CANDIDATES);

if (!$topMatch) {
    echo json_encode([
        'exhibit_code' => null,
        'exhibit_id'   => null,
        'confidence'   => 0,
        'candidates'   => [],
    ]);
    exit;
}

// ── Log the scan only when the match is strong enough to act on ──────────────
// The visitor app samples the camera every few seconds, so logging every match
// above the candidate floor wrote a row per sample — a visitor reading one
// label for a minute produced twenty-odd "views" of it and made engagement
// figures meaningless. Only the confidence that actually opens the exhibit
// counts as a visit; anything weaker is a suggestion the visitor may ignore,
// and if they do pick one from the candidate list the app logs that itself.
//
// We log regardless of visitor_id so aggregate counts stay accurate.
if ($topMatch['confidence'] >= HIGH_CONFIDENCE) {
    $vid = (int)($_POST['visitor_id'] ?? 0);
    $vidParam = $vid > 0 ? $vid : null;
    $matchId  = (int)$topMatch['exhibit_id'];
    $scanType = 'image';

    $ins = mysqli_prepare($con, "INSERT INTO scans (exhibit_id, visitor_id, scan_type) VALUES (?,?,?)");
    mysqli_stmt_bind_param($ins, 'iis', $matchId, $vidParam, $scanType);
    mysqli_stmt_execute($ins);
}

echo json_encode([
    'exhibit_code' => $topMatch['exhibit_code'],
    'exhibit_id'   => $topMatch['exhibit_id'],
    'confidence'   => $topMatch['confidence'],
    'candidates'   => array_map(fn($c) => [
        'exhibit_code' => $c['exhibit_code'],
        'name'         => $c['name'],
        'confidence'   => $c['confidence'],
    ], $candidates),
]);

// ── Perceptual Hash (pHash) Functions ─────────────────────────────────────────

/**
 * Compute a 64-bit perceptual hash of an image resource.
 *
 * Algorithm:
 *  1. Resize to 32x32 grayscale
 *  2. Apply a simplified DCT (Discrete Cosine Transform)
 *  3. Take the top-left 8x8 = 64 coefficients (low frequencies)
 *  4. Compare each coefficient to the median → 1 if above, 0 if below
 *  5. Pack into a 64-bit integer
 *
 * Images that look visually similar will produce hashes with a small
 * Hamming distance (few differing bits), even under different lighting,
 * scale, or compression.
 */
function computePhash($img): int
{
    // Step 1: resize to 32x32 grayscale
    $small = imagecreatetruecolor(PHASH_SIZE, PHASH_SIZE);
    imagecopyresampled($small, $img, 0, 0, 0, 0, PHASH_SIZE, PHASH_SIZE, imagesx($img), imagesy($img));

    // Convert to grayscale pixel grid
    $pixels = [];
    for ($y = 0; $y < PHASH_SIZE; $y++) {
        for ($x = 0; $x < PHASH_SIZE; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r   = ($rgb >> 16) & 0xFF;
            $g   = ($rgb >> 8)  & 0xFF;
            $b   =  $rgb        & 0xFF;
            // Luminance formula (matches human perception)
            $pixels[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        }
    }
    imagedestroy($small);

    // Step 2 & 3: Simplified 2D DCT, keep top-left 8x8
    $dct = simpleDct2d($pixels, PHASH_SIZE);

    // Flatten the 8x8 top-left region into a 1D array (skip [0][0] DC component)
    $freqs = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            if ($x === 0 && $y === 0) continue; // skip DC offset
            $freqs[] = $dct[$y][$x];
        }
    }

    // Step 4: median threshold
    $sorted = $freqs;
    sort($sorted);
    $median = $sorted[(int)(count($sorted) / 2)];

    // Step 5: pack to 64-bit int (use two 32-bit ints for PHP compatibility)
    $hash = 0;
    foreach ($freqs as $i => $val) {
        if ($val >= $median) {
            $hash |= (1 << $i);
        }
    }

    return $hash;
}

/**
 * Simplified row-by-row 2D DCT.
 * Fast enough for 32x32 images; not a full 2D DCT but sufficient for pHash.
 */
function simpleDct2d(array $pixels, int $size): array
{
    // Apply DCT to each row
    $rowDct = [];
    for ($y = 0; $y < $size; $y++) {
        $rowDct[$y] = dct1d($pixels[$y], $size);
    }

    // Apply DCT to each column
    $result = [];
    for ($x = 0; $x < $size; $x++) {
        $col = [];
        for ($y = 0; $y < $size; $y++) {
            $col[$y] = $rowDct[$y][$x];
        }
        $colDct = dct1d($col, $size);
        for ($y = 0; $y < $size; $y++) {
            $result[$y][$x] = $colDct[$y];
        }
    }

    return $result;
}

/**
 * 1D DCT-II (the standard DCT used in JPEG).
 */
function dct1d(array $input, int $n): array
{
    $output = [];
    for ($k = 0; $k < $n; $k++) {
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $input[$i] * cos(M_PI * $k * (2 * $i + 1) / (2 * $n));
        }
        $output[$k] = $sum * ($k === 0 ? sqrt(1 / $n) : sqrt(2 / $n));
    }
    return $output;
}

/**
 * Convert Hamming distance between two 64-bit hashes into a 0.0–1.0
 * confidence score. Distance 0 = identical (1.0), distance 63 = (0.0).
 *
 * We use 63 bits (not 64) because we skip the DC component, so the
 * maximum possible distance is 63.
 */
function hashSimilarity(int $a, int $b): float
{
    $xor      = $a ^ $b;
    $distance = 0;

    // Count set bits (popcount)
    while ($xor) {
        $distance += $xor & 1;
        $xor >>= 1;
    }

    return max(0.0, 1.0 - ($distance / 63));
}

/**
 * Rescale a raw hash similarity into a 0.0–1.0 confidence.
 *
 * Raw similarity is not a confidence and must never be compared against one.
 * Each bit of a pHash is an independent above/below-median decision, so two
 * completely unrelated images agree on about half their bits: chance sits at
 * roughly 0.5, not 0. A raw 0.40 is therefore *worse than a coin flip* — the
 * old floor let pure noise through as a match, which is a large part of why
 * this matcher produced confident-looking nonsense.
 *
 * Mapping [0.5 … 1.0] onto [0.0 … 1.0] makes 0 mean "no better than chance"
 * and 1 mean "identical", which is what the visitor app's shared thresholds
 * assume — and what the on-device model's probabilities already mean.
 */
function normaliseConfidence(float $similarity): float
{
    return max(0.0, min(1.0, ($similarity - 0.5) * 2.0));
}

/** Path to the cross-request cache of reference-image hashes. */
function hashCachePath(): string
{
    return sys_get_temp_dir() . '/museobaler_phash_cache.json';
}

function loadHashCache(): array
{
    $file = hashCachePath();
    if (!is_file($file)) return [];

    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : [];
}

function saveHashCache(array $cache): void
{
    if (!$cache) return;
    @file_put_contents(hashCachePath(), json_encode($cache), LOCK_EX);
}
