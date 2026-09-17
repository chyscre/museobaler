<?php
/**
 * SECURITY: Visitor Password Policy
 *
 * Visitor accounts hold a person's name, age, contact email and visit history,
 * so the account is worth protecting even though it is not an admin login.
 * This file is the single authority on what counts as an acceptable password —
 * the app mirrors these rules in the UI for instant feedback, but the check
 * that matters is this one, on the server, where a crafted request cannot
 * skip it.
 *
 * The rules deliberately go beyond "8 characters with a symbol":
 *
 *  1. LENGTH + CHARACTER CLASSES — raises the cost of an offline brute force
 *     against the stolen hash. Mirrors the staff policy (upper/digit/symbol)
 *     and adds a lowercase requirement so "PASSWORD1!" does not pass.
 *
 *  2. NO COMMON PASSWORDS — the overwhelming majority of real account
 *     compromises come from credential stuffing and dictionary attacks, not
 *     from brute force. A password that appears in every leaked-password list
 *     is broken the moment it is chosen, however many character classes it
 *     satisfies. "P@ssw0rd1" passes every complexity rule and is worthless.
 *
 *  3. NO PERSONAL INFORMATION — a password built from the visitor's own name
 *     or email address is guessable by anyone who can see the registration
 *     form over their shoulder, and it is the first thing an attacker tries
 *     because those values are printed on the visitor's own record.
 *
 *  4. NO TRIVIAL SEQUENCES OR REPEATS — "Abcd1234!" and "Aaaa1111!" clear the
 *     character-class rules while carrying almost no entropy.
 */

/**
 * Passwords that must never be accepted, regardless of complexity rules.
 *
 * Drawn from the leaked-credential lists that credential-stuffing tools
 * actually use. Comparison is case-insensitive and ignores trailing digits and
 * symbols, so "Password1!", "password123" and "P@ssword" are all caught by the
 * single "password" entry.
 */
const COMMON_PASSWORD_ROOTS = [
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
 * Validate a proposed password.
 *
 * @param string   $password  The raw password as typed.
 * @param string[] $personal  Personal values it must not contain — typically
 *                            the first name, last name and email local part.
 *
 * @return string|null  An error code for the client, or null when acceptable.
 */
function validatePasswordPolicy(string $password, array $personal = []): ?string
{
    // 1. LENGTH AND CHARACTER CLASSES
    // mb_strlen, not strlen, so a password of accented or non-Latin characters
    // is measured in characters rather than bytes.
    if (mb_strlen($password) < 8)                  return 'password_too_short';
    if (mb_strlen($password) > 100)                return 'password_too_long';
    if (!preg_match('/[a-z]/', $password))         return 'password_needs_lowercase';
    if (!preg_match('/[A-Z]/', $password))         return 'password_needs_uppercase';
    if (!preg_match('/[0-9]/', $password))         return 'password_needs_number';
    if (!preg_match('/[^a-zA-Z0-9]/', $password))  return 'password_needs_symbol';

    // Whitespace-only padding is not complexity.
    if (trim($password) !== $password)             return 'password_has_edge_spaces';

    $lower = mb_strtolower($password);

    // 2. COMMON PASSWORDS
    // Strip the decoration people add to get a weak password past a complexity
    // meter — leading/trailing digits and symbols, and the usual letter/digit
    // substitutions — then test what is left against the blocklist.
    $stripped = preg_replace('/[^a-z]/', '', strtr($lower, [
        '@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
        '0' => 'o', '$' => 's', '5' => 's', '7' => 't', '+' => 't',
    ]));

    foreach (COMMON_PASSWORD_ROOTS as $root) {
        // Exact match after stripping, or the root makes up most of the
        // password ("mypassword2024" is still just "password").
        if ($stripped === $root) return 'password_too_common';
        if (mb_strlen($root) >= 4 && str_contains($stripped, $root)
            && mb_strlen($root) >= mb_strlen($stripped) * 0.6) {
            return 'password_too_common';
        }
    }

    // 3. PERSONAL INFORMATION
    // Reject anything built from the visitor's own name or email. Fragments of
    // 3 characters or fewer are ignored, otherwise a surname like "Uy" would
    // ban every password containing those two letters.
    foreach ($personal as $value) {
        $value = mb_strtolower(trim((string) $value));
        if (mb_strlen($value) < 4) continue;
        if (str_contains($lower, $value))            return 'password_has_personal_info';
        // Also catch the reversed spelling, a common "clever" variation.
        if (str_contains($lower, strrev($value)))    return 'password_has_personal_info';
    }

    // 4. TRIVIAL SEQUENCES AND REPEATS
    // A single repeated character ("aaaaaaaa") or a run of four or more
    // consecutive keyboard/alphabet/number characters ("abcd", "1234").
    if (preg_match('/^(.)\1+$/u', $password))        return 'password_too_simple';
    if (hasRunOfFour($lower))                        return 'password_too_simple';

    return null;
}

/**
 * True when the value contains four or more consecutive characters running
 * forwards or backwards ("abcd", "4321", "wxyz").
 */
function hasRunOfFour(string $value): bool
{
    $len = strlen($value);
    if ($len < 4) return false;

    $ascending = 1;
    $descending = 1;
    for ($i = 1; $i < $len; $i++) {
        $delta = ord($value[$i]) - ord($value[$i - 1]);
        $ascending  = $delta === 1  ? $ascending + 1  : 1;
        $descending = $delta === -1 ? $descending + 1 : 1;
        if ($ascending >= 4 || $descending >= 4) return true;
    }

    return false;
}

/**
 * Human-readable text for each policy error code. The API returns codes so the
 * app can localise them, but the message is included for any client that just
 * wants to display something.
 */
function passwordPolicyMessage(string $code): string
{
    return [
        'password_too_short'         => 'Password must be at least 8 characters.',
        'password_too_long'          => 'Password must be 100 characters or fewer.',
        'password_needs_lowercase'   => 'Password must include a lowercase letter.',
        'password_needs_uppercase'   => 'Password must include an uppercase letter.',
        'password_needs_number'      => 'Password must include a number.',
        'password_needs_symbol'      => 'Password must include a symbol.',
        'password_has_edge_spaces'   => 'Password cannot start or end with a space.',
        'password_too_common'        => 'That password is too common — please choose something less predictable.',
        'password_has_personal_info' => 'Password cannot contain your name or email address.',
        'password_too_simple'        => 'Password cannot be a simple sequence or a repeated character.',
        'password_mismatch'          => 'The two passwords do not match.',
    ][$code] ?? 'Please choose a stronger password.';
}
