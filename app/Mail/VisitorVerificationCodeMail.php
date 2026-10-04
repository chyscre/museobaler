<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The six-digit code that confirms a new visitor's email address. Plain
 * text for the same reason as VisitorPasswordCodeMail: it is read on the
 * phone the code is typed into.
 */
class VisitorVerificationCodeMail extends Mailable
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function build(): static
    {
        return $this
            ->subject($this->code . ' is your ' . config('app.name') . ' verification code')
            ->text('mail.visitor-verification-code');
    }
}
