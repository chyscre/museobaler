<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResendVerificationRequest;
use App\Http\Requests\Api\VerifyEmailRequest;
use App\Models\Visitor;
use App\Models\VisitorEmailVerification;
use App\Support\VisitorVerification;
use Illuminate\Http\JsonResponse;

/**
 * The 6-digit code that proves a new visitor owns their email.
 *
 * Sign-up with a password creates the account unverified and emails a code
 * (VisitorController::register); signing in to an account that never
 * finished does the same. Until the code is typed back no session token is
 * issued, so nothing behind visitor.auth - the admission status, the
 * museum itself - can be reached.
 *
 * A Google sign-in never comes here: Google has already checked the
 * address. See GoogleAuthController.
 */
class EmailVerificationController extends Controller
{
    /**
     * POST /api/v1/visitors/verify-email - email and code in; on a right
     * code the account is verified and signed in, the same answer as a
     * sign-in. A wrong code and an email with nothing pending are the same
     * answer. Five wrong codes void the code.
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $visitor = Visitor::with('group')->where('email', $request->input('email'))->first();
        $pending = $visitor && !$visitor->hasVerifiedEmail()
            ? VisitorEmailVerification::where('visitor_id', $visitor->visitor_id)->first()
            : null;

        if ($pending === null) {
            return response()->json(['error' => 'code_invalid'], 422);
        }

        if ($pending->isExpired()) {
            $pending->delete();
            return response()->json(['error' => 'code_expired'], 422);
        }

        if (!$pending->redeem($request->input('code'))) {
            return response()->json([
                'error' => $pending->exists ? 'code_invalid' : 'code_locked',
            ], 422);
        }

        $visitor->markEmailVerified();
        // Signing up and verifying on the same day changes nothing here; a
        // sign-up abandoned yesterday and finished today is a new visit.
        $visitor->touchReturning();
        $token = $visitor->issueToken();

        return response()->json($visitor->clearancePayload() + [
            'returning'  => !$visitor->created_at?->isToday(),
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
        ]);
    }

    /**
     * POST /api/v1/visitors/verify-email/resend - "Resend Code".
     *
     * The answer has the same shape whether or not the email has an account
     * waiting on a code, so this is no way to find out which addresses are
     * registered. A press inside the cooldown sends nothing and says how
     * long is left.
     */
    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $visitor = Visitor::where('email', $request->input('email'))->first();

        if ($visitor === null || $visitor->hasVerifiedEmail() || !$visitor->password) {
            return response()->json([
                'verification_required' => true,
                'email'                 => (string) $request->input('email'),
                'minutes'               => VisitorEmailVerification::TTL_MINUTES,
                'code_sent'             => true,
                'resend_in'             => VisitorEmailVerification::RESEND_SECONDS,
            ]);
        }

        $result = VisitorVerification::send($visitor);

        if (!$result['code_sent'] && $result['resend_in'] === 0) {
            return response()->json([
                'error'   => 'mail_unavailable',
                'message' => 'We could not send the email just now. Please try again in a few minutes, or ask at the front desk.',
            ] + $result, 503);
        }

        return response()->json($result);
    }
}
