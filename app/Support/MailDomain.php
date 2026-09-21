<?php

namespace App\Support;

use Closure;

/**
 * Does an email address's domain actually receive mail?
 *
 * A shape check alone lets "asdf@asdf.com" through. An MX lookup on the
 * domain catches that kind of junk without making the visitor wait for a
 * verification email at the entrance desk - a real cost for a school party
 * of forty, for very little gain, since the fee and the ID are checked
 * face to face anyway. This does NOT prove the mailbox exists or that the
 * visitor owns it; it proves the domain is real.
 *
 * Fails OPEN. If DNS itself is unreachable (the museum's connection drops)
 * every domain would look dead and nobody could register at all, so a
 * second lookup against a domain that certainly has mail servers tells
 * "this domain is bogus" apart from "we cannot resolve anything right now".
 */
class MailDomain
{
    /** @var (Closure(string): bool)|null a resolver swapped in by tests */
    private static ?Closure $resolver = null;

    public static function acceptsMail(string $email): bool
    {
        $at = strrchr($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($at, 1));
        if ($domain === '') {
            return false;
        }

        if (self::resolves($domain)) {
            return true;
        }

        // Domain looked dead - but was it the domain, or is DNS down?
        return !self::resolves('gmail.com');
    }

    /** An A record alone is enough: RFC 5321 lets mail fall back to it. */
    private static function resolves(string $domain): bool
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($domain);
        }

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
    }

    /** Tests: decide which domains exist without touching the network. */
    public static function fakeResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }
}
