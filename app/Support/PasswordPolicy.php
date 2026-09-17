<?php

namespace App\Support;

use App\Rules\NotCommonPassword;
use App\Rules\NotPersonalInfo;
use Illuminate\Validation\Rules\Password;

/**
 * SECURITY: one definition of what counts as an acceptable staff password.
 *
 * Three screens set a password — Tourism creating an account, Tourism
 * resetting one, and a staff member changing their own — and before this
 * class existed the rules were pasted into two of them and absent from the
 * third. Keeping them in one place is the only way the third screen cannot
 * quietly be the weak one.
 */
class PasswordPolicy
{
    /**
     * Length carries this, not the character classes.
     *
     * The old rule was eight characters with an uppercase, a digit and a
     * symbol, which `Museo@26` satisfies. Twelve is the point where the
     * character classes stop being the thing standing between an account and
     * a cracker, and it costs the staff member nothing — they type it once
     * and let the browser remember it.
     */
    public const MIN_LENGTH = 12;

    /**
     * The rule array for a password field.
     *
     * @param  array<int, string|null>  $identifiers  name, email — whatever
     *         this account is known by, so the password cannot be built from it.
     * @return array<int, mixed>
     */
    public static function rules(array $identifiers = []): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min(self::MIN_LENGTH)
                ->mixedCase()
                ->numbers()
                ->symbols()
                // Checks HaveIBeenPwned by k-anonymity: only the first five
                // characters of the SHA-1 hash leave the building, never the
                // password. Laravel fails this open on a network error, which
                // is why NotCommonPassword sits behind it.
                ->uncompromised(),
            new NotCommonPassword,
            new NotPersonalInfo($identifiers),
        ];
    }

    /** Wording for the rules above, shown under every password field. */
    public static function hint(): string
    {
        return 'At least ' . self::MIN_LENGTH . ' characters with upper and lower case, a number, and a symbol. It cannot contain your name or the museum’s name, and it cannot be a password that has appeared in a known breach.';
    }

    /**
     * A password for Tourism to hand over, generated rather than invented.
     *
     * Whatever a person types into a "new staff" form is a password they can
     * remember, which is the same as saying it is a password worth guessing —
     * and the staff member inherits it. Generating it means the handover
     * credential is strong by construction and obviously temporary, and the
     * `must_change_password` flag means it survives exactly one sign-in.
     *
     * Grouped in fours because it gets read aloud or written on a slip of
     * paper, and the ambiguous glyphs are gone for the same reason: nobody
     * should be locked out arguing about a 1 against an l.
     */
    public static function generateTemporary(): string
    {
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // no I, no O
        $lower   = 'abcdefghijkmnpqrstuvwxyz';   // no l, no o
        $digits  = '23456789';                   // no 0, no 1
        $symbols = '@#$%*?';

        $alphabet = $upper . $lower . $digits . $symbols;

        // One of each class up front guarantees the generated password passes
        // the same rules everyone else's has to, then the rest is filled at
        // random and the whole thing shuffled so the classes are not in a
        // predictable position.
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($chars); $i < 16; $i++) {
            $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('-', str_split(implode('', $chars), 4));
    }
}
