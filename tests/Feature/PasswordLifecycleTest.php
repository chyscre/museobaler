<?php

namespace Tests\Feature;

use App\Models\Log;
use App\Models\Staff;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who owns a staff password.
 *
 * Accounts are assigned - nobody self-registers, because the tier being
 * overseen must not mint its own overseers. But an assigned password that can
 * never be changed is a credential two people know forever, and a credential
 * two people know cannot be held against either of them in the audit log.
 *
 * So the split these tests pin down is: the Tourism office assigns the
 * account, and the staff member owns the password.
 */
class PasswordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PasswordPolicy asks HaveIBeenPwned whether a password has appeared
        // in a breach. An empty response means "no match", which is what a
        // freshly invented test password would really get - and it keeps the
        // suite off the network.
        Http::fake(['*' => Http::response('', 200)]);
    }

    // -- Issuing --------------------------------------------------------

    public function test_creating_an_account_issues_a_password_rather_than_accepting_one(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $response = $this->actingAs($tourism)->post('/staff', [
            'name'  => 'Maria Santos',
            'email' => 'maria@museobaler.ph',
            'role'  => 'Administrator',
            // Sent the way the old form sent it. It must not be what ends up
            // on the account, or the office goes on knowing a live password.
            'password' => 'Password1!',
        ]);

        $response->assertRedirect(route('staff.index'));

        $created = Staff::where('email', 'maria@museobaler.ph')->firstOrFail();

        $this->assertFalse(Hash::check('Password1!', $created->password));
        $this->assertTrue($created->must_change_password);
        $this->assertNull($created->password_changed_at);

        // Shown to Tourism once, so it can be handed over, and it is the
        // password that actually works.
        $issued = session('issued_credential');
        $this->assertNotNull($issued);
        $this->assertTrue(Hash::check($issued['password'], $created->password));
    }

    public function test_two_issued_passwords_are_never_the_same(): void
    {
        $first  = PasswordPolicy::generateTemporary();
        $second = PasswordPolicy::generateTemporary();

        $this->assertNotSame($first, $second);
        $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($first));
    }

    public function test_tourism_can_reissue_a_password_for_someone_locked_out(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $staff   = Staff::factory()->administrator()->create();

        $before = $staff->password;

        $this->actingAs($tourism)
            ->post("/staff/{$staff->staff_id}/reset-password")
            ->assertRedirect(route('staff.index'));

        $staff->refresh();

        $this->assertNotSame($before, $staff->password);
        $this->assertTrue($staff->must_change_password);
        $this->assertDatabaseHas('logs', ['action' => 'Staff Password Reset']);
    }

    public function test_museum_staff_cannot_reissue_anybody_a_password(): void
    {
        // Otherwise the tier being overseen could take over its overseer's
        // account, which is the whole boundary this system rests on.
        $staff  = Staff::factory()->administrator()->create();
        $victim = Staff::factory()->tourismHead()->create();

        $before = $victim->password;

        $this->actingAs($staff)
            ->post("/staff/{$victim->staff_id}/reset-password")
            ->assertForbidden();

        $this->assertSame($before, $victim->fresh()->password);
    }

    public function test_the_edit_form_can_no_longer_set_a_password(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $staff   = Staff::factory()->administrator()->create();

        $before = $staff->password;

        $this->actingAs($tourism)->put("/staff/{$staff->staff_id}", [
            'name'     => 'Renamed Person',
            'email'    => $staff->email,
            'role'     => 'Administrator',
            'password' => 'TakenOver@2026x',
        ])->assertRedirect(route('staff.index'));

        $staff->refresh();

        $this->assertSame('Renamed Person', $staff->name);
        $this->assertSame($before, $staff->password);
    }

    // -- The forced first change ----------------------------------------

    public function test_an_account_on_an_issued_password_reaches_nothing_else(): void
    {
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create();

        $this->actingAs($staff)->get('/')->assertRedirect(route('password.edit'));
        $this->actingAs($staff)->get('/desk')->assertRedirect(route('password.edit'));
        $this->actingAs($staff)->get('/my/attendance')->assertRedirect(route('password.edit'));
    }

    public function test_it_can_still_reach_the_password_screen_and_sign_out(): void
    {
        // Penning someone in a screen they cannot leave is how two people end
        // up sharing one signed-in browser.
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create();

        $this->actingAs($staff)->get('/my/password')->assertOk();
        $this->actingAs($staff)->post('/logout')->assertRedirect(route('login'));
    }

    public function test_the_tourism_office_is_held_to_it_as_well(): void
    {
        $tourism = Staff::factory()->tourismHead()->awaitingPasswordChange()->create();

        $this->actingAs($tourism)->get('/staff')->assertRedirect(route('password.edit'));
    }

    public function test_setting_a_password_opens_the_rest_of_the_system(): void
    {
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create();

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Password1!',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ])->assertRedirect(route('dashboard'));

        $staff->refresh();

        $this->assertFalse($staff->must_change_password);
        $this->assertNotNull($staff->password_changed_at);
        $this->assertTrue(Hash::check('Kalabaw-tuwid-9-bakod', $staff->password));

        $this->actingAs($staff)->get('/')->assertOk();
    }

    public function test_the_change_is_logged_and_the_password_is_not(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Password1!',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ]);

        $entry = Log::where('action', 'Password Changed')->first();

        $this->assertNotNull($entry);
        $this->assertSame($staff->staff_id, $entry->user_id);
        $this->assertStringNotContainsString('Kalabaw', $entry->details);
    }

    // -- What the rules refuse ------------------------------------------

    public function test_the_current_password_has_to_be_proved(): void
    {
        // Otherwise an unattended signed-in browser is a permanent takeover
        // for whoever walks past the desk.
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'NotTheRightOne1!',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Password1!', $staff->fresh()->password));
    }

    #[DataProvider('refusedPasswords')]
    public function test_a_weak_or_guessable_password_is_refused(string $password): void
    {
        $staff = Staff::factory()->administrator()->create([
            'name'  => 'Maria Santos',
            'email' => 'maria.santos@museobaler.ph',
        ]);

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Password1!',
            'password'              => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Password1!', $staff->fresh()->password));
    }

    public static function refusedPasswords(): array
    {
        return [
            'too short'            => ['Ab1@efgh'],          // passed the old eight-character rule
            'no symbol'            => ['abcdefgh1234A'],
            'no digit'             => ['abcdefghijk@A'],
            'a common word'        => ['Password@123'],
            'leetspeak of one'     => ['P@ssw0rd!2345'],
            'their own first name' => ['Maria-tuwid-9-bakod'],
            'their own surname'    => ['Santos-tuwid-9-bakod'],
            'their email name'     => ['MariaSantos-9-bakod!'],
            'the museum'           => ['Museobaler-9-bakod!'],
            'the town'             => ['Baler-tuwid-9-bakod!'],
        ];
    }

    public function test_the_two_boxes_have_to_match(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Password1!',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-8-bakod',
        ])->assertSessionHasErrors('password');
    }

    public function test_the_new_password_cannot_be_the_old_one(): void
    {
        // Reusing it would leave the account exactly where this screen exists
        // to move it out of.
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create([
            'password' => Hash::make('Kalabaw-tuwid-9-bakod'),
        ]);

        $this->actingAs($staff)->put('/my/password', [
            'current_password'      => 'Kalabaw-tuwid-9-bakod',
            'password'              => 'Kalabaw-tuwid-9-bakod',
            'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($staff->fresh()->must_change_password);
    }
}
