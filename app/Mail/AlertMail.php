<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The plain-text email App\Support\Alerts sends. Text only: it is read on
 * a phone at two in the morning, and nothing about "the backup failed"
 * needs a template.
 */
class AlertMail extends Mailable
{
    public function __construct(
        public readonly string $alertSubject,
        public readonly string $body,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('[' . config('app.name') . '] ' . $this->alertSubject)
            ->text('mail.alert');
    }
}
