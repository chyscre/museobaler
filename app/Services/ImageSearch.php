<?php

namespace App\Services;

use App\Models\Exhibit;
use GdImage;

/**
 * Point-and-identify without the on-device model: a perceptual hash of the
 * camera frame against a hash of every exhibit photo.
 *
 * pHash: resize to 32x32 grey, DCT, keep the low-frequency 8x8, threshold
 * each coefficient at the median, pack the bits. Images that look alike
 * hash alike - a small Hamming distance - even under different lighting,
 * scale or compression. Reference hashes are cached across requests, keyed
 * on the file's mtime and size, because the DCT is the expensive part and
 * the app samples the camera every few seconds per visitor.
 *
 * Confidence is rescaled so it means the same as the on-device model's
 * probability: each hash bit is an independent above/below-median call, so
 * two unrelated images agree on about half of them and raw similarity sits
 * near 0.5 by chance. Mapping [0.5 .. 1.0] onto [0 .. 1] makes 0 "no better
 * than chance", which is what the app's shared thresholds assume.
 *
 * Ported from public/api/image_search.php, byte for byte where it counts.
 */
class ImageSearch
{
    public const SIZE            = 32;
    public const MIN_CONFIDENCE  = 0.45; // below this: not even a candidate
    public const HIGH_CONFIDENCE = 0.75; // at or above: the app navigates on its own
    public const MAX_CANDIDATES  = 3;

    /**
     * Rank active exhibits by how much their photos look like $frame.
     *
     * @return array<int, array{exhibit_id:int, exhibit_code:string, name:string, confidence:float}>
     *         Best first, only those at or above MIN_CONFIDENCE.
     */
    public function match(GdImage $frame): array
    {
        $frameHash = self::phash($frame);

        $exhibits = Exhibit::query()
            ->where('status', true)
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('image_id')])
            ->orderBy('exhibit_id')
            ->get();

        $cache = $this->loadCache();
        $fresh = [];
        $results = [];

        foreach ($exhibits as $exhibit) {
            $best  = 0.0;
            $files = array_merge([$exhibit->image], $exhibit->images->pluck('filename')->all());

            foreach ($files as $filename) {
                $path = public_path('images/exhibits/' . $filename);
                if (!is_file($path)) {
                    continue;
                }

                $key = $path . '|' . filemtime($path) . '|' . filesize($path);

                if (isset($cache[$key])) {
                    $refHash = (int) $cache[$key];
                } else {
                    $ref = @imagecreatefromstring((string) file_get_contents($path));
                    if (!$ref) {
                        continue;
                    }
                    $refHash = self::phash($ref);
                    imagedestroy($ref);
                }

                // Carried forward hit or miss, so entries for photos that no
                // longer exist fall out instead of accumulating.
                $fresh[$key] = $refHash;

                $best = max($best, self::confidence(self::similarity($frameHash, $refHash)));
            }

            if ($best >= self::MIN_CONFIDENCE) {
                $results[] = [
                    'exhibit_id'   => (int) $exhibit->exhibit_id,
                    'exhibit_code' => $exhibit->exhibit_code,
                    'name'         => $exhibit->name,
                    'confidence'   => round($best, 4),
                ];
            }
        }

        $this->saveCache($fresh);

        usort($results, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $results;
    }

    // -- The hash --------------------------------------------------------------

    /** A 63-bit perceptual hash (the DC coefficient is skipped). */
    public static function phash(GdImage $img): int
    {
        $n     = self::SIZE;
        $small = imagecreatetruecolor($n, $n);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $n, $n, imagesx($img), imagesy($img));

        $pixels = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                // Luminance, weighted the way the eye is.
                $pixels[$y][$x] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }
        imagedestroy($small);

        $dct = self::dct2d($pixels, $n);

        $freqs = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                if ($x === 0 && $y === 0) {
                    continue; // DC offset says nothing about shape
                }
                $freqs[] = $dct[$y][$x];
            }
        }

        $sorted = $freqs;
        sort($sorted);
        $median = $sorted[(int) (count($sorted) / 2)];

        $hash = 0;
        foreach ($freqs as $i => $val) {
            if ($val >= $median) {
                $hash |= (1 << $i);
            }
        }

        return $hash;
    }

    /** Row-then-column DCT-II; enough for a 32x32 thumbnail. */
    private static function dct2d(array $pixels, int $n): array
    {
        $rows = [];
        for ($y = 0; $y < $n; $y++) {
            $rows[$y] = self::dct1d($pixels[$y], $n);
        }

        $out = [];
        for ($x = 0; $x < $n; $x++) {
            $col = [];
            for ($y = 0; $y < $n; $y++) {
                $col[$y] = $rows[$y][$x];
            }
            $colDct = self::dct1d($col, $n);
            for ($y = 0; $y < $n; $y++) {
                $out[$y][$x] = $colDct[$y];
            }
        }

        return $out;
    }

    private static function dct1d(array $input, int $n): array
    {
        $out = [];
        for ($k = 0; $k < $n; $k++) {
            $sum = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $sum += $input[$i] * cos(M_PI * $k * (2 * $i + 1) / (2 * $n));
            }
            $out[$k] = $sum * ($k === 0 ? sqrt(1 / $n) : sqrt(2 / $n));
        }

        return $out;
    }

    /** 1.0 for identical hashes, 0.0 for every one of the 63 bits differing. */
    public static function similarity(int $a, int $b): float
    {
        $xor      = $a ^ $b;
        $distance = 0;
        while ($xor) {
            $distance += $xor & 1;
            $xor >>= 1;
        }

        return max(0.0, 1.0 - ($distance / 63));
    }

    /** Raw similarity rescaled so chance is 0, not 0.5 - see the class note. */
    public static function confidence(float $similarity): float
    {
        return max(0.0, min(1.0, ($similarity - 0.5) * 2.0));
    }

    // -- The reference-hash cache ------------------------------------------------

    public static function cachePath(): string
    {
        return storage_path('app/api-cache/phash_cache.json');
    }

    private function loadCache(): array
    {
        $file = self::cachePath();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveCache(array $cache): void
    {
        if (!$cache) {
            return;
        }
        $dir = dirname(self::cachePath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::cachePath(), json_encode($cache), LOCK_EX);
    }
}
