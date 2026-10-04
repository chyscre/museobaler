<?php

namespace Tests\Feature;

use App\Models\Log;
use App\Models\Staff;
use App\Models\Visitor;
use App\Support\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * What the panel says to the people using it: the audit trail in words
 * rather than HTTP codes, a refused form answered in a sentence, Museum Info
 * in tabs, and one My Account page for who you are and your password.
 */
class SettingsUiTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function as(Staff $staff)
    {
        return $this->withHeader('User-Agent', self::LAPTOP)->actingAs($staff);
    }

    // -- Audit trail -----------------------------------------------------

    public function test_a_change_is_recorded_in_words_not_as_a_request(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->as($staff)->post('/museum', ['name' => 'Museo de Baler', 'admission_fee' => 50]);

        $row = Log::where('user_id', $staff->staff_id)->where('action', 'Update museum info')->first();
        $this->assertNotNull($row);
        $this->assertSame('Done', $row->details);
        $this->assertSame(0, Log::where('details', 'like', 'HTTP %')->count());
    }

    public function test_a_form_sent_back_with_errors_is_not_recorded_as_done(): void
    {
        $staff = Staff::factory()->administrator()->create();

        // A redirect, like a save - only the errors it flashed tell them apart.
        $this->as($staff)->post('/museum', ['name' => 'Museo de Baler', 'admission_fee' => -5])
            ->assertSessionHasErrors('admission_fee');

        $row = Log::where('action', 'Update museum info')->latest('log_id')->first();
        $this->assertStringStartsWith('Not saved', $row->details);
    }

    public function test_rows_written_the_old_way_are_shown_in_words(): void
    {
        $this->assertSame(
            ['Mark visitor #221 as paid', 'Submitted'],
            AuditTrail::describe('POST visitors/221/mark-paid', 'HTTP POST | Status: 302')
        );
        $this->assertSame(
            ['Sign in', 'Done'],
            AuditTrail::describe('POST login', 'HTTP POST | Status: 302')
        );
        $this->assertSame(
            ['Register a group at the desk', 'Failed: something went wrong on the server'],
            AuditTrail::describe('POST desk/groups', 'HTTP POST | Status: 500')
        );
        // What a controller wrote itself is already in words.
        $this->assertSame(
            ['Admission Paid', 'Collected PHP 50.00 from Juan'],
            AuditTrail::describe('Admission Paid', 'Collected PHP 50.00 from Juan')
        );
    }

    public function test_the_logs_page_never_shows_a_status_code(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        Log::create([
            'user_id' => $tourism->staff_id, 'user_name' => $tourism->name, 'role' => $tourism->role,
            'action'  => 'POST visitors/221/mark-paid', 'details' => 'HTTP POST | Status: 302',
        ]);

        $this->as($tourism)->get('/logs')->assertOk()
            ->assertSee('Mark visitor #221 as paid')
            ->assertDontSee('Status: 302')
            ->assertDontSee('POST visitors/221/mark-paid');
    }

    public function test_the_action_filter_groups_old_and_new_rows_by_kind(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        foreach (['POST visitors/1/mark-paid', 'POST visitors/2/mark-paid', 'Mark visitor #3 as paid'] as $action) {
            Log::create(['user_id' => $tourism->staff_id, 'user_name' => $tourism->name, 'action' => $action, 'details' => 'HTTP POST | Status: 302']);
        }

        $this->as($tourism)->get('/logs?action=' . urlencode('Mark visitor as paid'))->assertOk()
            ->assertSee('Mark visitor #1 as paid')
            ->assertSee('Mark visitor #2 as paid')
            ->assertSee('Mark visitor #3 as paid');
    }

    // -- Refused forms ---------------------------------------------------

    public function test_a_refused_form_gets_one_plain_sentence_and_its_fields_marked(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $bag = new ViewErrorBag();
        $bag->put('default', new MessageBag(['admission_fee' => 'The admission fee must be at least 0.']));

        $this->as($staff)->withSession(['errors' => $bag])
            ->get('/museum')->assertOk()
            ->assertSee('<div id="flashMsg" hidden data-kind="red">Some information is missing. Please check the highlighted fields.</div>', false)
            ->assertSee('var bad = ["admission_fee"]', false)
            // The tab holding the bad field is the one that opens.
            ->assertSee('data-error-tab="admission"', false);
    }

    // -- Desk actions from a notification -------------------------------

    public function test_a_notification_button_hears_how_mark_paid_went(): void
    {
        // A redirect followed in the background would spend the message on
        // a page nobody sees, so the toast's button asks for JSON.
        $staff   = Staff::factory()->administrator()->create();
        $visitor = Visitor::factory()->create(['visitor_type' => 'Tourist']);

        $this->as($staff)->postJson(route('visitors.mark-paid', $visitor))
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'marked as paid'));

        // Pressed twice (two desks, one toast each): the second hears why not.
        $this->as($staff)->postJson(route('visitors.mark-paid', $visitor->refresh()))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This admission fee is already marked as paid for today.']);
    }

    public function test_a_returning_visitor_signing_in_is_announced_not_just_a_red_dot(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->travelTo(now()->subDay());
        $visitor = Visitor::factory()->create(['visitor_type' => 'Tourist', 'first_name' => 'Jae', 'last_name' => 'Ken']);
        $visitor->touchReturning();
        $this->travelBack();

        // What the app's sign-in does on a later day.
        $visitor->refresh()->touchReturning();

        $res = $this->as($staff)->getJson(route('notifications.poll', ['init' => 1]))->assertOk();

        $back = collect($res->json('items'))->firstWhere('type', 'visitor');
        $this->assertNotNull($back);
        $this->assertStringStartsWith('back_', $back['id']);
        $this->assertSame('Jae Ken is back for another visit (Tourist)', $back['message']);
        // The toast pairs it with the fee now due, by visitor.
        $this->assertSame($visitor->visitor_id, $back['visitor_id']);
        $this->assertContains('pay_' . $visitor->visitor_id, collect($res->json('pending'))->pluck('id'));
    }

    public function test_a_first_visit_is_announced_once_as_a_registration(): void
    {
        $staff = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['visitor_type' => 'Tourist'])->touchReturning();

        $items = collect($this->as($staff)->getJson(route('notifications.poll', ['init' => 1]))->json('items'));

        $this->assertCount(0, $items->filter(fn ($i) => str_starts_with($i['id'], 'back_')));
    }

    public function test_the_desk_forms_still_get_the_page_back(): void
    {
        $staff   = Staff::factory()->administrator()->create();
        $visitor = Visitor::factory()->create(['visitor_type' => 'Tourist']);

        $this->as($staff)->from('/records')->post(route('visitors.mark-paid', $visitor))
            ->assertRedirect('/records')
            ->assertSessionHas('success');
    }

    // -- Museum Info -----------------------------------------------------

    public function test_museum_info_is_in_tabs_with_save_at_the_bottom(): void
    {
        $html = $this->as(Staff::factory()->administrator()->create())->get('/museum')->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('Save Changes')
            ->assertDontSee('Save All Changes')
            ->getContent();

        foreach (['general', 'admission', 'halls', 'location', 'branding'] as $tab) {
            $this->assertStringContainsString('id="pane-' . $tab . '"', $html);
        }
        // Save is inside the form, after every tab.
        $this->assertGreaterThan(strpos($html, 'id="pane-halls"'), strpos($html, 'Save Changes'));
        $this->assertLessThan(strpos($html, '</form>', strpos($html, 'id="museumForm"')), strpos($html, 'Save Changes'));
    }

    // -- My Account ------------------------------------------------------

    public function test_my_account_shows_the_profile_and_the_password_form_together(): void
    {
        $staff = Staff::factory()->administrator()->create(['name' => 'Maria Santos', 'email' => 'maria@museobaler.ph']);

        $this->as($staff)->get('/my/password')->assertOk()
            ->assertSee('My Account')
            ->assertSee('maria@museobaler.ph')
            ->assertSee('name="current_password"', false)
            // Inside the panel, not the bare sign-in style card.
            ->assertSee('class="sidebar"', false);
    }

    public function test_someone_on_an_issued_password_still_gets_the_bare_card(): void
    {
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create();

        $this->as($staff)->get('/my/password')->assertOk()
            ->assertSee('Set your own password')
            ->assertDontSee('class="sidebar"', false);
    }

    public function test_a_voluntary_change_comes_back_to_my_account(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->as($staff)->put('/my/password', [
            'current_password'      => 'Password1!',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ])->assertRedirect(route('password.edit'))->assertSessionHas('success');
    }
}
