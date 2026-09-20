<?php

namespace App\Support;

use App\Models\Feedback;
use App\Models\SurveyQuestion;
use Illuminate\Support\Collection;

/**
 * The numbers the ARTA Client Satisfaction Measurement report asks for,
 * computed the way the ARTA guidelines define them:
 *
 *   CC awareness   share of respondents whose CC1 answer is 1, 2 or 3
 *   SQD score      share of SQD answers that are Agree or Strongly Agree,
 *                  with N/A answers left out of the denominator
 *
 * Reports are keyed by question code rather than question id, so a retired
 * or deleted question still shows its history. Wording comes from the
 * current question row when there is one, and falls back to the code.
 */
class CsmReport
{
    /**
     * @param  Collection<int, Feedback>  $rows  feedback with `answers` loaded
     */
    public static function build(Collection $rows): array
    {
        $questions = SurveyQuestion::orderBy('sort_order')->get()->keyBy('code');
        $answers   = $rows->flatMap->answers;

        $withSurvey = $rows->filter(fn ($f) => $f->answers->isNotEmpty())->count();

        // ── CC1–CC3 ──────────────────────────────────────────────────
        $cc1Total = $answers->where('code', 'CC1')->count();
        $cc1Aware = $answers->where('code', 'CC1')->whereIn('value', [1, 2, 3])->count();

        $ccTables = [];
        foreach (['CC1', 'CC2', 'CC3'] as $code) {
            $set = $answers->where('code', $code);
            if ($set->isEmpty()) continue;
            $q = $questions->get($code);
            $counts = [];
            $choices = $q ? $q->choices() : [];
            foreach ($choices as $c) {
                $n = $c['value'] === null
                    ? $set->whereNull('value')->count()
                    : $set->where('value', $c['value'])->count();
                $counts[] = ['label' => $c['en'], 'fil' => $c['fil'], 'count' => $n, 'na' => $c['na']];
            }
            $ccTables[] = [
                'code'   => $code,
                'text'   => $q?->text_en ?? $code,
                'total'  => $set->count(),
                'counts' => $counts,
            ];
        }

        // ── SQD0–SQD8 and the museum's own agree5 rows ───────────────
        $scaleRows = function (string $section) use ($answers, $questions) {
            $out = [];
            $codes = $questions->where('section', $section)->where('scale', 'agree5')->pluck('code')
                ->merge($answers->pluck('code')->unique()->filter(fn ($c) =>
                    !$questions->has($c) && str_starts_with($c, $section === 'sqd' ? 'SQD' : 'APP')))
                ->unique();
            foreach ($codes as $code) {
                $set = $answers->where('code', $code);
                if ($set->isEmpty()) continue;
                $rated = $set->whereNotNull('value');
                $out[] = [
                    'code'      => $code,
                    'text'      => $questions->get($code)?->text_en ?? $code,
                    'active'    => (bool) ($questions->get($code)?->is_active ?? false),
                    'counts'    => collect(range(1, 5))->mapWithKeys(fn ($v) => [$v => $rated->where('value', $v)->count()]),
                    'na'        => $set->whereNull('value')->count(),
                    'responses' => $rated->count(),
                    'satisfied' => $rated->whereIn('value', [4, 5])->count(),
                    'score'     => $rated->count() ? round($rated->whereIn('value', [4, 5])->count() / $rated->count() * 100, 1) : null,
                    'mean'      => $rated->count() ? round($rated->avg('value'), 2) : null,
                ];
            }
            return $out;
        };

        $sqd = $scaleRows('sqd');
        $app = $scaleRows('app');

        $sqdRated     = collect($sqd)->sum('responses');
        $sqdSatisfied = collect($sqd)->sum('satisfied');

        // Per-respondent header breakdowns.
        $clientTypes = $rows->whereNotNull('client_type')->groupBy('client_type')->map->count();
        $regions     = $rows->whereNotNull('region')->groupBy('region')->map->count()->sortDesc();

        return [
            'respondents'   => $withSurvey,
            'cc1_total'     => $cc1Total,
            'cc_awareness'  => $cc1Total ? round($cc1Aware / $cc1Total * 100, 1) : null,
            'cc'            => $ccTables,
            'sqd'           => $sqd,
            'sqd_responses' => $sqdRated,
            'sqd_score'     => $sqdRated ? round($sqdSatisfied / $sqdRated * 100, 1) : null,
            'app'           => $app,
            'client_types'  => $clientTypes,
            'regions'       => $regions,
        ];
    }

    /**
     * ARTA's own descriptor for an overall SQD score.
     */
    public static function rating(?float $score): ?string
    {
        if ($score === null) return null;
        return match (true) {
            $score >= 95 => 'Outstanding',
            $score >= 90 => 'Very Satisfactory',
            $score >= 80 => 'Satisfactory',
            $score >= 60 => 'Fair',
            default      => 'Poor',
        };
    }
}
