<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SECURITY: rejects a password built out of the account's own details.
 *
 * The complexity rules alone are easy to satisfy with exactly the password a
 * person would have picked anyway — `Maria@2026`, `Museo@123`, `Baler@1`. All
 * three pass "uppercase, digit, symbol" and all three are the first things
 * anyone standing in the building would try.
 *
 * So the account's own name and email are compared against the password, and
 * so are the handful of words that are written on the front of the building.
 */
class NotPersonalInfo implements ValidationRule
{
    /**
     * Words that are free to anyone who has seen the sign outside, the URL,
     * or the town they are standing in.
     */
    private const CONTEXT_WORDS = [
        'museo', 'baler', 'museobaler', 'museum', 'quezon', 'aurora',
        'tourism', 'admin', 'administrator', 'staff', 'password',
    ];

    /**
     * Shortest run of characters that counts as a match. Three is too short —
     * it would trip on common syllables inside perfectly good passwords.
     */
    private const MIN_MATCH = 4;

    /** @param array<int, string|null> $identifiers name, email, and anything else personal */
    public function __construct(private array $identifiers = [])
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $password = mb_strtolower($value);

        foreach ($this->candidates() as $candidate) {
            if (mb_strlen($candidate) < self::MIN_MATCH) {
                continue;
            }

            if (str_contains($password, $candidate)) {
                $fail('Your password cannot contain your name, your email, or the museum’s name. Pick something unrelated to you or to Museo de Baler.');

                return;
            }
        }
    }

    /**
     * Every lowercase fragment the password is not allowed to contain.
     *
     * A full name is split as well as kept whole, because "Maria Santos"
     * becomes `maria2026!` far more often than it becomes `mariasantos2026!`.
     * An email is reduced to its local part and its domain's own name for the
     * same reason — `chynna@gmail.com` is a warning about `chynna`, not about
     * `gmail`, which is why the public mail hosts are dropped.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        $words = self::CONTEXT_WORDS;

        foreach ($this->identifiers as $identifier) {
            if (!is_string($identifier) || trim($identifier) === '') {
                continue;
            }

            $identifier = mb_strtolower(trim($identifier));

            if (str_contains($identifier, '@')) {
                [$local, $domain] = explode('@', $identifier, 2);
                $words[] = $local;
                $words = array_merge($words, preg_split('/[^a-z0-9]+/', $local) ?: []);

                // Only the domain's own label, and only when it is not a mail
                // provider everybody shares.
                $host = explode('.', $domain)[0] ?? '';
                if (!in_array($host, ['gmail', 'yahoo', 'outlook', 'hotmail', 'icloud', 'proton'], true)) {
                    $words[] = $host;
                }

                continue;
            }

            $words[] = str_replace(' ', '', $identifier);
            $words = array_merge($words, preg_split('/[^a-z0-9]+/', $identifier) ?: []);
        }

        return array_values(array_unique(array_filter($words)));
    }
}
