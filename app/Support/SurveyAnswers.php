<?php

namespace App\Support;

use App\Models\SurveyQuestion;
use Illuminate\Support\Collection;

/**
 * The survey as the app renders and submits it.
 *
 * The question bank is edited by the Tourism office (SurveyQuestionController)
 * and this is the one place that turns a row into what the phone shows,
 * so "give me the form" and "check this submission" agree on exactly which
 * answers are acceptable. Ported from public/api/_survey.php.
 */
class SurveyAnswers
{
    /**
     * Active questions in display order, each with a flat `choices` list
     * [{value, fil, en, na}] regardless of scale. value null = N/A.
     *
     * @return Collection<int, SurveyQuestion>
     */
    public static function questions(): Collection
    {
        return SurveyQuestion::active()->get();
    }

    /** One question, in the shape the app renders. */
    public static function present(SurveyQuestion $q): array
    {
        return [
            'question_id' => (int) $q->question_id,
            'code'        => $q->code,
            'section'     => $q->section,
            'scale'       => $q->scale,
            'text_fil'    => $q->text_fil,
            'text_en'     => $q->text_en,
            'hint_fil'    => $q->hint_fil,
            'hint_en'     => $q->hint_en,
            'show_if'     => $q->show_if ?: null,
            'default_na'  => (bool) $q->default_na,
            'required'    => (bool) $q->required,
            'choices'     => $q->choices(),
        ];
    }

    /**
     * Validate a submitted {CODE: value|null} map against the active questions.
     *
     * Returns ['ok' => true, 'answers' => [[question_id, code, value], …]]
     * or ['ok' => false, 'error' => ..., 'code' => ...]. A hidden question
     * (its show_if rule failed) is stored as its N/A option when it has one
     * and skipped otherwise - that is the paper form's own instruction for
     * CC2/CC3. Unknown codes are dropped rather than rejected, so the app
     * and the question bank can drift by one edit without breaking
     * submissions; a missing required answer is not tolerated.
     *
     * @param  Collection<int, SurveyQuestion>  $questions
     */
    public static function validate(Collection $questions, array $submitted): array
    {
        $clean = [];

        foreach ($questions as $q) {
            $code = $q->code;
            $has  = array_key_exists($code, $submitted);
            $raw  = $has ? $submitted[$code] : null;
            // JSON null, empty string and the literal "na" all mean N/A.
            $val  = ($raw === null || $raw === '' || $raw === 'na') ? null : (int) $raw;

            $choices = $q->choices();
            $allowed = array_map(fn ($c) => $c['value'], $choices);

            if (!self::isShown($q, $clean)) {
                foreach ($choices as $c) {
                    if ($c['na']) {
                        $clean[$code] = $c['value'];
                        break;
                    }
                }
                continue;
            }

            if (!$has || ($val === null && !in_array(null, $allowed, true))) {
                if ($q->required) {
                    return ['ok' => false, 'error' => 'missing_answer', 'code' => $code];
                }
                continue;
            }

            if (!in_array($val, $allowed, true)) {
                return ['ok' => false, 'error' => 'invalid_answer', 'code' => $code];
            }
            $clean[$code] = $val;
        }

        $rows = [];
        foreach ($questions as $q) {
            if (array_key_exists($q->code, $clean)) {
                $rows[] = [(int) $q->question_id, $q->code, $clean[$q->code]];
            }
        }

        return ['ok' => true, 'answers' => $rows];
    }

    /** Whether a question is asked, given the answers so far. */
    private static function isShown(SurveyQuestion $q, array $answers): bool
    {
        $rule = $q->show_if;
        if (!$rule || empty($rule['code'])) {
            return true;
        }
        if (!array_key_exists($rule['code'], $answers)) {
            return false;
        }
        $v = $answers[$rule['code']];

        return $v !== null && in_array((int) $v, array_map('intval', $rule['in'] ?? []), true);
    }
}
