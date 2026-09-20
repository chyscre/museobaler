<?php

namespace App\Support;

/**
 * The default question set for the visitor survey.
 *
 * The first twelve rows are the ARTA Client Satisfaction Measurement form
 * (ARTA MC 2022-05, the on-site Filipino version the Tourism office prints),
 * transcribed verbatim so the numbers the app collects can be filed as the
 * same instrument. They are marked `locked`: the Tourism office can reword
 * them and reorder them, but not delete them or change their code, because
 * a CSM report with a missing SQD is not a CSM report.
 *
 * The APP_* rows are the museum's own additions — the head of tourism also
 * wants to know how the app itself is doing. They are ordinary rows: edit,
 * disable, delete, add more.
 *
 * Section decides which step of the sheet a question appears on:
 *   cc   Citizen's Charter awareness (single choice)
 *   sqd  Service Quality Dimensions (five-face agreement scale)
 *   app  The museum's own questions, shown with the star rating and comment
 *
 * Scale:
 *   agree5  1 = strongly disagree … 5 = strongly agree, plus N/A when allowed.
 *           Its option labels are fixed and shared, see self::AGREE_LABELS.
 *   choice  Bespoke options carried in `options` as [{value, fil, en, na?}].
 *           An option flagged `na` is what gets stored when the question is
 *           skipped by a show_if rule (the paper form's "answer N/A on CC2
 *           and CC3" instruction).
 *
 * show_if: {"code": "CC1", "in": [1,2,3]} — only ask when that earlier
 * answer is one of those values.
 */
class ArtaSurvey
{
    public const AGREE_LABELS = [
        1 => ['fil' => 'Lubos na hindi sumasang-ayon', 'en' => 'Strongly disagree'],
        2 => ['fil' => 'Hindi sumasang-ayon',          'en' => 'Disagree'],
        3 => ['fil' => 'Walang kinikilingan',          'en' => 'Neither agree nor disagree'],
        4 => ['fil' => 'Sumasang-ayon',                'en' => 'Agree'],
        5 => ['fil' => 'Labis na sumasang-ayon',       'en' => 'Strongly agree'],
    ];

    public const NA_LABEL = ['fil' => 'N/A', 'en' => 'N/A'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $sort = 0;
        $row = function (array $r) use (&$sort) {
            $sort += 10;
            return array_merge([
                'section'    => 'sqd',
                'scale'      => 'agree5',
                'hint_fil'   => null,
                'hint_en'    => null,
                'options'    => null,
                'show_if'    => null,
                'allow_na'   => true,
                'default_na' => false,
                'required'   => true,
                'locked'     => true,
                'is_active'  => true,
                'sort_order' => $sort,
            ], $r);
        };

        return [
            // ── Citizen's Charter ────────────────────────────────────────
            $row([
                'code'     => 'CC1',
                'section'  => 'cc',
                'scale'    => 'choice',
                'allow_na' => false,
                'text_fil' => 'Alin sa mga sumusunod ang naglalarawan sa iyong kaalaman sa Citizen\'s Charter (CC)?',
                'text_en'  => 'Which of the following best describes your awareness of the Citizen\'s Charter (CC)?',
                'options'  => [
                    ['value' => 1, 'fil' => 'Alam ko ang CC at nakita ko ito sa napuntahang opisina',                    'en' => 'I know what a CC is and I saw this office\'s CC'],
                    ['value' => 2, 'fil' => 'Alam ko ang CC pero hindi ko ito nakita sa napuntahang opisina',            'en' => 'I know what a CC is but I did NOT see this office\'s CC'],
                    ['value' => 3, 'fil' => 'Nalaman ko ang CC nang makita ko ito sa napuntahang opisina',               'en' => 'I learned of the CC only when I saw this office\'s CC'],
                    ['value' => 4, 'fil' => 'Hindi ko alam kung ano ang CC at wala akong nakita sa napuntahang opisina', 'en' => 'I do not know what a CC is and I did not see one in this office'],
                ],
            ]),
            $row([
                'code'     => 'CC2',
                'section'  => 'cc',
                'scale'    => 'choice',
                'allow_na' => false,
                'text_fil' => 'Masasabi mo ba na ang CC ng napuntahang opisina ay…',
                'text_en'  => 'Would you say that the CC of this office was…',
                'show_if'  => ['code' => 'CC1', 'in' => [1, 2, 3]],
                'options'  => [
                    ['value' => 1, 'fil' => 'Madaling makita',       'en' => 'Easy to see'],
                    ['value' => 2, 'fil' => 'Medyo madaling makita', 'en' => 'Somewhat easy to see'],
                    ['value' => 3, 'fil' => 'Mahirap makita',        'en' => 'Difficult to see'],
                    ['value' => 4, 'fil' => 'Hindi makita',          'en' => 'Not visible at all'],
                    ['value' => 5, 'fil' => 'N/A',                   'en' => 'N/A', 'na' => true],
                ],
            ]),
            $row([
                'code'     => 'CC3',
                'section'  => 'cc',
                'scale'    => 'choice',
                'allow_na' => false,
                'text_fil' => 'Gaano nakatulong ang CC sa transaksyon mo?',
                'text_en'  => 'How much did the CC help you in your transaction?',
                'show_if'  => ['code' => 'CC1', 'in' => [1, 2, 3]],
                'options'  => [
                    ['value' => 1, 'fil' => 'Sobrang nakatulong', 'en' => 'Helped very much'],
                    ['value' => 2, 'fil' => 'Nakatulong naman',   'en' => 'Somewhat helped'],
                    ['value' => 3, 'fil' => 'Hindi nakatulong',   'en' => 'Did not help'],
                    ['value' => 4, 'fil' => 'N/A',                'en' => 'N/A', 'na' => true],
                ],
            ]),

            // ── Service Quality Dimensions ───────────────────────────────
            $row([
                'code'     => 'SQD0',
                'text_fil' => 'Nasiyahan ako sa serbisyo na aking natanggap sa napuntahan na tanggapan.',
                'text_en'  => 'I am satisfied with the service that I availed.',
            ]),
            $row([
                'code'     => 'SQD1',
                'text_fil' => 'Makatwiran ang oras na aking ginugol para sa pagproseso ng aking transaksyon.',
                'text_en'  => 'I spent a reasonable amount of time for my transaction.',
            ]),
            $row([
                'code'     => 'SQD2',
                'text_fil' => 'Ang opisina ay sumusunod sa mga kinakailangang dokumento at mga hakbang batay sa impormasyong ibinigay.',
                'text_en'  => 'The office followed the transaction\'s requirements and steps based on the information provided.',
            ]),
            $row([
                'code'     => 'SQD3',
                'text_fil' => 'Ang mga hakbang sa pagproseso, kasama na ang pagbayad ay madali at simple lamang.',
                'text_en'  => 'The steps (including payment) I needed to do for my transaction were easy and simple.',
            ]),
            $row([
                'code'     => 'SQD4',
                'text_fil' => 'Mabilis at madali akong nakahanap ng impormasyon tungkol sa aking transaksyon mula sa opisina o sa website nito.',
                'text_en'  => 'I easily found information about my transaction from the office or its website.',
            ]),
            $row([
                'code'       => 'SQD5',
                'text_fil'   => 'Nagbayad ako ng makatwirang halaga para sa aking transaksyon.',
                'text_en'    => 'I paid a reasonable amount of fees for my transaction.',
                'hint_fil'   => 'Kung ang serbisyo ay ibinigay ng libre, piliin ang N/A.',
                'hint_en'    => 'If the service was free, choose N/A.',
                'default_na' => true,
            ]),
            $row([
                'code'     => 'SQD6',
                'text_fil' => 'Pakiramdam ko ay patas ang opisina sa lahat, o "walang palakasan", sa aking transaksyon.',
                'text_en'  => 'I feel the office was fair to everyone, or "walang palakasan", during my transaction.',
            ]),
            $row([
                'code'     => 'SQD7',
                'text_fil' => 'Magalang akong trinato ng mga tauhan, at (kung sakali ako ay humingi ng tulong) alam ko na sila ay handang tumulong sa akin.',
                'text_en'  => 'I was treated courteously by the staff, and (if asked for help) the staff was helpful.',
            ]),
            $row([
                'code'     => 'SQD8',
                'text_fil' => 'Nakuha ko ang kinakailangan ko mula sa tanggapan ng gobyerno, kung tinanggihan man, ito ay sapat na ipinaliwanag sa akin.',
                'text_en'  => 'I got what I needed from the government office, or (if denied) the denial was sufficiently explained to me.',
            ]),

            // ── The museum's own questions ───────────────────────────────
            $row([
                'code'     => 'APP_EASE',
                'section'  => 'app',
                'locked'   => false,
                'text_fil' => 'Madaling gamitin ang Museo de Baler app.',
                'text_en'  => 'The Museo de Baler app was easy to use.',
            ]),
            $row([
                'code'     => 'APP_CONTENT',
                'section'  => 'app',
                'locked'   => false,
                'text_fil' => 'Nakatulong ang mga exhibit page at audio guide sa app para maunawaan ko ang mga exhibit.',
                'text_en'  => 'The exhibit pages and audio guide in the app helped me understand the exhibits.',
            ]),
            $row([
                'code'     => 'APP_CHECKIN',
                'section'  => 'app',
                'locked'   => false,
                'text_fil' => 'Maayos at mabilis ang QR check-in gamit ang app.',
                'text_en'  => 'QR check-in with the app was smooth and quick.',
            ]),
        ];
    }

    /**
     * Insert any default whose code is not yet in the table. Used by the
     * migration on install and by the "restore ARTA defaults" button, which
     * is the way back after someone deletes an APP_* row they wanted.
     */
    public static function seedMissing(): int
    {
        $existing = \DB::table('survey_questions')->pluck('code')->all();
        $added    = 0;

        foreach (self::defaults() as $q) {
            if (in_array($q['code'], $existing, true)) {
                continue;
            }
            $q['options'] = $q['options'] ? json_encode($q['options']) : null;
            $q['show_if'] = $q['show_if'] ? json_encode($q['show_if']) : null;
            $q['created_at'] = $q['updated_at'] = now();
            \DB::table('survey_questions')->insert($q);
            $added++;
        }

        return $added;
    }
}
