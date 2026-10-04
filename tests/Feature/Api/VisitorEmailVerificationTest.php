<?php

namespace Tests\Feature\Api;

use App\Mail\VisitorPasswordCodeMail;
use App\Mail\VisitorVerificationCodeMail;
use App\Models\Visitor;
use App\Models\VisitorEmailVerification;
use App\Support\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The 6-digit code that proves a new visitor owns their email: sent at
 * sign-up, typed back before any session exists, resent on request.
 */
class VisitorEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-horse-battery-7';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        MailDomain::fakeResolver(fn () => true);
    }

    protected function tearDown(): void
    {
        MailDomain::fakeResolver(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signUp(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/visitors', [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'age' => 34, 'sex' => 'Female',
            'visit_type' => 'Solo', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'country' => 'Philippines',
            'email' => 'maria@example.org',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
    }

    /** The code in the newest verification email. */
    private function lastCode(): string
    {
        return Mail::sent(VisitorVerificationCodeMail::class)->last()->code;
    }

    private function verify(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/visitors/verify-email', ['email' => 'maria@example.org', 'code' => $code]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_sign_up_sends_a_code_and_issues_no_session(): void
    {
        $this->signUp()->assertCreated()
            ->assertJson(['verification_required' => true, 'code_sent' => true, 'minutes' => 15, 'resend_in' => 60])
            ->assertJsonMissingPath('token');

        Mail::assertSent(VisitorVerificationCodeMail::class, fn ($m) => $m->hasTo('maria@example.org')
            && preg_match('/^\d{6}$/', $m->code));

        $visitor = Visitor::firstWhere('email', 'maria@example.org');
        $this->assertNull($visitor->api_token);
        $this->assertNull($visitor->email_verified_at);
    }

    public function test_the_right_code_verifies_and_signs_in_once(): void
    {
        $this->signUp();
        $code = $this->lastCode();

        $token = $this->verify($code)->assertOk()->json('token');
        $this->assertNotNull(Visitor::firstWhere('email', 'maria@example.org')->email_verified_at);
        $this->getJson('/api/v1/visitors/me', ['Authorization' => "Bearer $token"])->assertOk();

        // Spent.
        $this->verify($code)->assertStatus(422)->assertJson(['error' => 'code_invalid']);
    }

    public function test_signing_in_before_verifying_resends_and_withholds_the_session(): void
    {
        $this->signUp();
        Carbon::setTestNow(now()->addMinutes(2));   // past the resend cooldown

        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJson(['error' => 'email_unverified', 'code_sent' => true])
            ->assertJsonMissingPath('token');
        Mail::assertSentCount(2);

        // A wrong password learns nothing, and sends nothing.
        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'Not-the-password-9'])
            ->assertStatus(401)->assertJson(['error' => 'invalid_credentials']);
        Mail::assertSentCount(2);

        $this->verify($this->lastCode())->assertOk();
        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_five_wrong_codes_void_it(): void
    {
        $this->signUp();
        $code = $this->lastCode();

        for ($i = 0; $i < VisitorEmailVerification::MAX_ATTEMPTS - 1; $i++) {
            $this->verify($this->wrong($code))->assertStatus(422)->assertJson(['error' => 'code_invalid']);
        }
        $this->verify($this->wrong($code))->assertStatus(422)->assertJson(['error' => 'code_locked']);

        $this->verify($code)->assertStatus(422)->assertJson(['error' => 'code_invalid']);
    }

    public function test_a_code_runs_out_after_fifteen_minutes(): void
    {
        $this->signUp();
        $code = $this->lastCode();

        Carbon::setTestNow(now()->addMinutes(16));
        $this->verify($code)->assertStatus(422)->assertJson(['error' => 'code_expired']);
    }

    public function test_resend_waits_out_the_cooldown_and_voids_the_old_code(): void
    {
        $this->signUp();
        $first = $this->lastCode();

        $this->postJson('/api/v1/visitors/verify-email/resend', ['email' => 'maria@example.org'])
            ->assertOk()->assertJson(['code_sent' => false]);
        Mail::assertSentCount(1);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/visitors/verify-email/resend', ['email' => 'maria@example.org'])
            ->assertOk()->assertJson(['code_sent' => true, 'resend_in' => 60]);
        Mail::assertSentCount(2);

        $second = $this->lastCode();
        if ($first !== $second) {
            $this->verify($first)->assertStatus(422);
        }
        $this->verify($second)->assertOk();
    }

    public function test_resend_says_the_same_for_an_unknown_or_verified_email(): void
    {
        Visitor::factory()->create(['email' => 'done@example.org']);

        foreach (['nobody@example.org', 'done@example.org'] as $email) {
            $this->postJson('/api/v1/visitors/verify-email/resend', ['email' => $email])
                ->assertOk()->assertJson(['verification_required' => true, 'code_sent' => true]);
        }
        Mail::assertNothingSent();
    }

    public function test_an_already_verified_account_cannot_be_signed_in_by_code(): void
    {
        Visitor::factory()->create(['email' => 'maria@example.org']);

        $this->verify('123456')->assertStatus(422)->assertJson(['error' => 'code_invalid']);
    }

    public function test_a_mail_failure_leaves_no_code_and_no_cooldown(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->signUp()->assertCreated()->assertJson(['code_sent' => false, 'resend_in' => 0]);
        $this->assertDatabaseCount('visitor_email_verifications', 0);
    }

    public function test_a_password_reset_also_proves_the_inbox(): void
    {
        $visitor = Visitor::factory()->unverified()->create(['email' => 'maria@example.org']);

        $this->postJson('/api/v1/visitors/password/forgot', ['email' => 'maria@example.org'])->assertOk();
        $code  = Mail::sent(VisitorPasswordCodeMail::class)->last()->code;
        $token = $this->postJson('/api/v1/visitors/password/verify', ['email' => 'maria@example.org', 'code' => $code])
            ->json('reset_token');

        $this->postJson('/api/v1/visitors/password/reset', [
            'email' => 'maria@example.org', 'reset_token' => $token,
            'password' => 'Kalabaw-tuwid-9-bakod', 'password_confirmation' => 'Kalabaw-tuwid-9-bakod',
        ])->assertOk();

        $this->assertNotNull($visitor->fresh()->email_verified_at);
    }
}
