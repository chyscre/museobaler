<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SECURITY: the offline half of the common-password check.
 *
 * PasswordPolicy's main defence is HaveIBeenPwned, which knows about hundreds
 * of millions of leaked passwords. But it is a network call, and it is built
 * to fail open — if the museum's connection is down, Laravel treats every
 * password as clean rather than locking the Tourism office out of creating an
 * account. That is the right trade, and this rule is what stands behind it on
 * those days.
 *
 * The comparison strips what people add to get past complexity rules, so
 * `Password@123`, `p@ssw0rd!` and `Museo2026!` all reduce to entries in the
 * list rather than sliding by as novel strings.
 */
class NotCommonPassword implements ValidationRule
{
    /** @var array<int, string>|null Parsed once per request, not per field. */
    private static ?array $list = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        if (in_array(self::reduce($value), self::list(), true)) {
            $fail('That password is too common and is refused. Pick something that is not a dictionary word with numbers on the end.');
        }
    }

    /**
     * Reduces a password to the word underneath it.
     *
     * Order matters here, and getting it wrong is what makes this useless.
     * The padding on the ends comes off first, because folding leetspeak
     * before trimming turns `P@ssw0rd!2345` into `passwordeas` — a string
     * that is in no list anywhere, which is exactly the password that should
     * have been caught. Trim first and it is plain `password`.
     *
     *   P@ssw0rd!2345  ->  p@ssw0rd  ->  password
     *   Password@123   ->  password  ->  password
     *   Qwerty123      ->  qwerty    ->  qwerty
     *
     * A password of only digits and symbols reduces to an empty string and
     * matches nothing here — the length and complexity rules deal with it.
     */
    private static function reduce(string $password): string
    {
        $value = mb_strtolower($password);

        // The bolted-on ends: `123`, `!`, `@2026`, and any run of them.
        $value = preg_replace('/^[^a-z@$017345]+|[^a-z]+$/', '', $value) ?? '';

        $folded = strtr($value, [
            '@' => 'a', '4' => 'a',
            '3' => 'e',
            '1' => 'i',
            '0' => 'o',
            '$' => 's', '5' => 's',
            '7' => 't',
        ]);

        return preg_replace('/[^a-z]/', '', $folded) ?? '';
    }

    /** @return array<int, string> */
    private static function list(): array
    {
        if (self::$list !== null) {
            return self::$list;
        }

        $path = resource_path('security/common-passwords.txt');

        if (!is_readable($path)) {
            return self::$list = [];
        }

        $entries = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // The list is stored as readable words, so it goes through the
            // same reduction the candidate does — otherwise `p@ssword` in the
            // file would reduce to nonsense and never match anything.
            $entries[] = self::reduce($line);
        }

        return self::$list = array_values(array_unique(array_filter($entries)));
    }
}
