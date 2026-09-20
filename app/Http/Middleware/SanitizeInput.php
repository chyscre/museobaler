<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * SECURITY: SanitizeInput Middleware
 *
 * Strips HTML tags and control characters out of every string the admin
 * panel receives, before validation sees it.
 *
 * Blade already escapes on output, and that is the real XSS defence - this
 * is the second layer. It matters for the places output escaping does not
 * reach: the CSV exports Tourism opens in Excel, the audit log a future
 * screen might render differently, and the raw-PHP visitor API that reads
 * the same rows back out to phones. A name saved as "<script>" should never
 * have been a name.
 *
 * Deliberately narrower than strip_tags(): that function treats any "<"
 * as the start of a tag and eats everything after it, so "ages <12 free"
 * would be saved as "ages ". Only something shaped like a real tag -
 * "<" followed by a letter or "/", then ">" - is removed here, so ordinary
 * text with angle brackets survives.
 *
 * Passwords are left alone. A password is never rendered anywhere, and
 * altering it silently would lock the person out of the account they just
 * set the password on.
 */
class SanitizeInput extends TransformsRequest
{
    /** Keys whose values are never modified. */
    protected array $except = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        '_token',
    ];

    protected function transform($key, $value)
    {
        if (!is_string($value) || in_array($key, $this->except, true)) {
            return $value;
        }

        return self::scrub($value);
    }

    /**
     * The cleaning itself, exposed so tests and the visitor API's mirror of
     * this rule can share one definition.
     */
    public static function scrub(string $value): string
    {
        // Real tags only: "<b>", "</b>", "<img src=x onerror=…>", comments.
        $value = preg_replace('/<!--.*?-->/s', '', $value);
        $value = preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $value);

        // Null bytes and C0 control characters, keeping tab, newline and CR
        // for multi-line descriptions. A stray \0 in a name is only ever an
        // injection attempt against something further down the line.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }
}
