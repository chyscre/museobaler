<?php

namespace Tests\Feature\Api;

use App\Models\Feedback;
use App\Models\FeedbackAnswer;
use App\Models\Staff;
use App\Models\SurveyQuestion;
use App\Models\Tour;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The feedback sheet: the form the app asks for and the submission it
 * sends back, against the real ARTA question bank the migration seeds.
 */
class SurveyFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function token(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    /** A complete, valid set of answers: CC1 = aware, every SQD "agree". */
    private function answers(array $overrides = []): array
    {
        $answers = ['CC1' => 1, 'CC2' => 1, 'CC3' => 1];
        foreach (SurveyQuestion::where('section', 'sqd')->pluck('code') as $code) {
            $answers[$code] = 4;
        }
        foreach (SurveyQuestion::where('section', 'app')->pluck('code') as $code) {
            $answers[$code] = 5;
        }

        return $overrides + $answers;
    }

    // -- The form ------------------------------------------------------------

    public function test_the_form_lists_active_questions_with_choices_and_defaults(): void
    {
        $v = Visitor::factory()->paid()->create();

        $res = $this->getJson('/api/v1/survey', $this->token($v))->assertOk();

        $codes = collect($res->json('questions'))->pluck('code');
        $this->assertTrue($codes->contains('CC1'));
        $this->assertTrue($codes->contains('SQD8'));

        $sqd1 = collect($res->json('questions'))->firstWhere('code', 'SQD1');
        $this->assertSame('agree5', $sqd1['scale']);
        $this->assertCount(6, $sqd1['choices'], 'five agreement steps plus N/A');
        $this->assertSame('Strongly agree', $sqd1['choices'][4]['en']);

        $res->assertJsonPath('guided', false)
            ->assertJsonPath('defaults.client_type', 'citizen')
            ->assertJsonPath('defaults.region', 'III – Central Luzon');
        $this->assertContains('BARMM – Bangsamoro', $res->json('regions'));
    }

    public function test_a_foreign_visitor_defaults_to_outside_the_philippines(): void
    {
        $v = Visitor::factory()->paid()->create(['visitor_type' => 'Foreign', 'country' => 'Japan']);

        $this->getJson('/api/v1/survey', $this->token($v))
            ->assertJsonPath('defaults.region', 'Outside the Philippines');
    }

    public function test_a_retired_question_is_left_off_the_form(): void
    {
        SurveyQuestion::where('code', 'SQD8')->update(['is_active' => false]);
        $v = Visitor::factory()->paid()->create();

        $codes = collect($this->getJson('/api/v1/survey', $this->token($v))->json('questions'))->pluck('code');
        $this->assertFalse($codes->contains('SQD8'));
    }

    public function test_a_guided_visit_names_the_guide(): void
    {
        $guide = Staff::factory()->administrator()->create(['name' => 'Kuya Ben']);
        $v     = Visitor::factory()->paid()->create();
        Tour::create(['guide_staff_id' => $guide->staff_id, 'tour_type' => 'Requested', 'visitor_id' => $v->visitor_id, 'headcount' => 1, 'started_at' => now(), 'created_by' => $guide->staff_id]);

        $this->getJson('/api/v1/survey', $this->token($v))
            ->assertJsonPath('guided', true)
            ->assertJsonPath('guide_name', 'Kuya Ben');
    }

    // -- The submission ------------------------------------------------------

    public function test_a_full_survey_is_filed_in_one_piece(): void
    {
        $v = Visitor::factory()->paid()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this->postJson('/api/v1/feedback', [
            'rating' => 5, 'comment' => 'Lovely.', 'client_type' => 'citizen', 'region' => 'III – Central Luzon',
            'answers' => $this->answers(['SQD5' => null]),
        ], $this->token($v))->assertOk()->assertJson(['ok' => true]);

        $f = Feedback::firstOrFail();
        $this->assertSame($v->visitor_id, $f->visitor_id);
        $this->assertSame('Maria', $f->first_name, 'name falls back to the account');
        $this->assertSame('none', $f->attributed_by);
        $this->assertNull($f->guide_rating);

        $answers = FeedbackAnswer::where('feedback_id', $f->feedback_id)->pluck('value', 'code');
        $this->assertSame(1, $answers['CC1']);
        $this->assertSame(4, $answers['SQD1']);
        $this->assertNull($answers['SQD5'], 'N/A is stored explicitly');
    }

    public function test_a_missing_required_answer_files_nothing(): void
    {
        $v = Visitor::factory()->paid()->create();

        // Left out entirely - null would be a legitimate N/A on an SQD item.
        $without = $this->answers();
        unset($without['SQD2']);

        $this->postJson('/api/v1/feedback', ['rating' => 4, 'answers' => $without], $this->token($v))
            ->assertStatus(422)
            ->assertJson(['error' => 'missing_answer', 'code' => 'SQD2']);

        $this->postJson('/api/v1/feedback', ['rating' => 4, 'answers' => $this->answers(['SQD2' => 9])], $this->token($v))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_answer', 'code' => 'SQD2']);

        $this->assertSame(0, Feedback::count());
        $this->assertSame(0, FeedbackAnswer::count());
    }

    public function test_cc2_and_cc3_are_na_when_cc1_says_unaware(): void
    {
        $v = Visitor::factory()->paid()->create();

        // CC1 = 4 ("I do not know what a CC is"): CC2 and CC3 are not asked,
        // and the paper form says to mark them N/A.
        $this->postJson('/api/v1/feedback', ['rating' => 4, 'answers' => $this->answers(['CC1' => 4, 'CC2' => 1, 'CC3' => 1])], $this->token($v))->assertOk();

        $answers = FeedbackAnswer::pluck('value', 'code');
        $cc2 = SurveyQuestion::where('code', 'CC2')->first();
        $na  = collect($cc2->choices())->firstWhere('na', true)['value'];
        $this->assertSame($na, $answers['CC2']);
    }

    public function test_a_star_only_submission_from_an_older_app_still_works(): void
    {
        $v = Visitor::factory()->paid()->create();

        $this->postJson('/api/v1/feedback', ['rating' => 3, 'comment' => 'ok'], $this->token($v))->assertOk();

        $this->assertSame(1, Feedback::count());
        $this->assertSame(0, FeedbackAnswer::count());
    }

    public function test_the_guide_rating_only_lands_on_a_visit_that_had_a_guide(): void
    {
        $guide = Staff::factory()->administrator()->create();
        $v     = Visitor::factory()->paid()->create();
        $h     = $this->token($v);

        // No tour: the rating the app sent is dropped, whatever it says.
        $this->postJson('/api/v1/feedback', ['rating' => 5, 'guide_rating' => 5, 'staff_id' => $guide->staff_id], $h)->assertOk();
        $this->assertNull(Feedback::latest('feedback_id')->first()->staff_id);

        Tour::create(['guide_staff_id' => $guide->staff_id, 'tour_type' => 'Requested', 'visitor_id' => $v->visitor_id, 'headcount' => 1, 'started_at' => now(), 'created_by' => $guide->staff_id]);

        $this->postJson('/api/v1/feedback', ['rating' => 5, 'guide_rating' => 4], $h)->assertOk();
        $f = Feedback::latest('feedback_id')->first();
        $this->assertSame($guide->staff_id, $f->staff_id);
        $this->assertSame(4, $f->guide_rating);
        $this->assertSame('visitor', $f->attributed_by);
    }

    public function test_the_shape_is_validated_and_the_gate_applies(): void
    {
        $v = Visitor::factory()->paid()->create();
        $h = $this->token($v);

        $this->postJson('/api/v1/feedback', ['rating' => 6], $h)->assertStatus(422)->assertJsonPath('field', 'rating');
        $this->postJson('/api/v1/feedback', ['rating' => 4, 'region' => 'Mars'], $h)->assertStatus(422)->assertJsonPath('field', 'region');
        $this->postJson('/api/v1/feedback', ['rating' => 4, 'comment' => str_repeat('x', 2001)], $h)->assertStatus(422);

        $this->postJson('/api/v1/feedback', ['rating' => 4], $this->token(Visitor::factory()->create()))->assertStatus(403);
        $this->getJson('/api/v1/survey')->assertStatus(401);
    }
}
