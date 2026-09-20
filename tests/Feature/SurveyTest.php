<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\FeedbackAnswer;
use App\Models\Staff;
use App\Models\SurveyQuestion;
use App\Support\ArtaSurvey;
use App\Support\CsmReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The visitor survey is the ARTA Client Satisfaction Measurement form, kept
 * as an editable question bank. These pin the three things that matter:
 * the ARTA set is present after install and cannot be removed, the Tourism
 * office (and only the Tourism office) can edit it, and the report computes
 * the ARTA figures the way ARTA defines them.
 */
class SurveyTest extends TestCase
{
    use RefreshDatabase;

    // ── Install ─────────────────────────────────────────────────────

    public function test_the_arta_form_is_present_after_migrating(): void
    {
        $codes = SurveyQuestion::pluck('code')->all();

        foreach (['CC1', 'CC2', 'CC3', 'SQD0', 'SQD1', 'SQD2', 'SQD3', 'SQD4', 'SQD5', 'SQD6', 'SQD7', 'SQD8'] as $code) {
            $this->assertContains($code, $codes, "$code missing from the seeded survey");
            $this->assertTrue(SurveyQuestion::where('code', $code)->value('locked'), "$code should be locked");
        }

        // The museum's own questions are ordinary rows.
        $this->assertFalse(SurveyQuestion::where('code', 'APP_EASE')->value('locked'));

        // The paper form's rules survive the transcription.
        $cc2 = SurveyQuestion::where('code', 'CC2')->first();
        $this->assertSame(['code' => 'CC1', 'in' => [1, 2, 3]], $cc2->show_if);
        $this->assertTrue(collect($cc2->choices())->firstWhere('value', 5)['na']);
        $this->assertTrue(SurveyQuestion::where('code', 'SQD5')->value('default_na'));
    }

    public function test_seeding_is_idempotent(): void
    {
        $before = SurveyQuestion::count();
        $this->assertSame(0, ArtaSurvey::seedMissing());
        $this->assertSame($before, SurveyQuestion::count());
    }

    // ── Who may edit ────────────────────────────────────────────────

    public function test_museum_staff_cannot_open_or_change_the_question_bank(): void
    {
        $admin = Staff::factory()->administrator()->create();

        $this->actingAs($admin)->get('/survey')->assertForbidden();
        $this->actingAs($admin)->post('/survey', ['questions' => '[]'])->assertForbidden();
        $this->assertSame(15, SurveyQuestion::count());
    }

    public function test_tourism_head_can_reword_add_reorder_and_delete(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $this->actingAs($tourism)->get('/survey')->assertOk()->assertSee('Survey Questions');

        $rows = SurveyQuestion::orderBy('sort_order')->get()->map(fn ($q) => [
            'id' => $q->question_id, 'code' => $q->code, 'section' => $q->section, 'scale' => $q->scale,
            'text_fil' => $q->text_fil, 'text_en' => $q->text_en, 'hint_fil' => $q->hint_fil, 'hint_en' => $q->hint_en,
            'options' => $q->options, 'show_if' => $q->show_if, 'allow_na' => $q->allow_na,
            'default_na' => $q->default_na, 'required' => $q->required, 'is_active' => $q->is_active,
        ])->all();

        // Reword SQD0, drop APP_CHECKIN, add a new question, and put the new
        // one first in its section.
        foreach ($rows as &$r) {
            if ($r['code'] === 'SQD0') $r['text_en'] = 'I am happy with my museum visit.';
        }
        unset($r);
        $rows = array_values(array_filter($rows, fn ($r) => $r['code'] !== 'APP_CHECKIN'));
        $new = [
            'id' => '', 'code' => 'APP_STAFF', 'section' => 'app', 'scale' => 'agree5',
            'text_fil' => 'Magalang ang mga tauhan.', 'text_en' => 'The staff were courteous.',
            'allow_na' => true, 'default_na' => false, 'required' => true, 'is_active' => true,
        ];
        $firstApp = array_search('app', array_column($rows, 'section'), true);
        array_splice($rows, $firstApp, 0, [$new]);

        $this->actingAs($tourism)
            ->post('/survey', ['questions' => json_encode($rows)])
            ->assertRedirect(route('survey.index'))
            ->assertSessionHas('success');

        $this->assertSame('I am happy with my museum visit.', SurveyQuestion::where('code', 'SQD0')->value('text_en'));
        $this->assertDatabaseMissing('survey_questions', ['code' => 'APP_CHECKIN']);
        $this->assertDatabaseHas('survey_questions', ['code' => 'APP_STAFF', 'section' => 'app', 'locked' => 0]);

        $appOrder = SurveyQuestion::where('section', 'app')->orderBy('sort_order')->pluck('code')->all();
        $this->assertSame('APP_STAFF', $appOrder[0]);

        $this->assertDatabaseHas('logs', ['action' => 'Survey Questions Updated']);
    }

    public function test_arta_questions_cannot_be_deleted_or_recoded(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        // Post a list that omits every SQD row and renames CC1.
        $rows = SurveyQuestion::where('section', '!=', 'sqd')->orderBy('sort_order')->get()->map(fn ($q) => [
            'id' => $q->question_id, 'code' => $q->code === 'CC1' ? 'RENAMED' : $q->code,
            'section' => 'app', 'scale' => 'choice',
            'text_fil' => $q->text_fil, 'text_en' => $q->text_en, 'options' => $q->options,
            'is_active' => false,
        ])->all();

        $this->actingAs($tourism)->post('/survey', ['questions' => json_encode($rows)])->assertRedirect();

        $this->assertSame(9, SurveyQuestion::where('section', 'sqd')->count(), 'SQD rows must survive being left out');
        $cc1 = SurveyQuestion::where('code', 'CC1')->first();
        $this->assertNotNull($cc1, 'CC1 must keep its code');
        $this->assertSame('cc', $cc1->section);
        $this->assertTrue($cc1->is_active, 'a locked question cannot be switched off');
    }

    public function test_restore_puts_back_a_deleted_default_without_touching_edits(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        SurveyQuestion::where('code', 'APP_EASE')->delete();
        SurveyQuestion::where('code', 'SQD1')->update(['text_en' => 'Edited wording']);

        $this->actingAs($tourism)->post('/survey/restore')->assertRedirect(route('survey.index'));

        $this->assertDatabaseHas('survey_questions', ['code' => 'APP_EASE']);
        $this->assertSame('Edited wording', SurveyQuestion::where('code', 'SQD1')->value('text_en'));
    }

    public function test_bad_rows_are_rejected_with_a_readable_message(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $rows = [[
            'id' => '', 'code' => 'bad code!', 'section' => 'app', 'scale' => 'agree5',
            'text_fil' => 'x', 'text_en' => 'y',
        ]];

        $this->actingAs($tourism)
            ->from('/survey')
            ->post('/survey', ['questions' => json_encode($rows)])
            ->assertRedirect('/survey')
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('survey_questions', ['code' => 'BAD CODE!']);
    }

    // ── The report ──────────────────────────────────────────────────

    /** Three respondents with a known spread of answers. */
    private function fileSurveys(): void
    {
        $sqd = SurveyQuestion::where('section', 'sqd')->orderBy('sort_order')->pluck('question_id', 'code');
        $cc  = SurveyQuestion::where('section', 'cc')->pluck('question_id', 'code');
        $app = SurveyQuestion::where('code', 'APP_EASE')->value('question_id');

        $file = function (int $cc1, array $sqdValues, int $appVal, int $stars) use ($sqd, $cc, $app) {
            $f = Feedback::create(['rating' => $stars, 'client_type' => 'citizen', 'region' => 'III – Central Luzon']);
            $rows = [['q' => $cc['CC1'], 'code' => 'CC1', 'value' => $cc1]];
            if (in_array($cc1, [1, 2, 3], true)) {
                $rows[] = ['q' => $cc['CC2'], 'code' => 'CC2', 'value' => 1];
                $rows[] = ['q' => $cc['CC3'], 'code' => 'CC3', 'value' => 1];
            } else {
                $rows[] = ['q' => $cc['CC2'], 'code' => 'CC2', 'value' => 5];
                $rows[] = ['q' => $cc['CC3'], 'code' => 'CC3', 'value' => 4];
            }
            $i = 0;
            foreach ($sqd as $code => $qid) {
                // array_key_exists, not ??: a null here is a deliberate N/A.
                $rows[] = ['q' => $qid, 'code' => $code, 'value' => array_key_exists($i, $sqdValues) ? $sqdValues[$i] : 5];
                $i++;
            }
            $rows[] = ['q' => $app, 'code' => 'APP_EASE', 'value' => $appVal];
            foreach ($rows as $r) {
                FeedbackAnswer::create(['feedback_id' => $f->feedback_id, 'question_id' => $r['q'], 'code' => $r['code'], 'value' => $r['value']]);
            }
        };

        // 9 SQD items each. Respondent A: all 5 with SQD5 N/A → 8 rated, 8 satisfied.
        $file(1, [5, 5, 5, 5, 5, null, 5, 5, 5], 5, 5);
        // B: all 4 → 9 rated, 9 satisfied.
        $file(4, [4, 4, 4, 4, 4, 4, 4, 4, 4], 4, 4);
        // C: 3s and 2s → 9 rated, 0 satisfied.
        $file(2, [3, 3, 3, 2, 2, 2, 3, 3, 3], 2, 2);
        // Old star-only feedback: not a CSM respondent.
        Feedback::create(['rating' => 5]);
    }

    public function test_csm_figures_follow_the_arta_definitions(): void
    {
        $this->fileSurveys();

        $csm = CsmReport::build(Feedback::with('answers')->get());

        $this->assertSame(3, $csm['respondents']);
        // CC awareness: A(1) and C(2) know the CC, B(4) does not.
        $this->assertEqualsWithDelta(66.7, $csm['cc_awareness'], 0.05);
        // SQD: 26 rated answers (N/A excluded), 17 satisfied.
        $this->assertSame(26, $csm['sqd_responses']);
        $this->assertEqualsWithDelta(65.4, $csm['sqd_score'], 0.05);
        $this->assertSame('Fair', CsmReport::rating($csm['sqd_score']));

        $sqd5 = collect($csm['sqd'])->firstWhere('code', 'SQD5');
        $this->assertSame(1, $sqd5['na']);
        $this->assertSame(2, $sqd5['responses']);

        $ease = collect($csm['app'])->firstWhere('code', 'APP_EASE');
        $this->assertEqualsWithDelta(66.7, $ease['score'], 0.05);
    }

    public function test_feedback_pages_and_report_render_with_survey_answers(): void
    {
        $this->fileSurveys();
        $tourism = Staff::factory()->tourismHead()->create();
        $first   = Feedback::whereHas('answers')->first();

        $this->actingAs($tourism)->get('/feedback')->assertOk()->assertSee('CSM Score');
        $this->actingAs($tourism)->get('/feedback/' . $first->feedback_id)->assertOk()->assertSee('SQD0');
        $this->actingAs($tourism)->get('/feedback/' . $first->feedback_id . '/modal')
            ->assertOk()->assertJsonFragment(['code' => 'CC1']);

        $this->actingAs($tourism)
            ->get('/reports/feedback?from=' . today()->toDateString() . '&to=' . today()->toDateString())
            ->assertOk()
            ->assertSee('Client Satisfaction Measurement')
            ->assertSee('Overall SQD score');
    }

    public function test_csv_has_one_column_per_question_and_writes_na_explicitly(): void
    {
        $this->fileSurveys();
        $tourism = Staff::factory()->tourismHead()->create();

        $res = $this->actingAs($tourism)
            ->get('/reports/feedback/csv?from=' . today()->toDateString() . '&to=' . today()->toDateString());
        $res->assertOk();

        $csv   = $res->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $head  = str_getcsv($lines[0]);

        $this->assertContains('CC1', $head);
        $this->assertContains('SQD8', $head);
        $this->assertContains('APP_EASE', $head);
        // Three survey rows; the star-only feedback is not a CSM response.
        $this->assertCount(4, $lines);

        $sqd5 = array_search('SQD5', $head, true);
        $values = array_map(fn ($l) => str_getcsv($l)[$sqd5], array_slice($lines, 1));
        $this->assertContains('N/A', $values);
    }

    public function test_a_deleted_question_keeps_its_history_in_the_report(): void
    {
        $this->fileSurveys();
        SurveyQuestion::where('code', 'APP_EASE')->delete();

        $csm = CsmReport::build(Feedback::with('answers')->get());
        $ease = collect($csm['app'])->firstWhere('code', 'APP_EASE');

        $this->assertNotNull($ease, 'answers to a deleted question must still be reported');
        $this->assertFalse($ease['active']);
        $this->assertSame(3, $ease['responses']);
    }
}
