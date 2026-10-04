<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The six-digit code a visitor asked for on the Forgot Password screen.
 * Plain text: it is read on the phone the code gets typed into, and a
 * template would only push the number further down the screen.
 */
class VisitorPasswordCodeMail extends Mailable
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function build(): static
    {
        return $this
            ->subject($this->code . ' is your ' . config('app.name') . ' code')
            ->text('mail.visitor-password-code');
    }
}
