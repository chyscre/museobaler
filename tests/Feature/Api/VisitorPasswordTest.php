<?php

namespace Tests\Feature\Api;

use App\Mail\VisitorPasswordChangedMail;
use App\Mail\VisitorPasswordCodeMail;
use App\Models\Visitor;
use App\Models\VisitorPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A visitor's password from the phone's side: the forgotten-password code
 * by email, and the change from Settings.
 */
class VisitorPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Correct-horse-battery-7';
    private const NEW = 'Blue-kayak-tuesday-4';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function visitor(): Visitor
    {
        return Visitor::create([
            'first_name'     => 'Maria',
            'last_name'      => 'Santos',
            'visitor_type'   => 'Tourist',
            'visit_type'     => 'Solo',
            'email'          => 'maria@example.org',
            'password'       => self::OLD,
            'admission_fee'  => 0,
            'payment_status' => 'Free',
        ]);
    }

    /** Ask for a code and return it as the email carried it. */
    private function requestCode(string $email = 'maria@example.org'): ?string
    {
        $this->postJson('/api/v1/visitors/password/forgot', ['email' => $email])->assertOk();

        $code = null;
        Mail::assertSent(VisitorPasswordCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        return $code;
    }

    private function verify(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/visitors/password/verify', [
            'email' => 'maria@example.org', 'code' => $code,
        ]);
    }

    private function reset(string $token, string $password = self::NEW): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/visitors/password/reset', [
            'email'                 => 'maria@example.org',
            'reset_token'           => $token,
            'password'              => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_the_whole_reset_from_email_to_signing_in_with_the_new_password(): void
    {
        $visitor = $this->visitor();
        $visitor->issueToken();

        $code = $this->requestCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertSent(VisitorPasswordCodeMail::class, fn ($m) => $m->hasTo('maria@example.org'));

        $token = $this->verify($code)->assertOk()->json('reset_token');
        $this->reset($token)->assertOk();

        $visitor->refresh();
        $this->assertTrue(Hash::check(self::NEW, $visitor->password));
        $this->assertStringStartsWith('$2y$', $visitor->password, 'stored as a bcrypt hash');
        $this->assertNull($visitor->api_token, 'every session ends on a reset');
        $this->assertSame(0, VisitorPasswordReset::count());
        Mail::assertSent(VisitorPasswordChangedMail::class);

        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => self::NEW])->assertOk();
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        $this->visitor();

        $known   = $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'maria@example.org'])->json();
        Mail::fake();
        $unknown = $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'nobody@example.org'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Mail::assertNothingSent();
    }

    public function test_the_code_is_stored_hashed_not_as_sent(): void
    {
        $this->visitor();
        $code = $this->requestCode();

        $row = VisitorPasswordReset::first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row->getAttributes()));
    }

    public function test_a_code_stops_working_after_fifteen_minutes(): void
    {
        $this->visitor();
        $code = $this->requestCode();

        $this->travel(16)->minutes();

        $this->verify($code)->assertStatus(422)->assertJson(['error' => 'code_expired']);
    }

    public function test_five_wrong_codes_void_the_request(): void
    {
        $this->visitor();
        $code  = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i < VisitorPasswordReset::MAX_ATTEMPTS; $i++) {
            $this->verify($wrong)->assertStatus(422)->assertJson(['error' => 'code_invalid']);
        }
        $this->verify($wrong)->assertStatus(422)->assertJson(['error' => 'code_locked']);

        // Even the right code is dead now.
        $this->verify($code)->assertStatus(422)->assertJson(['error' => 'code_invalid']);
    }

    public function test_a_code_works_once_and_a_new_one_voids_the_old(): void
    {
        $this->visitor();
        $first = $this->requestCode();
        Mail::fake();
        $second = $this->requestCode();

        if ($first !== $second) {
            $this->verify($first)->assertStatus(422);
        }
        $this->verify($second)->assertOk();
        $this->verify($second)->assertStatus(422);
    }

    public function test_the_new_password_cannot_be_set_without_a_verified_code(): void
    {
        $this->visitor();
        $this->requestCode();

        $this->reset(str_repeat('a', 64))->assertStatus(422)->assertJson(['error' => 'reset_expired']);
        $this->assertTrue(Hash::check(self::OLD, Visitor::first()->password));
    }

    public function test_the_new_password_follows_the_sign_up_rules(): void
    {
        $this->visitor();
        $token = $this->verify($this->requestCode())->json('reset_token');

        $this->reset($token, 'password123')->assertStatus(422)->assertJson(['field' => 'password']);
        $this->reset($token, 'maria santos 77')->assertStatus(422)->assertJson(['field' => 'password']);

        // A refused password does not spend the token.
        $this->reset($token)->assertOk();
    }

    public function test_asking_for_codes_is_throttled_per_email(): void
    {
        $this->visitor();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'maria@example.org'])->assertOk();
        }
        $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'maria@example.org'])->assertStatus(429);
    }

    public function test_a_mail_failure_is_reported_and_leaves_no_code_behind(): void
    {
        $this->visitor();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'maria@example.org'])
            ->assertStatus(503)->assertJson(['error' => 'mail_unavailable']);
        $this->assertSame(0, VisitorPasswordReset::count());
    }

    // -- Change from Settings ---------------------------------------------

    private function change(Visitor $v, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $v->issueToken()['token'])
            ->putJson('/api/v1/visitors/me/password', $body + ['password_confirmation' => $body['password'] ?? null]);
    }

    public function test_a_signed_in_visitor_changes_their_password_with_the_current_one(): void
    {
        $visitor = $this->visitor();

        $this->change($visitor, ['current_password' => self::OLD, 'password' => self::NEW])->assertOk();

        $this->assertTrue(Hash::check(self::NEW, $visitor->refresh()->password));
        $this->assertNotNull($visitor->api_token, 'the phone that changed it stays signed in');
        Mail::assertSent(VisitorPasswordChangedMail::class);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $visitor = $this->visitor();

        $this->change($visitor, ['current_password' => 'not my password', 'password' => self::NEW])
            ->assertStatus(422)
            ->assertJson(['error' => 'current_password_wrong', 'field' => 'current_password']);

        $this->assertTrue(Hash::check(self::OLD, $visitor->refresh()->password));
        Mail::assertNothingSent();
    }

    public function test_the_new_password_must_be_new_and_strong(): void
    {
        $visitor = $this->visitor();

        $this->change($visitor, ['current_password' => self::OLD, 'password' => self::OLD])
            ->assertStatus(422)->assertJson(['field' => 'password']);
        $this->change($visitor, ['current_password' => self::OLD, 'password' => 'qwerty123'])
            ->assertStatus(422)->assertJson(['field' => 'password']);
    }

    public function test_changing_needs_a_session(): void
    {
        $this->visitor();

        $this->putJson('/api/v1/visitors/me/password', [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertStatus(401);
    }

    public function test_guesses_at_the_current_password_are_throttled(): void
    {
        $visitor = $this->visitor();

        for ($i = 0; $i < 5; $i++) {
            $this->change($visitor, ['current_password' => "guess $i", 'password' => self::NEW])->assertStatus(422);
        }
        $this->change($visitor, ['current_password' => self::OLD, 'password' => self::NEW])->assertStatus(429);
    }
}
