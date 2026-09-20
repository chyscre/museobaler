<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\ExhibitImage;
use App\Models\ExhibitTranslation;
use App\Models\Log;
use App\Services\ExhibitQr;
use App\Support\ExhibitLanguages;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExhibitController extends Controller
{
    public function index()
    {
        $exhibits   = Exhibit::with('category')
            ->withCount('scans')
            // Storyline first, then the code. Newest-first as the tiebreaker
            // put a freshly added exhibit at the top of its order group, which
            // read as "the new one jumped the queue".
            ->orderBy('storyline_order')
            ->orderBy('exhibit_code')
            ->get();
        $categories = Category::orderBy('name')->get();

        // Shown as the placeholder on the add form: what a blank order becomes.
        $nextOrder  = (int) Exhibit::max('storyline_order') + 1;

        return view('exhibits.index', compact('exhibits', 'categories', 'nextOrder'));
    }

    /**
     * Uploads go next to the seeded files, not into Laravel's storage disk.
     *
     * The seeded exhibits keep their pictures in public/images/exhibits/ and
     * their audio guides in public/audio/, and that is where everything that
     * displays them looks: the admin proxy routes, the visitor API, and the
     * visitor app. Writing new uploads to storage/app/public instead left them
     * behind a public/storage symlink that pointed at a machine this install
     * no longer lives on, so a freshly added exhibit saved fine and then
     * showed a broken image on both screens.
     */
    private const IMAGE_DIR = 'images/exhibits';
    private const AUDIO_DIR = 'audio';

    private const IMAGE_RULE = 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:10240';
    private const AUDIO_RULE = 'nullable|file|mimes:mp3,wav,ogg,m4a|max:20480';

    /**
     * Plain-English reasons for the refusals staff actually run into. The
     * defaults say things like "The image field must not be greater than
     * 10240 kilobytes", which reads as a bug rather than an instruction.
     */
    private const MESSAGES = [
        'exhibit_code.unique' => 'That exhibit code is already used by another exhibit. Pick a different one.',
        'image.image'         => 'The picture must be an image file (JPG, PNG, GIF or WebP).',
        'image.mimes'         => 'The picture must be a JPG, PNG, GIF or WebP. Phone photos saved as HEIC need converting first.',
        'image.max'           => 'The picture is too large. Keep it under 10 MB.',
        'images.*.image'      => 'Every gallery file must be an image (JPG, PNG, GIF or WebP).',
        'images.*.mimes'      => 'Gallery pictures must be JPG, PNG, GIF or WebP.',
        'images.*.max'        => 'One of the gallery pictures is over 10 MB.',
        'audio.mimes'         => 'The audio guide must be an MP3, WAV, OGG or M4A file.',
        'audio.max'           => 'The audio guide is too large. Keep it under 20 MB.',
        't_audio.*.mimes'     => 'Audio guides must be MP3, WAV, OGG or M4A files.',
        't_audio.*.max'       => 'One of the audio guides is over 20 MB.',
    ];

    public function store(Request $request)
    {
        $request->validate([
            'exhibit_code' => 'required|string|max:50|unique:exhibits,exhibit_code',
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string|max:5000',
            'fun_facts'    => 'nullable|string|max:3000',
            'category_id'  => 'nullable|exists:categories,category_id',
            'floor'        => 'nullable|string|max:50',
            'hall'         => 'nullable|string|max:100',
            'authors'      => 'nullable|string|max:300',
            'storyline_order' => 'nullable|integer|min:0',
            'image'        => self::IMAGE_RULE,
            't_audio.*'    => self::AUDIO_RULE,
        ], self::MESSAGES);

        $data = $request->only([
            'exhibit_code', 'name', 'description', 'fun_facts',
            'category_id', 'floor', 'hall', 'authors',
            'languages', 'storyline_order',
        ]);
        $data['date_published'] = now()->toDateString();

        // No order given means "after everything else". The form used to
        // default this to 0, which sorted every new exhibit ahead of EXH-001.
        if (empty($data['storyline_order'])) {
            $data['storyline_order'] = (int) Exhibit::max('storyline_order') + 1;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->saveImage($request->file('image'), $request->name);
        }

        $data['source_language'] = $request->input('source_language', 'en');

        $exhibit = Exhibit::create($data);

        // The label for the display case is ready the moment the exhibit is.
        $exhibit->update(['qr_file' => ExhibitQr::write($exhibit)]);

        $this->syncTranslations($request, $exhibit);

        $this->log('Exhibit Added', "Added exhibit: {$exhibit->name}");

        return redirect()->route('exhibits.index')->with('success', 'Exhibit added.');
    }

    public function show(Exhibit $exhibit)
    {
        $exhibit->load('category', 'translations', 'images');
        $exhibit->loadCount('scans');
        return view('exhibits.show', compact('exhibit'));
    }

    public function edit(Exhibit $exhibit)
    {
        $exhibit->load('translations', 'images');
        $categories = Category::orderBy('name')->get();
        return view('exhibits.edit', compact('exhibit', 'categories'));
    }

    public function update(Request $request, Exhibit $exhibit)
    {
        $request->validate([
            'exhibit_code' => 'required|string|max:50|unique:exhibits,exhibit_code,' . $exhibit->exhibit_id . ',exhibit_id',
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string|max:5000',
            'fun_facts'    => 'nullable|string|max:3000',
            'floor'        => 'nullable|string|max:50',
            'hall'         => 'nullable|string|max:100',
            'authors'      => 'nullable|string|max:300',
            'storyline_order' => 'nullable|integer|min:0',
            'category_id'  => 'nullable|exists:categories,category_id',
            'image'        => self::IMAGE_RULE,
            't_audio.*'    => self::AUDIO_RULE,
        ], self::MESSAGES);

        $data = $request->only([
            'exhibit_code', 'name', 'description', 'fun_facts',
            'category_id', 'floor', 'hall', 'authors',
            'languages', 'storyline_order',
        ]);
        if ($request->filled('source_language')) {
            $data['source_language'] = $request->input('source_language');
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->saveImage($request->file('image'), $request->name);
        }

        $exhibit->update($data);

        // The QR encodes the code, so a changed code means a new QR. Also
        // covers exhibits from before QR files existed.
        if ($exhibit->wasChanged('exhibit_code') || !ExhibitQr::path($exhibit)) {
            $exhibit->update(['qr_file' => ExhibitQr::write($exhibit)]);
        }

        $this->syncTranslations($request, $exhibit);

        $this->log('Exhibit Updated', "Updated exhibit: {$exhibit->name}");

        return redirect()->route('exhibits.index')->with('success', 'Exhibit updated.');
    }

    // ── Modal / AJAX endpoints ────────────────────────────────

    public function modalShow(Exhibit $exhibit)
    {
        $exhibit->load('category', 'translations', 'images');
        $exhibit->loadCount('scans');
        return response()->json([
            'exhibit_id'      => $exhibit->exhibit_id,
            'exhibit_code'    => $exhibit->exhibit_code,
            'name'            => $exhibit->name,
            'description'     => $exhibit->description,
            'fun_facts'       => $exhibit->fun_facts,
            'floor'           => $exhibit->floor,
            'hall'            => $exhibit->hall,
            'authors'         => $exhibit->authors,
            'languages'       => $exhibit->languages,
            'storyline_order' => $exhibit->storyline_order,
            'status'          => $exhibit->status,
            'scans_count'     => $exhibit->scans_count,
            'category'        => $exhibit->category?->name,
            'image'           => $exhibit->image,
            'source_language' => $exhibit->source_language,
            'qr_url'          => ExhibitQr::path($exhibit) ? route('exhibits.qr.single', $exhibit) : null,
            'scan_url'        => ExhibitQr::scanUrl($exhibit),
            'translations'    => $exhibit->translations->map(fn($t) => [
                'translation_id' => $t->translation_id,
                'language_code'  => $t->language_code,
                'language_label' => $t->language_label,
                'title'          => $t->title,
                'description'    => $t->description,
                'fun_facts'      => $t->fun_facts,
                'audio_file'     => $t->audio_file,
                'audio_url'      => $t->audio_url,
            ]),
            'images'          => $exhibit->images,
        ]);
    }

    public function modalEdit(Exhibit $exhibit)
    {
        $exhibit->load('translations', 'images');
        $categories = Category::orderBy('name')->get();
        return view('exhibits.partials.edit-form', compact('exhibit', 'categories'));
    }

    public function archive(Exhibit $exhibit)
    {
        $exhibit->update(['status' => false]);
        $this->log('Exhibit Archived', "Archived exhibit: {$exhibit->name}");
        return back()->with('success', 'Exhibit archived.');
    }

    public function restore(Exhibit $exhibit)
    {
        $exhibit->update(['status' => true]);
        $this->log('Exhibit Restored', "Restored exhibit: {$exhibit->name}");
        return back()->with('success', 'Exhibit restored.');
    }

    // ── Translation CRUD ──────────────────────────────────────

    public function storeTranslation(Request $request, Exhibit $exhibit)
    {
        $request->validate([
            'language_code'  => 'required|string|max:10|alpha_dash',
            'language_label' => 'required|string|max:50',
            'title'          => 'nullable|string|max:200',
            'description'    => 'nullable|string|max:5000',
            'fun_facts'      => 'nullable|string|max:3000',
            'audio'          => self::AUDIO_RULE,
        ], self::MESSAGES);

        $data = $request->only(['language_code', 'language_label', 'title', 'description', 'fun_facts']);
        $data['exhibit_id'] = $exhibit->exhibit_id;

        if ($request->hasFile('audio')) {
            $data['audio_file'] = $this->saveAudio($request->file('audio'), $exhibit->exhibit_id, $request->language_code);
        }

        ExhibitTranslation::updateOrCreate(
            ['exhibit_id' => $exhibit->exhibit_id, 'language_code' => $request->language_code],
            $data
        );

        return back()->with('success', 'Translation saved.');
    }

    public function updateTranslation(Request $request, ExhibitTranslation $translation)
    {
        $request->validate(['audio' => self::AUDIO_RULE], self::MESSAGES);

        $data = $request->only(['language_code', 'language_label', 'title', 'description', 'fun_facts']);

        if ($request->hasFile('audio')) {
            $data['audio_file'] = $this->saveAudio($request->file('audio'), $translation->exhibit_id, $request->language_code);
        }

        $translation->update($data);
        return back()->with('success', 'Translation updated.');
    }

    public function destroyTranslation(ExhibitTranslation $translation)
    {
        $translation->delete();
        return back()->with('success', 'Translation deleted.');
    }

    // ── Gallery ───────────────────────────────────────────────

    public function uploadGallery(Request $request, Exhibit $exhibit)
    {
        $request->validate(['images.*' => self::IMAGE_RULE], self::MESSAGES);

        foreach ($request->file('images', []) as $file) {
            $name = $this->saveImage($file, $exhibit->name . ' gallery ' . Str::random(4));
            ExhibitImage::create([
                'exhibit_id' => $exhibit->exhibit_id,
                'filename'   => $name,
                'caption'    => $request->input('caption'),
                'sort_order' => $exhibit->images()->count(),
            ]);
        }

        return back()->with('success', 'Images uploaded.');
    }

    public function destroyGalleryImage(ExhibitImage $image)
    {
        $public = public_path(self::IMAGE_DIR . '/' . $image->filename);
        if (is_file($public)) {
            unlink($public);
        }
        // Anything uploaded before uploads moved next to the seeded files.
        Storage::disk('public')->delete('exhibits/' . $image->filename);
        $image->delete();
        return back()->with('success', 'Image removed.');
    }

    public function qrCodes()
    {
        $exhibits = Exhibit::where('status', true)
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get();
        return view('exhibits.qr', compact('exhibits'));
    }

    /**
     * One exhibit's QR as a file - the SVG made when it was created. Opens
     * inline for a look, or downloads with ?download=1 for the label.
     */
    public function qrSingle(Request $request, Exhibit $exhibit)
    {
        $path = ExhibitQr::path($exhibit);
        if (!$path) {
            $exhibit->update(['qr_file' => ExhibitQr::write($exhibit)]);
            $path = ExhibitQr::path($exhibit);
        }

        $headers = ['Content-Type' => 'image/svg+xml'];
        if ($request->boolean('download')) {
            return response()->download($path, $exhibit->exhibit_code . '.svg', $headers);
        }

        return response()->file($path, $headers);
    }

    /**
     * Download all active exhibit QR codes as a ZIP archive.
     * Each file is named {exhibit_code}.svg and encodes the visitor deep-link URL.
     */
    public function qrDownloadAll(Request $request)
    {
        $exhibits = Exhibit::where('status', true)
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get();

        if ($exhibits->isEmpty()) {
            return back()->with('error', 'No active exhibits found.');
        }

        $zipPath = sys_get_temp_dir() . '/museobaler_qrcodes_' . time() . '.zip';
        $zip     = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Could not create ZIP archive.');
        }

        foreach ($exhibits as $exhibit) {
            $zip->addFromString($exhibit->exhibit_code . '.svg', ExhibitQr::svg(ExhibitQr::scanUrl($exhibit)));
        }

        $zip->close();

        return response()->download($zipPath, 'museobaler-qr-codes.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * The translation cards from the exhibit form, one per language.
     *
     * Each card arrives as parallel t_* arrays. Audio comes one of three
     * ways: a file the admin uploaded (t_audio), a narration the AI made and
     * the admin listened to (t_audio_draft - a token for a file parked in
     * public/audio/drafts), or nothing, in which case an existing recording
     * is kept. Cards the admin removed arrive in t_delete.
     */
    private function syncTranslations(Request $request, Exhibit $exhibit): void
    {
        foreach ((array) $request->input('t_delete', []) as $code) {
            $code = strtolower(trim((string) $code));
            if ($code === '') continue;
            $gone = $exhibit->translations()->where('language_code', $code)->first();
            if ($gone) {
                $this->deleteAudio($gone->audio_file);
                $gone->delete();
            }
        }

        foreach ((array) $request->input('t_code', []) as $i => $code) {
            $code  = strtolower(trim((string) $code));
            $label = trim((string) $request->input("t_label.$i", ''));
            if ($code === '') continue;
            if ($label === '') {
                $label = ExhibitLanguages::label($code);
            }

            $fields = [
                'language_label' => $label,
                'title'          => $request->input("t_title.$i"),
                'description'    => $request->input("t_desc.$i"),
                'fun_facts'      => $request->input("t_facts.$i"),
            ];

            $existing = $exhibit->translations()->where('language_code', $code)->first();

            if ($request->hasFile("t_audio.$i")) {
                $fields['audio_file'] = $this->saveAudio($request->file("t_audio.$i"), $exhibit->exhibit_id, $code);
            } elseif ($draft = ExhibitAiController::draftPath($request->input("t_audio_draft.$i"))) {
                $name = 'exhibit_' . $exhibit->exhibit_id . '_' . $code . '_' . time() . '.wav';
                rename($draft, public_path(self::AUDIO_DIR . '/' . $name));
                $fields['audio_file'] = $name;
            }

            if (isset($fields['audio_file']) && $existing) {
                $this->deleteAudio($existing->audio_file);
            }

            ExhibitTranslation::updateOrCreate(
                ['exhibit_id' => $exhibit->exhibit_id, 'language_code' => $code],
                $fields
            );
        }

        $exhibit->load('translations');
    }

    private function deleteAudio(?string $file): void
    {
        if (!$file) return;
        $path = public_path(self::AUDIO_DIR . '/' . basename($file));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Returns the stored filename; the record keeps that, never a path. */
    private function saveImage(UploadedFile $file, string $label): string
    {
        $name = Str::slug($label) . '_' . time() . '.' . strtolower($file->getClientOriginalExtension());
        $file->move(public_path(self::IMAGE_DIR), $name);
        return $name;
    }

    private function saveAudio(UploadedFile $file, int $exhibitId, string $languageCode): string
    {
        $name = 'exhibit_' . $exhibitId . '_' . $languageCode . '_' . time() . '.' . strtolower($file->getClientOriginalExtension());
        $file->move(public_path(self::AUDIO_DIR), $name);
        return $name;
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
