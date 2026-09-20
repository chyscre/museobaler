<?php
/**
 * Survey questions for the visitor API.
 *
 * The question bank is edited in the Laravel admin (SurveyQuestionController)
 * and read here by the raw-PHP visitor endpoint. This file is the one place
 * that turns a survey_questions row into what the app renders, so both the
 * "give me the form" call and the "validate this submission" step agree on
 * exactly which answers are acceptable.
 */

// The "Rehiyon" field. The visitor's record already says whether they are
// foreign; a domestic tourist picks their own region from this list.
const SURVEY_REGIONS = [
    'NCR – National Capital Region',
    'CAR – Cordillera',
    'I – Ilocos',
    'II – Cagayan Valley',
    'III – Central Luzon',
    'IV-A – CALABARZON',
    'IV-B – MIMAROPA',
    'V – Bicol',
    'VI – Western Visayas',
    'VII – Central Visayas',
    'VIII – Eastern Visayas',
    'IX – Zamboanga Peninsula',
    'X – Northern Mindanao',
    'XI – Davao',
    'XII – SOCCSKSARGEN',
    'XIII – Caraga',
    'BARMM – Bangsamoro',
    'Outside the Philippines',
];

const SURVEY_AGREE_LABELS = [
    1 => ['fil' => 'Lubos na hindi sumasang-ayon', 'en' => 'Strongly disagree'],
    2 => ['fil' => 'Hindi sumasang-ayon',          'en' => 'Disagree'],
    3 => ['fil' => 'Walang kinikilingan',          'en' => 'Neither agree nor disagree'],
    4 => ['fil' => 'Sumasang-ayon',                'en' => 'Agree'],
    5 => ['fil' => 'Labis na sumasang-ayon',       'en' => 'Strongly agree'],
];

/**
 * Active questions in display order, each with a flat `choices` list
 * [{value, fil, en, na}] regardless of scale. value null = N/A.
 */
function surveyQuestions(mysqli $con): array
{
    $res = mysqli_query($con,
        "SELECT question_id, code, section, scale, text_fil, text_en, hint_fil, hint_en,
                options, show_if, allow_na, default_na, required
         FROM survey_questions
         WHERE is_active = 1
         ORDER BY sort_order, question_id"
    );

    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $choices = [];
        if ($r['scale'] === 'choice') {
            foreach (json_decode($r['options'] ?? '[]', true) ?: [] as $o) {
                $choices[] = [
                    'value' => (int)$o['value'],
                    'fil'   => (string)($o['fil'] ?? ''),
                    'en'    => (string)($o['en']  ?? ''),
                    'na'    => (bool)($o['na'] ?? false),
                ];
            }
        } else {
            foreach (SURVEY_AGREE_LABELS as $v => $l) {
                $choices[] = ['value' => $v, 'fil' => $l['fil'], 'en' => $l['en'], 'na' => false];
            }
            if ((int)$r['allow_na'] === 1) {
                $choices[] = ['value' => null, 'fil' => 'N/A', 'en' => 'N/A', 'na' => true];
            }
        }

        $out[] = [
            'question_id' => (int)$r['question_id'],
            'code'        => $r['code'],
            'section'     => $r['section'],
            'scale'       => $r['scale'],
            'text_fil'    => $r['text_fil'],
            'text_en'     => $r['text_en'],
            'hint_fil'    => $r['hint_fil'],
            'hint_en'     => $r['hint_en'],
            'show_if'     => $r['show_if'] ? json_decode($r['show_if'], true) : null,
            'default_na'  => (int)$r['default_na'] === 1,
            'required'    => (int)$r['required'] === 1,
            'choices'     => $choices,
        ];
    }
    return $out;
}

/**
 * Whether a question is asked, given the answers so far.
 */
function surveyIsShown(array $q, array $answers): bool
{
    $rule = $q['show_if'];
    if (!$rule || empty($rule['code'])) return true;
    if (!array_key_exists($rule['code'], $answers)) return false;
    $v = $answers[$rule['code']];
    return $v !== null && in_array((int)$v, array_map('intval', $rule['in'] ?? []), true);
}

/**
 * Validate a submitted {CODE: value|null} map against the active questions.
 *
 * Returns ['ok' => true, 'answers' => [[question_id, code, value], …]] or
 * ['ok' => false, 'error' => ..., 'code' => ...]. A hidden question (its
 * show_if rule failed) is stored as its N/A option when it has one, and
 * skipped otherwise — that is the paper form's own instruction for CC2/CC3.
 * Unknown codes are dropped rather than rejected, so the app and the
 * question bank can drift by one edit without breaking submissions.
 */
function surveyValidate(array $questions, array $submitted): array
{
    $clean = [];
    foreach ($questions as $q) {
        $code = $q['code'];
        $has  = array_key_exists($code, $submitted);
        $raw  = $has ? $submitted[$code] : null;
        // JSON null, empty string and the literal "na" all mean N/A.
        $val  = ($raw === null || $raw === '' || $raw === 'na') ? null : (int)$raw;

        $allowed = array_map(fn($c) => $c['value'], $q['choices']);

        if (!surveyIsShown($q, $clean)) {
            $na = null;
            foreach ($q['choices'] as $c) {
                if ($c['na']) { $na = $c['value']; break; }
            }
            if ($na !== null) $clean[$code] = $na;
            continue;
        }

        if (!$has || ($val === null && !in_array(null, $allowed, true))) {
            if ($q['required']) {
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
        if (array_key_exists($q['code'], $clean)) {
            $rows[] = [$q['question_id'], $q['code'], $clean[$q['code']]];
        }
    }
    return ['ok' => true, 'answers' => $rows];
}
