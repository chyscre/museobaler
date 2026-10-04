<?php

namespace App\Support;

use App\Mail\VisitorVerificationCodeMail;
use App\Models\Visitor;
use App\Models\VisitorEmailVerification;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sending the sign-up code, from the three places that need to: sign-up
 * itself, a sign-in on an account that never finished verifying, and the
 * "Resend Code" button.
 *
 * Mail is sent in the request, not queued, for the same reason as the
 * password-reset code: this install runs no queue worker.
 */
class VisitorVerification
{
    /**
     * Email a fresh code unless one went out inside the cooldown, and say
     * what the app should show: whether a code is on its way now, and how
     * long until "Resend Code" may be pressed.
     *
     * @return array{verification_required: true, email: string, minutes: int, code_sent: bool, resend_in: int}
     */
    public static function send(Visitor $visitor): array
    {
        $wait = VisitorEmailVerification::cooldownFor($visitor);
        $sent = false;

        if ($wait === 0) {
            $code = VisitorEmailVerification::issueFor($visitor);

            try {
                Mail::to($visitor->email)->send(new VisitorVerificationCodeMail(
                    (string) $visitor->first_name, $code, VisitorEmailVerification::TTL_MINUTES,
                ));
                $sent = true;
                $wait = VisitorEmailVerification::RESEND_SECONDS;
            } catch (Throwable $e) {
                // A code nobody received must not sit there waiting to be
                // guessed, and the cooldown must not hold back the retry.
                report($e);
                VisitorEmailVerification::where('visitor_id', $visitor->visitor_id)->delete();
            }
        }

        return self::payload($visitor, $sent, $wait);
    }

    /** @return array{verification_required: true, email: string, minutes: int, code_sent: bool, resend_in: int} */
    public static function payload(Visitor $visitor, bool $sent, int $resendIn): array
    {
        return [
            'verification_required' => true,
            'email'                 => (string) $visitor->email,
            'minutes'               => VisitorEmailVerification::TTL_MINUTES,
            'code_sent'             => $sent,
            'resend_in'             => $resendIn,
        ];
    }
}
