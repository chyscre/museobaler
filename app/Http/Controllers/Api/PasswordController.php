<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Requests\Api\VerifyResetCodeRequest;
use App\Mail\VisitorPasswordChangedMail;
use App\Mail\VisitorPasswordCodeMail;
use App\Models\Visitor;
use App\Models\VisitorPasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A visitor's password: forgotten, and changed.
 *
 * Forgotten is three calls, one per screen in the app, and nobody at the
 * museum is involved in any of them:
 *
 *   forgot   email in; a six-digit code goes to that inbox, good for
 *            fifteen minutes.
 *   verify   email and code in; a reset token out. The code is spent.
 *   reset    email, token and the new password in; the password is changed
 *            and every session on the account is ended.
 *
 * Owning the inbox is the whole proof, so an account the desk made without
 * a password can be given one this way too.
 *
 * Mail is sent in the request, not queued: this install runs no queue
 * worker (see docs/HOSTING.md), and a queued code would never leave.
 */
class PasswordController extends Controller
{
    /**
     * POST /api/v1/visitors/password/forgot.
     *
     * The answer is the same whether or not the email has an account. Sign-up
     * does say "already registered", so this is not the only place an address
     * could be tested - but it is no reason to add another. Throttled per
     * address and per email in AppServiceProvider.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $visitor = Visitor::where('email', $request->input('email'))->first();

        if ($visitor !== null) {
            $code = VisitorPasswordReset::issueFor($visitor);

            try {
                Mail::to($visitor->email)->send(new VisitorPasswordCodeMail(
                    (string) $visitor->first_name, $code, VisitorPasswordReset::TTL_MINUTES,
                ));
            } catch (Throwable $e) {
                // A code nobody received must not sit there waiting to be
                // guessed, and the visitor must hear that nothing was sent
                // rather than wait on an inbox that will stay empty.
                report($e);
                VisitorPasswordReset::where('visitor_id', $visitor->visitor_id)->delete();

                return response()->json([
                    'error'   => 'mail_unavailable',
                    'message' => 'We could not send the email just now. Please try again in a few minutes, or ask at the front desk.',
                ], 503);
            }
        }

        return response()->json([
            'ok'      => true,
            'minutes' => VisitorPasswordReset::TTL_MINUTES,
            'message' => 'If that email has an account, a 6-digit code is on its way.',
        ]);
    }

    /**
     * POST /api/v1/visitors/password/verify.
     *
     * A wrong code and an email with no request are the same answer. Five
     * wrong codes void the request; see VisitorPasswordReset::redeem().
     */
    public function verify(VerifyResetCodeRequest $request): JsonResponse
    {
        $reset = $this->resetFor($request->input('email'));

        if ($reset === null) {
            return response()->json(['error' => 'code_invalid'], 422);
        }

        if ($reset->isExpired()) {
            $reset->delete();
            return response()->json(['error' => 'code_expired'], 422);
        }

        $token = $reset->redeem($request->input('code'));

        if ($token === null) {
            return response()->json([
                'error' => $reset->exists ? 'code_invalid' : 'code_locked',
            ], 422);
        }

        return response()->json(['ok' => true, 'reset_token' => $token]);
    }

    /** POST /api/v1/visitors/password/reset. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $reset = $this->resetFor($request->input('email'));

        if ($reset === null || !$reset->tokenMatches($request->input('reset_token'))) {
            return response()->json([
                'error'   => 'reset_expired',
                'message' => 'This reset has expired. Please ask for a new code.',
            ], 422);
        }

        $visitor = $reset->visitor;

        // Hashed by the model's cast. The session goes too: whoever made the
        // reset necessary may be the one holding it.
        $visitor->forceFill([
            'password'         => $request->input('password'),
            'api_token'        => null,
            'token_expires_at' => null,
        ])->save();

        // Typing the emailed code was proof of the inbox, the same proof the
        // sign-up code gives, so an account stuck at that screen is freed.
        $visitor->markEmailVerified();

        $reset->delete();
        $this->tellOwner($visitor);

        return response()->json(['ok' => true]);
    }

    /**
     * PUT /api/v1/visitors/me/password - from Settings, signed in.
     *
     * The current password is asked for even though the token already proves
     * who this is: a phone left unlocked on a café table should not be enough
     * to take the account for good. The session making the change stays
     * signed in. Throttled per visitor in AppServiceProvider.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        /** @var Visitor $visitor */
        $visitor = $request->user('visitor');

        if (!Hash::check($request->input('current_password'), (string) $visitor->password)) {
            return response()->json([
                'error'   => 'current_password_wrong',
                'field'   => 'current_password',
                'message' => 'Your current password is not correct.',
            ], 422);
        }

        $visitor->password = $request->input('password');
        $visitor->save();

        $this->tellOwner($visitor);

        return response()->json(['ok' => true]);
    }

    private function resetFor(string $email): ?VisitorPasswordReset
    {
        $visitor = Visitor::where('email', $email)->first();

        return $visitor
            ? VisitorPasswordReset::where('visitor_id', $visitor->visitor_id)->first()
            : null;
    }

    /** The change has happened either way; a notice that fails to send is logged, not fatal. */
    private function tellOwner(Visitor $visitor): void
    {
        if (!$visitor->email) return;

        try {
            Mail::to($visitor->email)->send(new VisitorPasswordChangedMail((string) $visitor->first_name));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
