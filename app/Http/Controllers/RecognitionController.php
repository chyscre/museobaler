<?php

namespace App\Http\Controllers;

use App\Models\Exhibit;
use App\Models\ExhibitTrainingImage;
use App\Models\Log;
use App\Services\Recognition;
use App\Support\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Exhibit recognition, kept inside the panel.
 *
 * Three screens. The panel (index) says what the model on disk knows, which
 * exhibits have enough photos and which do not, and holds the Train button.
 * The photo page is the one that runs on a phone - it is where the staff
 * stand in front of the exhibit and shoot. And the dataset/model pair is the
 * plumbing the browser uses while training: it pulls every photo down, and
 * pushes the finished model back up.
 *
 * Training itself happens in the admin's browser. Nothing here does any
 * machine learning; the server only stores photos and files.
 */
class RecognitionController extends Controller
{
    private const PHOTO_RULE = 'required|image|mimes:jpeg,jpg,png,webp|max:15360';

    public function index()
    {
        $exhibits = Exhibit::where('status', true)
            ->withCount('trainingImages')
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get();

        $backgroundCount = ExhibitTrainingImage::whereNull('exhibit_id')->count();
        $model  = Recognition::currentModel();
        $labels = $model ? array_map('strtoupper', $model['labels']) : [];

        // The two things the panel has to be able to say: this exhibit is not
        // in the model yet, and the model still knows exhibits that are gone.
        $exhibits->each(function ($e) use ($labels) {
            $e->in_model = in_array(strtoupper($e->exhibit_code), $labels, true);
        });
        $activeCodes = $exhibits->pluck('exhibit_code')->map(fn ($c) => strtoupper($c))->all();
        $stale = $model
            ? array_values(array_filter($model['labels'], fn ($l) =>
                strtoupper($l) !== strtoupper(Recognition::BACKGROUND)
                && !in_array(strtoupper($l), $activeCodes, true)))
            : [];

        return view('recognition.index', [
            'exhibits'        => $exhibits,
            'backgroundCount' => $backgroundCount,
            'model'           => $model,
            'stale'           => $stale,
            'missing'         => $exhibits->filter(fn ($e) => !$e->in_model)->values(),
            'thin'            => $exhibits->filter(fn ($e) => $e->training_images_count < Recognition::MIN_PHOTOS)->values(),
            'minPhotos'       => Recognition::MIN_PHOTOS,
            'goodPhotos'      => Recognition::GOOD_PHOTOS,
        ]);
    }

    /** One exhibit's photos, or the Background set when no exhibit is given. */
    public function photos(Request $request, ?Exhibit $exhibit = null)
    {
        $photos = $this->photosFor($exhibit)->latest('training_image_id')->get();

        return view('recognition.photos', [
            'exhibit'    => $exhibit,
            'photos'     => $photos,
            'minPhotos'  => Recognition::MIN_PHOTOS,
            'goodPhotos' => Recognition::GOOD_PHOTOS,
            // Shooting happens on a phone, so this page is the second one
            // that drops the panel chrome - see DesktopOnly.
            'layout'     => Device::isPhone($request->userAgent()) ? 'layouts.mobile' : 'layouts.admin',
        ]);
    }

    public function upload(Request $request, ?Exhibit $exhibit = null)
    {
        $request->validate(['photos' => 'required|array|min:1', 'photos.*' => self::PHOTO_RULE], [
            'photos.required' => 'Take or choose at least one photo.',
            'photos.*.image'  => 'One of the files is not a photo.',
            'photos.*.max'    => 'One of the photos is over 15 MB.',
        ]);

        $saved = [];
        foreach ($request->file('photos') as $file) {
            try {
                $name = Recognition::storePhoto($file, $exhibit);
            } catch (RuntimeException $e) {
                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
                }
                return back()->with('error', $e->getMessage());
            }
            $saved[] = ExhibitTrainingImage::create([
                'exhibit_id' => $exhibit?->exhibit_id,
                'filename'   => $name,
            ]);
        }

        $label = $exhibit ? $exhibit->exhibit_code : Recognition::BACKGROUND;
        $this->log('Recognition photos added', count($saved) . ' photo(s) for ' . $label);

        if ($request->expectsJson()) {
            return response()->json([
                'ok'     => true,
                'photos' => array_map(fn ($p) => ['id' => $p->training_image_id, 'url' => $p->url], $saved),
                'count'  => $this->photosFor($exhibit)->count(),
            ]);
        }

        return back()->with('success', count($saved) . ' photo(s) added.');
    }

    public function destroyPhoto(Request $request, ExhibitTrainingImage $photo)
    {
        Recognition::deletePhoto($photo);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Photo removed.');
    }

    /**
     * Everything the trainer needs, as one JSON: a class per active exhibit
     * that has photos, plus the Background set. Classes are named by exhibit
     * code because that is what the visitor app looks up after a match.
     */
    public function dataset(): JsonResponse
    {
        $exhibits = Exhibit::where('status', true)
            ->with('trainingImages')
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get();

        $classes = $exhibits->map(fn ($e) => [
            'label'  => $e->exhibit_code,
            'name'   => $e->name,
            'photos' => $e->trainingImages->map->url->values(),
        ]);

        $background = ExhibitTrainingImage::whereNull('exhibit_id')->get();
        $classes->push([
            'label'  => Recognition::BACKGROUND,
            'name'   => 'Background (not an exhibit)',
            'photos' => $background->map->url->values(),
        ]);

        return response()->json(['classes' => $classes->values()]);
    }

    /** The browser hands back the three files it trained. */
    public function saveModel(Request $request): JsonResponse
    {
        $request->validate([
            'model_json'    => 'required|file|max:8192',
            'weights'       => 'required|file|max:32768',
            'metadata_json' => 'required|file|max:1024',
        ]);

        $modelJson = (string) file_get_contents($request->file('model_json')->getRealPath());
        $metaJson  = (string) file_get_contents($request->file('metadata_json')->getRealPath());
        $weights   = (string) file_get_contents($request->file('weights')->getRealPath());

        $model = json_decode($modelJson, true);
        $meta  = json_decode($metaJson, true);

        // Refuse anything that would not load in the visitor app rather than
        // knock out a working model with a broken one.
        if (!is_array($model) || empty($model['modelTopology']) || empty($model['weightsManifest'])) {
            return response()->json(['ok' => false, 'message' => 'model.json is not a TensorFlow.js model.'], 422);
        }
        if (!is_array($meta) || !is_array($meta['labels'] ?? null) || count($meta['labels']) < 2) {
            return response()->json(['ok' => false, 'message' => 'metadata.json has no class labels.'], 422);
        }
        if ($weights === '') {
            return response()->json(['ok' => false, 'message' => 'weights.bin is empty.'], 422);
        }

        try {
            Recognition::writeModel($modelJson, $weights, $metaJson);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $labels = array_map('strval', $meta['labels']);
        $this->log('Recognition model trained', count($labels) . ' classes: ' . implode(', ', $labels));

        return response()->json(['ok' => true, 'labels' => $labels]);
    }

    private function photosFor(?Exhibit $exhibit)
    {
        return ExhibitTrainingImage::query()->when(
            $exhibit,
            fn ($q) => $q->where('exhibit_id', $exhibit->exhibit_id),
            fn ($q) => $q->whereNull('exhibit_id'),
        );
    }

    private function log(string $action, string $details): void
    {
        Log::create([
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'role'       => auth()->user()->role ?? 'Administrator',
            'action'     => $action,
            'details'    => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
