<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Sent after a visitor's password changes, by either route. The visitor who
 * did it can ignore it; the one who did not is the reason it exists.
 */
class VisitorPasswordChangedMail extends Mailable
{
    public function __construct(
        public readonly string $firstName,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('Your ' . config('app.name') . ' password was changed')
            ->text('mail.visitor-password-changed');
    }
}
