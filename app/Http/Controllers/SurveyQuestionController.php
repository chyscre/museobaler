<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\SurveyQuestion;
use App\Support\ArtaSurvey;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The question bank behind the visitor survey.
 *
 * Tourism-only: the survey is the ARTA Client Satisfaction Measurement, an
 * LGU instrument the Tourism office files, so the office that answers for
 * the numbers is the office that decides the questions.
 *
 * Locked rows (the ARTA set) keep their code, scale and section and cannot
 * be deleted or deactivated — the report is only a CSM report while every
 * SQD is in it. Their wording can be edited, and everything can be
 * reordered. Unlocked rows are the museum's own and are fully editable.
 */
class SurveyQuestionController extends Controller
{
    public function index()
    {
        $questions = SurveyQuestion::orderBy('sort_order')->orderBy('question_id')->get();
        $answered  = \DB::table('feedback_answers')
            ->selectRaw('code, COUNT(*) AS n')
            ->groupBy('code')
            ->pluck('n', 'code');

        return view('survey.index', [
            'questions'   => $questions,
            'answered'    => $answered,
            'agreeLabels' => ArtaSurvey::AGREE_LABELS,
        ]);
    }

    /**
     * The whole list comes back as JSON, like the halls editor: rows in
     * their new order, edited in place, new ones without an id, deleted
     * ones simply absent.
     */
    public function save(Request $request)
    {
        $rows = json_decode($request->input('questions', '[]'), true);
        if (!is_array($rows)) {
            return back()->with('error', 'The question list could not be read.');
        }

        $existing = SurveyQuestion::all()->keyBy('question_id');
        $kept     = [];
        $codes    = [];
        $sort     = 0;

        foreach ($rows as $r) {
            $id     = !empty($r['id']) ? (int) $r['id'] : null;
            $record = $id ? $existing->get($id) : null;
            if ($id && !$record) {
                continue; // deleted meanwhile
            }
            $record ??= new SurveyQuestion(['locked' => false]);

            try {
                $data = validator($r, [
                    'code'       => ['required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*$/'],
                    'section'    => ['required', Rule::in(['cc', 'sqd', 'app'])],
                    'scale'      => ['required', Rule::in(['agree5', 'choice'])],
                    'text_fil'   => ['required', 'string', 'max:500'],
                    'text_en'    => ['required', 'string', 'max:500'],
                    'hint_fil'   => ['nullable', 'string', 'max:255'],
                    'hint_en'    => ['nullable', 'string', 'max:255'],
                    'options'    => ['nullable', 'array', 'max:10'],
                    'options.*.value' => ['required', 'integer', 'min:1', 'max:99'],
                    'options.*.fil'   => ['required', 'string', 'max:200'],
                    'options.*.en'    => ['required', 'string', 'max:200'],
                    'options.*.na'    => ['nullable', 'boolean'],
                    'show_if'    => ['nullable', 'array'],
                    'show_if.code' => ['nullable', 'string', 'max:32'],
                    'show_if.in'   => ['nullable', 'array'],
                    'show_if.in.*' => ['integer'],
                    'allow_na'   => ['nullable', 'boolean'],
                    'default_na' => ['nullable', 'boolean'],
                    'required'   => ['nullable', 'boolean'],
                    'is_active'  => ['nullable', 'boolean'],
                ], [
                    'code.regex' => 'Codes are upper-case letters, digits and underscores, e.g. APP_STAFF.',
                ])->validate();
            } catch (ValidationException $e) {
                // The form posts one JSON blob, so a field error cannot be
                // pinned to an input; name the row and say what is wrong.
                $label = strtoupper((string) ($r['code'] ?? 'new question'));
                return back()->with('error', "{$label}: " . $e->validator->errors()->first());
            }

            $code = strtoupper($data['code']);
            if (isset($codes[$code])) {
                return back()->with('error', "The code {$code} is used twice.");
            }
            $codes[$code] = true;

            // The ARTA rows keep their identity. Everything else about them
            // — wording, hints, order — is the office's to change.
            if ($record->locked) {
                $code            = $record->code;
                $data['section'] = $record->section;
                $data['scale']   = $record->scale;
                $data['is_active'] = true;
                $data['required']  = true;
            }

            $options = null;
            if ($data['scale'] === 'choice') {
                $options = array_values(array_map(fn ($o) => [
                    'value' => (int) $o['value'],
                    'fil'   => trim($o['fil']),
                    'en'    => trim($o['en']),
                    'na'    => (bool) ($o['na'] ?? false),
                ], $data['options'] ?? []));
                if (count($options) < 2) {
                    return back()->with('error', "{$code} needs at least two options.");
                }
            }

            $showIf = null;
            if (!empty($data['show_if']['code']) && !empty($data['show_if']['in'])) {
                $showIf = [
                    'code' => strtoupper($data['show_if']['code']),
                    'in'   => array_values(array_map('intval', $data['show_if']['in'])),
                ];
            }

            $sort += 10;
            $record->fill([
                'code'       => $code,
                'section'    => $data['section'],
                'scale'      => $data['scale'],
                'text_fil'   => trim($data['text_fil']),
                'text_en'    => trim($data['text_en']),
                'hint_fil'   => trim($data['hint_fil'] ?? '') ?: null,
                'hint_en'    => trim($data['hint_en'] ?? '') ?: null,
                'options'    => $options,
                'show_if'    => $showIf,
                'allow_na'   => (bool) ($data['allow_na'] ?? true),
                'default_na' => (bool) ($data['default_na'] ?? false),
                'required'   => (bool) ($data['required'] ?? true),
                'is_active'  => (bool) ($data['is_active'] ?? true),
                'sort_order' => $sort,
            ]);
            $record->save();
            $kept[] = $record->question_id;
        }

        // Rows left out of the list are deleted — unless locked, in which
        // case they are kept and the office is told why.
        $missing = $existing->whereNotIn('question_id', $kept);
        $lockedDropped = $missing->where('locked', true);
        SurveyQuestion::whereIn('question_id', $missing->where('locked', false)->pluck('question_id'))->delete();

        $this->log('Survey Questions Updated', count($kept) . ' questions saved'
            . ($missing->where('locked', false)->count() ? ', ' . $missing->where('locked', false)->count() . ' deleted' : ''));

        $msg = 'Survey questions saved.';
        if ($lockedDropped->count()) {
            $msg .= ' The ARTA questions (' . $lockedDropped->pluck('code')->join(', ') . ') cannot be deleted and were kept.';
        }
        return redirect()->route('survey.index')->with('success', $msg);
    }

    /**
     * Put back any default question whose code is missing. Existing rows
     * are never touched, so an edited wording survives a restore.
     */
    public function restore()
    {
        $added = ArtaSurvey::seedMissing();
        $this->log('Survey Defaults Restored', "{$added} default questions re-added");

        return redirect()->route('survey.index')->with('success',
            $added ? "{$added} default question(s) restored." : 'Every default question is already present.');
    }

    private function log(string $action, string $details): void
    {
        Log::create([
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'role'       => auth()->user()->role ?? 'TourismHead',
            'action'     => $action,
            'details'    => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
