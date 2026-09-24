<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exhibit;
use App\Models\ExhibitImage;
use App\Models\ExhibitTranslation;
use Illuminate\Http\JsonResponse;
use App\Support\ExhibitImage as ExhibitImageFile;
use Illuminate\Http\Request;

/**
 * The exhibits, as the app renders them.
 *
 * SECURITY: behind the admission gate. Exhibit records, descriptions, fun
 * facts and audio guides ARE the product the admission fee pays for, so
 * this is where the gate has to bite (routes/api.php, visitor.cleared).
 *
 * Text comes from the translation for the requested language when one
 * exists, else the exhibit's own. Media URLs are root-relative to wherever
 * the app is being served from - localhost, the LAN address, a tunnel -
 * rather than APP_URL, which names only one of those.
 */
class ExhibitController extends Controller
{
    /** GET /api/v1/exhibits?lang=en - every active exhibit in storyline order. */
    public function index(Request $request): JsonResponse
    {
        $lang = $this->lang($request);

        $exhibits = Exhibit::query()
            ->where('status', true)
            ->with(['category', 'museumHall', 'translations' => fn ($q) => $q->where('language_code', $lang)])
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Exhibit $e) => $this->summary($e, $request));

        return response()->json($exhibits);
    }

    /** GET /api/v1/exhibits/{code}?lang=en - one exhibit, for a scanned label. */
    public function show(Request $request, string $code): JsonResponse
    {
        $lang = $this->lang($request);

        $exhibit = Exhibit::query()
            ->where('status', true)
            ->where('exhibit_code', $code)
            ->with(['category', 'museumHall', 'translations' => fn ($q) => $q->where('language_code', $lang), 'images'])
            ->first();

        if ($exhibit === null) {
            return response()->json(['error' => 'not_found']);
        }

        $next = Exhibit::query()
            ->where('status', true)
            ->where('storyline_order', $exhibit->storyline_order + 1)
            ->value('exhibit_id');

        return response()->json($this->summary($exhibit, $request) + [
            'original_name' => $exhibit->name,
            'gallery'       => $exhibit->images
                ->sortBy([['sort_order', 'asc'], ['image_id', 'asc']])
                ->values()
                ->map(fn (ExhibitImage $g) => [
                    'filename' => $g->filename,
                    'url'      => $this->imageUrl($request, $g->filename),
                    'thumb'    => $this->imageUrl($request, $g->filename, ExhibitImageFile::THUMB),
                    'caption'  => $g->caption,
                ]),
            'scan_count'    => $exhibit->scans()->count(),
            'next_id'       => $next ? (int) $next : null,
        ]);
    }

    /** The fields both the list and the single record carry. */
    private function summary(Exhibit $e, Request $request): array
    {
        /** @var ExhibitTranslation|null $t */
        $t = $e->translations->first();

        return [
            'exhibit_id'      => (int) $e->exhibit_id,
            'exhibit_code'    => $e->exhibit_code,
            'name'            => $t?->title ?: $e->name,
            'description'     => $t?->description ?: $e->description,
            'fun_facts'       => $this->facts($t?->fun_facts ?: $e->fun_facts),
            'category'        => $e->category?->name,
            'floor'           => $e->floor,
            'hall'            => $e->hall,
            'authors'         => $e->authors,
            'languages'       => explode(',', (string) $e->languages),
            'storyline_order' => (int) $e->storyline_order,
            'map_x'           => $e->map_x,
            'map_y'           => $e->map_y,
            'year'            => $e->date_published?->format('Y') ?? '',
            'image'           => $this->imageUrl($request, $e->image),
            // List rows and cards only ever draw this at 44-130px.
            'thumb'           => $this->imageUrl($request, $e->image, ExhibitImageFile::THUMB),
            'audio_file'      => $t?->audio_file,
            'audio_url'       => $t?->audio_file ? $request->getBasePath() . '/audio/' . rawurlencode($t->audio_file) : null,
        ];
    }

    /** One fact per line in the admin form; blank lines are not facts. */
    private function facts(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    /**
     * The picture the app should actually download.
     *
     * The originals are camera files — 6000x4000, up to 9.8 MB — so the app is
     * given the ~1280px display copy, or the ~400px thumbnail for list rows.
     * App\Support\ExhibitImage falls back to the original when a derivative
     * has not been built, so this degrades to slow rather than to broken.
     */
    private function imageUrl(Request $request, ?string $file, string $variant = ExhibitImageFile::DISPLAY): ?string
    {
        $path = ExhibitImageFile::variantPath($file, $variant);

        return $path
            ? $request->getBasePath() . '/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            : null;
    }

    private function lang(Request $request): string
    {
        $lang = (string) $request->query('lang', 'en');

        return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $lang) ? $lang : 'en';
    }
}
