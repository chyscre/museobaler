<?php

namespace Tests\Feature\Api;

use App\Models\Visitor;
use App\Support\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Continue with Google: Google's redirect lands on a web route, which hands
 * the app a one-time code; the app trades it for a session, or for a
 * sign-up token if the museum has never seen this person.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        MailDomain::fakeResolver(fn () => true);
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    protected function tearDown(): void
    {
        MailDomain::fakeResolver(null);
        parent::tearDown();
    }

    /** Google answers the callback as this person. */
    private function googleSays(array $raw): void
    {
        $raw += ['sub' => '1098765', 'email' => 'maria@gmail.com', 'email_verified' => true,
                 'given_name' => 'Maria', 'family_name' => 'Santos'];

        $user = (new GoogleUser)->setRaw($raw)->map([
            'id' => $raw['sub'], 'email' => $raw['email'],
            'name' => trim($raw['given_name'] . ' ' . $raw['family_name']),
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** The fragment the callback sent the browser back to the app with. */
    private function fragment(TestResponse $res): array
    {
        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringContainsString('/visitor/index.html#', $location);
        parse_str(substr($location, strpos($location, '#') + 1), $fragment);

        return $fragment;
    }

    private function googleCallback(): array
    {
        return $this->fragment($this->get('/auth/google/callback?code=abc&state=xyz'));
    }

    private function exchange(string $code): TestResponse
    {
        return $this->postJson('/api/v1/visitors/google', ['code' => $code]);
    }

    public function test_the_button_is_offered_only_once_configured(): void
    {
        $this->getJson('/api/v1/museum')->assertJsonPath('info.google_sign_in', true);

        config(['services.google.client_id' => null]);
        $this->getJson('/api/v1/museum')->assertJsonPath('info.google_sign_in', false);
        $this->assertSame(['google_error' => 'unavailable'], $this->fragment($this->get('/auth/google')));
    }

    public function test_the_trip_starts_at_google(): void
    {
        $this->get('/auth/google')->assertRedirectContains('accounts.google.com');
    }

    public function test_someone_new_signs_up_verified_with_no_password(): void
    {
        $this->googleSays([]);
        $code = $this->googleCallback()['google'];

        $handoff = $this->exchange($code)->assertOk()
            ->assertJson(['email' => 'maria@gmail.com', 'first_name' => 'Maria', 'last_name' => 'Santos'])
            ->json();

        // The code works once.
        $this->exchange($code)->assertStatus(422)->assertJson(['error' => 'google_expired']);

        $res = $this->postJson('/api/v1/visitors', [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'age' => 34, 'sex' => 'Female',
            'visit_type' => 'Solo', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'country' => 'Philippines',
            // Whatever the form says, the address is the one Google checked.
            'email' => 'someone-else@example.org',
            'google_signup' => $handoff['google_signup'],
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $res->json('token'));
        Mail::assertNothingSent();

        $visitor = Visitor::firstWhere('email', 'maria@gmail.com');
        $this->assertNotNull($visitor->email_verified_at);
        $this->assertSame('google', $visitor->auth_provider);
        $this->assertSame('1098765', $visitor->google_id);
        $this->assertNull($visitor->password);
        $this->assertDatabaseMissing('visitors', ['email' => 'someone-else@example.org']);
    }

    public function test_a_sign_up_token_works_once(): void
    {
        $this->googleSays([]);
        $token = $this->exchange($this->googleCallback()['google'])->json('google_signup');

        $form = [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'email' => 'maria@gmail.com',
            'google_signup' => $token,
        ];
        $this->postJson('/api/v1/visitors', $form)->assertCreated();
        $this->postJson('/api/v1/visitors', $form)->assertStatus(422)->assertJson(['error' => 'google_signup_expired']);
    }

    public function test_an_existing_visitor_is_signed_in_and_linked(): void
    {
        $visitor = Visitor::factory()->create(['email' => 'maria@gmail.com']);
        $this->googleSays([]);

        $res = $this->exchange($this->googleCallback()['google'])->assertOk()->assertJson(['returning' => true]);
        $this->getJson('/api/v1/visitors/me', ['Authorization' => 'Bearer ' . $res->json('token')])->assertOk();

        $this->assertSame('1098765', $visitor->fresh()->google_id);
        // Their password still works alongside Google.
        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@gmail.com', 'password' => 'Correct-horse-battery-7'])->assertOk();
    }

    public function test_an_unverified_account_loses_the_password_that_made_it(): void
    {
        // Registered by someone who does not own the inbox, never verified.
        $visitor = Visitor::factory()->unverified()->create(['email' => 'maria@gmail.com']);
        $this->googleSays([]);

        $this->exchange($this->googleCallback()['google'])->assertOk();

        $visitor->refresh();
        $this->assertNotNull($visitor->email_verified_at);
        $this->assertNull($visitor->password);
        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@gmail.com', 'password' => 'Correct-horse-battery-7'])
            ->assertStatus(401);
    }

    public function test_an_address_google_has_not_confirmed_is_refused(): void
    {
        $this->googleSays(['email_verified' => false]);

        $this->assertSame(['google_error' => 'email_unverified'], $this->googleCallback());
        $this->assertDatabaseCount('visitors', 0);
    }

    public function test_cancelling_at_google_comes_back_as_cancelled(): void
    {
        $this->assertSame(['google_error' => 'cancelled'],
            $this->fragment($this->get('/auth/google/callback?error=access_denied')));
    }

    public function test_an_address_already_linked_to_another_google_account_is_refused(): void
    {
        Visitor::factory()->create(['email' => 'maria@gmail.com', 'google_id' => '555']);
        $this->googleSays([]);

        $this->assertSame(['google_error' => 'conflict'], $this->googleCallback());
    }

    public function test_a_made_up_code_gets_nothing(): void
    {
        $this->exchange(str_repeat('a', 64))->assertStatus(422);
        $this->exchange('nope')->assertStatus(422);
        $this->postJson('/api/v1/visitors/google', ['code' => ['x']])->assertStatus(422);
    }
}
