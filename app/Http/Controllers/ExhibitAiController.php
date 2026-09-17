<?php

namespace App\Http\Controllers;

use App\Services\Gemini;
use App\Support\ExhibitLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The AI half of the exhibit form.
 *
 * Both endpoints hand back drafts for the admin to read, correct and listen
 * to inside the form. Nothing is written to an exhibit here: the text goes
 * back as JSON to fill editable fields, and narration is parked as a draft
 * file that ExhibitController moves next to the exhibit only when the form
 * is saved.
 */
class ExhibitAiController extends Controller
{
    /** Where narration waits between "Generate audio" and "Save". */
    public const DRAFT_DIR = 'audio/drafts';

    public function translate(Request $request, Gemini $gemini): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'fun_facts'   => 'nullable|string|max:3000',
            'from'        => ['required', Rule::in(ExhibitLanguages::codes())],
            'to'          => 'nullable|array',
            'to.*'        => Rule::in(ExhibitLanguages::codes()),
        ], [
            'description.required' => 'Write the description first - that is what gets translated.',
        ]);

        $targets = $data['to'] ?? array_diff(ExhibitLanguages::codes(), [$data['from']]);

        try {
            $translations = $gemini->translate([
                'title'       => $data['title'],
                'description' => $data['description'],
                'fun_facts'   => $data['fun_facts'] ?? '',
            ], $data['from'], array_values($targets));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $out = [];
        foreach ($translations as $code => $t) {
            $out[] = ['language_code' => $code, 'language_label' => ExhibitLanguages::label($code)] + $t;
        }

        return response()->json(['translations' => $out]);
    }

    public function narrate(Request $request, Gemini $gemini): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(ExhibitLanguages::codes())],
            'text'     => 'required|string|max:8000',
        ], [
            'text.required' => 'There is nothing to narrate yet - write the description first.',
        ]);

        try {
            $wav = $gemini->narrate($data['text'], $data['language']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $dir = public_path(self::DRAFT_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::sweep($dir);

        $token = Str::random(32);
        file_put_contents("$dir/$token.wav", $wav);

        return response()->json([
            'draft' => $token,
            'url'   => asset(self::DRAFT_DIR . "/$token.wav") . '?t=' . time(),
        ]);
    }

    /** Resolve a draft token to its file, or null if it has gone. */
    public static function draftPath(?string $token): ?string
    {
        if (!$token || !preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }
        $path = public_path(self::DRAFT_DIR . "/$token.wav");

        return is_file($path) ? $path : null;
    }

    /** Drafts nobody saved are forgotten after a day. */
    private static function sweep(string $dir): void
    {
        foreach (glob("$dir/*.wav") ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
