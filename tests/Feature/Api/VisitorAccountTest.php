<?php

namespace Tests\Feature\Api;

use App\Models\MuseumInfo;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A visitor's account, from the phone's side: signing up, signing in and
 * out, waiting on the desk, joining a party. What was visitor.php's
 * register / login / logout / status / join_group.
 */
class VisitorAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No DNS in a test: example.org is real, dead.invalid is not.
        MailDomain::fakeResolver(fn (string $domain) => $domain !== 'dead.invalid');
        RateLimiter::clear('login:ip:127.0.0.1');
    }

    protected function tearDown(): void
    {
        MailDomain::fakeResolver(null);
        parent::tearDown();
    }

    private function signUp(array $overrides = []): array
    {
        return $overrides + [
            'first_name'            => 'Maria',
            'last_name'             => 'Santos',
            'age'                   => 34,
            'sex'                   => 'Female',
            'visit_type'            => 'Solo',
            'visitor_type'          => 'Tourist',
            'city'                  => 'Quezon City',
            'province'              => 'Metro Manila',
            'country'               => 'Philippines',
            'email'                 => 'maria@example.org',
            'password'              => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
            'explore_mode'          => 'Storyline',
        ];
    }

    private function token(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    // -- Register ------------------------------------------------------------

    public function test_a_tourist_registers_owes_the_fee_and_waits_on_the_desk(): void
    {
        MuseumInfo::create(['name' => 'Museo de Baler', 'admission_fee' => 75]);

        $res = $this->postJson('/api/v1/visitors', $this->signUp())->assertCreated();

        $res->assertJsonPath('first_name', 'Maria')
            ->assertJsonPath('admission_fee', 75)
            ->assertJsonPath('payment_status', 'Unpaid')
            ->assertJsonPath('clearance', 'pending_payment')
            ->assertJsonPath('cleared', false)
            ->assertJsonPath('returning', false)
            ->assertJsonMissingPath('password');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $res->json('token'));

        $v = Visitor::firstWhere('email', 'maria@example.org');
        $this->assertNotSame('correct horse battery', $v->password, 'stored hashed');
        $this->assertSame('app', $v->source);
        $this->assertNotNull($v->last_visit);

        // The token works, and reaches the gate but not through it.
        $this->getJson('/api/v1/visitors/me', ['Authorization' => 'Bearer ' . $res->json('token')])
            ->assertOk()->assertJsonPath('clearance', 'pending_payment');
        $this->getJson('/api/v1/exhibits', ['Authorization' => 'Bearer ' . $res->json('token')])->assertStatus(403);
    }

    public function test_a_local_must_name_a_baler_barangay_and_gets_baler_filled_in(): void
    {
        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('field', 'barangay');

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local', 'barangay' => 'Makati']))
            ->assertStatus(422)->assertJsonPath('field', 'barangay');

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local', 'barangay' => 'Sabang', 'city' => 'Manila']))
            ->assertCreated()
            ->assertJsonPath('city', 'Baler')
            ->assertJsonPath('province', 'Aurora')
            ->assertJsonPath('barangay', 'Sabang')
            ->assertJsonPath('admission_fee', 0)
            ->assertJsonPath('payment_status', 'Free')
            ->assertJsonPath('clearance', 'pending_id');
    }

    public function test_a_foreign_visitor_must_say_which_country(): void
    {
        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Foreign', 'country' => 'Philippines']))
            ->assertStatus(422)->assertJsonPath('field', 'country');

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Foreign', 'country' => 'Japan']))
            ->assertCreated()->assertJsonPath('country', 'Japan');
    }

    public function test_the_fee_is_never_taken_from_the_request(): void
    {
        $res = $this->postJson('/api/v1/visitors', $this->signUp([
            'admission_fee' => 0, 'payment_status' => 'Paid', 'id_verified' => true, 'cleared' => true,
        ]))->assertCreated();

        $res->assertJsonPath('payment_status', 'Unpaid')->assertJsonPath('cleared', false);
    }

    public function test_weak_passwords_are_refused_with_a_reason(): void
    {
        foreach (['short' => 'abc', 'common' => 'Password123!', 'personal' => 'santos2024xx', 'run' => '12345678'] as $why => $pw) {
            $this->postJson('/api/v1/visitors', $this->signUp(['password' => $pw, 'password_confirmation' => $pw]))
                ->assertStatus(422)
                ->assertJsonPath('field', 'password');
        }

        $this->postJson('/api/v1/visitors', $this->signUp(['password_confirmation' => 'different']))
            ->assertStatus(422)
            ->assertJsonPath('field', 'password')
            ->assertJsonPath('message', 'The two passwords do not match.');
    }

    public function test_a_known_email_is_sent_to_sign_in_not_signed_in(): void
    {
        Visitor::factory()->create(['email' => 'maria@example.org']);

        $this->postJson('/api/v1/visitors', $this->signUp())
            ->assertStatus(422)
            ->assertJsonPath('error', 'email_taken')
            ->assertJsonMissingPath('token');
    }

    public function test_an_email_at_a_domain_that_takes_no_mail_is_refused(): void
    {
        $this->postJson('/api/v1/visitors', $this->signUp(['email' => 'x@dead.invalid']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'email_domain_invalid');
    }

    public function test_html_in_a_name_is_stripped_before_it_is_stored(): void
    {
        $this->postJson('/api/v1/visitors', $this->signUp(['first_name' => 'Maria<script>alert(1)</script>']))
            ->assertCreated()
            ->assertJsonPath('first_name', 'Mariaalert(1)');
    }

    public function test_registering_with_a_group_code_joins_the_party(): void
    {
        $group = VisitGroup::create([
            'group_name' => 'Baler Central School', 'group_type' => 'School', 'visitor_type' => 'Tourist',
            'contact_name' => 'Ms Reyes', 'headcount' => 2, 'paying_count' => 2, 'total_fee' => 100,
            'payment_status' => 'Paid', 'visit_date' => today(), 'join_code' => 'ABCDEF',
        ]);

        $this->postJson('/api/v1/visitors', $this->signUp(['group_code' => 'abcdef']))
            ->assertCreated()
            ->assertJsonPath('visit_type', 'School')
            ->assertJsonPath('payment_status', 'Free')
            ->assertJsonPath('cleared', true)
            ->assertJsonPath('group.label', 'Baler Central School');

        $this->postJson('/api/v1/visitors', $this->signUp(['email' => 'second@example.org', 'group_code' => 'ABCDEF']))->assertCreated();

        // Two joined on a headcount of two: the code is spent.
        $this->postJson('/api/v1/visitors', $this->signUp(['email' => 'third@example.org', 'group_code' => 'ABCDEF']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'group_full');

        $this->assertSame(0, Visitor::where('email', 'third@example.org')->count(), 'a bad code makes no half account');

        $this->postJson('/api/v1/visitors', $this->signUp(['email' => 'fourth@example.org', 'group_code' => 'ZZZZ']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'group_not_found');
    }

    // -- Sign in / out ------------------------------------------------------

    public function test_sign_in_issues_a_token_and_resets_the_fee_on_a_new_day(): void
    {
        MuseumInfo::create(['name' => 'Museo de Baler', 'admission_fee' => 50]);
        $v = Visitor::factory()->paid()->create(['email' => 'maria@example.org', 'last_visit' => now()->subDays(3)]);

        $res = $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'correct horse battery'])
            ->assertOk()
            ->assertJsonPath('returning', true)
            ->assertJsonPath('payment_status', 'Unpaid')
            ->assertJsonPath('clearance', 'pending_payment');

        $this->getJson('/api/v1/visitors/me', ['Authorization' => 'Bearer ' . $res->json('token')])->assertOk();

        // Same day: paid stays paid.
        $v->refresh()->forceFill(['payment_status' => 'Paid', 'last_visit' => now()])->save();
        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'correct horse battery'])
            ->assertOk()->assertJsonPath('cleared', true);
    }

    public function test_wrong_password_and_unknown_email_are_the_same_answer(): void
    {
        Visitor::factory()->create(['email' => 'maria@example.org']);

        $wrong   = $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'nope']);
        $unknown = $this->postJson('/api/v1/visitors/login', ['email' => 'nobody@example.org', 'password' => 'nope']);
        $broken  = $this->postJson('/api/v1/visitors/login', ['email' => 'not-an-email', 'password' => 'nope']);

        foreach ([$wrong, $unknown, $broken] as $res) {
            $res->assertStatus(401)->assertExactJson(['error' => 'invalid_credentials']);
        }
    }

    public function test_sign_in_is_throttled_per_account(): void
    {
        Visitor::factory()->create(['email' => 'maria@example.org']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'guess' . $i])->assertStatus(401);
        }

        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'guess'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // A different account from the same address is not locked out with it.
        Visitor::factory()->create(['email' => 'jun@example.org']);
        $this->postJson('/api/v1/visitors/login', ['email' => 'jun@example.org', 'password' => 'correct horse battery'])->assertOk();
    }

    public function test_sign_out_kills_the_token(): void
    {
        $v = Visitor::factory()->paid()->create();
        $h = $this->token($v);

        $this->getJson('/api/v1/visitors/me', $h)->assertOk();
        $this->postJson('/api/v1/visitors/logout', [], $h)->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/api/v1/visitors/me', $h)->assertStatus(401);

        // Signing out twice, or with no token at all, is not an error.
        $this->postJson('/api/v1/visitors/logout')->assertOk();
    }

    // -- Join a group after signing in ---------------------------------------

    public function test_a_waiting_visitor_can_join_a_paid_group_and_walk_in(): void
    {
        $group = VisitGroup::create([
            'contact_name' => 'Jun Molina', 'group_type' => 'Family', 'visitor_type' => 'Tourist',
            'headcount' => 4, 'paying_count' => 4, 'total_fee' => 200,
            'payment_status' => 'Paid', 'visit_date' => today(), 'join_code' => 'FAMILY',
        ]);
        $v = Visitor::factory()->create();
        $h = $this->token($v);

        $this->postJson('/api/v1/visitors/me/group', ['group_code' => 'family'], $h)
            ->assertOk()
            ->assertJsonPath('joined', true)
            ->assertJsonPath('visit_type', 'Family')
            ->assertJsonPath('cleared', true)
            ->assertJsonPath('group.label', "Jun Molina's group");

        $this->getJson('/api/v1/exhibits', $h)->assertOk();

        // Rejoining is a no-op, not a second seat.
        $this->postJson('/api/v1/visitors/me/group', ['group_code' => 'FAMILY'], $h)->assertOk();
        $this->assertSame(1, $group->visitors()->count());
    }

    public function test_someone_who_paid_alone_is_sent_to_the_desk_instead(): void
    {
        $v = Visitor::factory()->paid()->create();

        $this->postJson('/api/v1/visitors/me/group', ['group_code' => 'FAMILY'], $this->token($v))
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_paid');
    }

    public function test_yesterdays_code_admits_nobody(): void
    {
        VisitGroup::create([
            'contact_name' => 'Old', 'group_type' => 'Group', 'visitor_type' => 'Tourist',
            'headcount' => 9, 'paying_count' => 9, 'total_fee' => 450,
            'payment_status' => 'Paid', 'visit_date' => today()->subDay(), 'join_code' => 'OLDOLD',
        ]);
        $v = Visitor::factory()->create();

        $this->postJson('/api/v1/visitors/me/group', ['group_code' => 'OLDOLD'], $this->token($v))
            ->assertStatus(422)
            ->assertJsonPath('error', 'group_not_found');
    }
}
