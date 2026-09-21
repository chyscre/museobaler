<?php

namespace App\Support;

/**
 * SECURITY: what counts as an acceptable visitor password.
 *
 * Visitor accounts hold a person's name, age, contact email and visit
 * history, so the account is worth protecting even though it is not an
 * admin login. The app mirrors these rules for instant feedback, but the
 * check that matters is this one, on the server.
 *
 * Deliberately NOT the staff policy (PasswordPolicy): no character-class
 * rules, no breach lookup. NIST SP 800-63B dropped "must contain a symbol"
 * because it makes passwords harder to remember without making them harder
 * to guess - "P@ssw0rd1" satisfies every such rule and is in every cracking
 * list - and a visitor is typing this at an entrance queue on a phone.
 * Length and a blocklist do the real work:
 *
 *  1. LENGTH - at least 8 characters, at most 100.
 *  2. NO COMMON PASSWORDS - the overwhelming majority of real compromises
 *     are credential stuffing and dictionary attacks, not brute force.
 *  3. NO PERSONAL INFORMATION - a password built from the visitor's own
 *     name or email is guessable by anyone who watched them register.
 *  4. Quietly: one character repeated, or one unbroken run ("12345678").
 *
 * Ported from public/api/_password_policy.php, rule for rule.
 */
class VisitorPasswordPolicy
{
    /**
     * Drawn from the leaked-credential lists that stuffing tools actually
     * use. Compared case-insensitively after stripping the decoration
     * people add to get a weak password past a meter, so "Password1!",
     * "password123" and "P@ssword" are all caught by the one entry.
     */
    public const COMMON_ROOTS = [
        'password', 'passwd', 'pass', 'welcome', 'admin', 'administrator',
        'letmein', 'qwerty', 'qwertyuiop', 'asdfgh', 'zxcvbn', 'abc', 'abcd',
        'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football',
        'baseball', 'master', 'shadow', 'superman', 'batman', 'trustno',
        'login', 'guest', 'test', 'testing', 'demo', 'sample', 'default',
        'changeme', 'secret', 'access', 'freedom', 'whatever', 'starwars',
        'computer', 'internet', 'samsung', 'google', 'facebook', 'gmail',
        'birthday', 'january', 'february', 'december', 'summer', 'winter',
        // Locally predictable choices for this particular museum
        'museo', 'museum', 'baler', 'aurora', 'philippines', 'pilipinas',
        'museobaler', 'quezon', 'sabang', 'ditumabo', 'visitor', 'tourist',
    ];

    /**
     * An error code, or null when the password is acceptable.
     *
     * @param  string[]  $personal  values it must not contain - first name,
     *                              last name, the local part of the email.
     */
    public static function check(string $password, array $personal = []): ?string
    {
        // mb_strlen, so accented or non-Latin characters count as characters.
        if (mb_strlen($password) < 8)      return 'password_too_short';
        if (mb_strlen($password) > 100)    return 'password_too_long';
        if (trim($password) !== $password) return 'password_has_edge_spaces';

        $lower = mb_strtolower($password);

        $stripped = preg_replace('/[^a-z]/', '', strtr($lower, [
            '@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
            '0' => 'o', '$' => 's', '5' => 's', '7' => 't', '+' => 't',
        ]));

        foreach (self::COMMON_ROOTS as $root) {
            // Exact after stripping, or the root makes up most of it -
            // "mypassword2024" is still just "password".
            if ($stripped === $root) return 'password_too_common';
            if (mb_strlen($root) >= 4 && str_contains($stripped, $root)
                && mb_strlen($root) >= mb_strlen($stripped) * 0.6) {
                return 'password_too_common';
            }
        }

        // Fragments of 3 characters or fewer are ignored, otherwise a
        // surname like "Uy" would ban every password containing those letters.
        foreach ($personal as $value) {
            $value = mb_strtolower(trim((string) $value));
            if (mb_strlen($value) < 4) continue;
            if (str_contains($lower, $value))         return 'password_has_personal_info';
            if (str_contains($lower, strrev($value))) return 'password_has_personal_info';
        }

        if (preg_match('/^(.)\1+$/u', $password)) return 'password_too_simple';
        if (self::isWholeSequence($lower))       return 'password_too_simple';

        return null;
    }

    /** True when the whole value is one run of consecutive characters, either way. */
    private static function isWholeSequence(string $value): bool
    {
        $len = strlen($value);
        if ($len < 2) return false;

        $step = ord($value[1]) - ord($value[0]);
        if ($step !== 1 && $step !== -1) return false;

        for ($i = 2; $i < $len; $i++) {
            if (ord($value[$i]) - ord($value[$i - 1]) !== $step) return false;
        }

        return true;
    }

    /** Wording for each code. The app localises from the code; this is for everyone else. */
    public static function message(string $code): string
    {
        return [
            'password_too_short'         => 'Password must be at least 8 characters.',
            'password_too_long'          => 'Password must be 100 characters or fewer.',
            'password_has_edge_spaces'   => 'Password cannot start or end with a space.',
            'password_too_common'        => 'That password is too common — please choose something less predictable.',
            'password_has_personal_info' => 'Password cannot contain your name or email address.',
            'password_too_simple'        => 'That password is too easy to guess — please choose something less predictable.',
            'password_mismatch'          => 'The two passwords do not match.',
        ][$code] ?? 'Please choose a stronger password.';
    }
}
