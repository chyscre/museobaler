<?php

namespace App\Support;

/**
 * Puts the AuditLog middleware's rows into words.
 *
 * The middleware records every change anyone makes in the panel. It used to
 * write them as the raw request - "POST visitors/221/mark-paid" with "HTTP
 * POST | Status: 302" beside it - which is accurate and means nothing to the
 * Tourism office reading the trail, or to anyone they hand the printout to.
 *
 * New rows are written through here, so they are stored in words. Rows
 * written before that are still in the old form; they are not rewritten (an
 * audit trail is not something to edit after the fact) but are put through
 * the same translation wherever they are shown or exported.
 */
class AuditTrail
{
    /** What each change is called, by request path. $1 is the record number. */
    private const ACTIONS = [
        '#^login$#'                                => 'Sign in',
        '#^logout$#'                               => 'Sign out',
        '#^my/password$#'                          => 'Change own password',
        '#^my/attendance/scan$#'                   => 'Check in or out',
        '#^my/attendance/pin$#'                    => 'Set the museum pin from a phone',
        '#^attendance/corrections$#'               => 'File an attendance correction',
        '#^attendance/corrections/(\d+)/review$#'  => 'Review attendance correction #$1',
        '#^desk/groups$#'                          => 'Register a group at the desk',
        '#^desk/groups/(\d+)/correct$#'            => 'Correct group #$1',
        '#^desk/groups/(\d+)/paid$#'               => 'Mark group #$1 as paid',
        '#^desk/visitors$#'                        => 'Register a visitor at the desk',
        '#^visitors/(\d+)/mark-paid$#'             => 'Mark visitor #$1 as paid',
        '#^visitors/(\d+)/verify-id$#'             => 'Verify the ID of visitor #$1',
        '#^exhibits$#'                             => 'Add an exhibit',
        '#^exhibits/ai/narrate$#'                  => 'Generate an exhibit narration',
        '#^exhibits/ai/translate$#'                => 'Generate exhibit translations',
        '#^exhibits/(\d+)$#'                       => 'Edit exhibit #$1',
        '#^exhibits/(\d+)/archive$#'               => 'Archive exhibit #$1',
        '#^exhibits/(\d+)/restore$#'               => 'Restore exhibit #$1',
        '#^exhibits/(\d+)/gallery$#'               => 'Add gallery pictures to exhibit #$1',
        '#^exhibits/(\d+)/translations$#'          => 'Add a translation to exhibit #$1',
        '#^gallery/(\d+)$#'                        => 'Delete gallery picture #$1',
        '#^map/positions$#'                        => 'Rearrange the floor map',
        '#^museum$#'                               => 'Update museum info',
        '#^recognition/background$#'               => 'Add background recognition photos',
        '#^recognition/background/remove$#'        => 'Remove background recognition photos',
        '#^recognition/model$#'                    => 'Save the recognition model',
        '#^recognition/(\d+)$#'                    => 'Add recognition photos for exhibit #$1',
        '#^recognition/(\d+)/remove$#'             => 'Remove recognition photos for exhibit #$1',
        '#^recognition/photo/(\d+)$#'              => 'Delete recognition photo #$1',
        '#^staff$#'                                => 'Create a staff account',
        '#^staff/(\d+)$#'                          => 'Edit staff account #$1',
        '#^staff/(\d+)/toggle$#'                   => 'Turn staff account #$1 on or off',
        '#^staff/(\d+)/reset-password$#'           => 'Issue a new password to staff account #$1',
        '#^staff-attendance/(\d+)/schedule$#'      => 'Set the work schedule of staff account #$1',
        '#^survey$#'                               => 'Edit the survey questions',
        '#^survey/restore$#'                       => 'Restore the default survey',
        '#^tours$#'                                => 'Start a guided tour',
        '#^tours/(\d+)/end$#'                      => 'End guided tour #$1',
    ];

    /** How the middleware wrote a row before this class existed. */
    private const LEGACY_DETAILS = '#^HTTP (?:POST|PUT|PATCH|DELETE) \| Status: (\d{3})$#';
    private const LEGACY_ACTION  = '#^(POST|PUT|PATCH|DELETE) (\S+)$#';

    /** The name of the change a request made. */
    public static function action(string $method, string $path): string
    {
        $path = trim($path, '/');

        // One path, two changes.
        if (preg_match('#^translations/(\d+)$#', $path, $m)) {
            return ($method === 'DELETE' ? 'Delete translation #' : 'Edit translation #') . $m[1];
        }

        foreach (self::ACTIONS as $pattern => $label) {
            if (preg_match($pattern, $path)) {
                return preg_replace($pattern, $label, $path);
            }
        }

        // A route added after this list: still readable, if plainer.
        return 'Change ' . str_replace(['/', '-'], [' / ', ' '], $path);
    }

    /**
     * What came of it. $refused is whether the request sent its form back
     * with errors: a redirect is what both a save and a refusal look like
     * from outside, so the status alone cannot tell them apart.
     */
    public static function outcome(int $status, bool $refused = false): string
    {
        return match (true) {
            $refused, $status === 422       => 'Not saved: some information was missing or invalid',
            $status === 419                 => 'Not saved: the page had been open too long',
            $status === 429                 => 'Refused: too many attempts in a row',
            in_array($status, [401, 403])   => 'Refused: not allowed for this account',
            $status >= 500                  => 'Failed: something went wrong on the server',
            $status >= 400                  => 'Refused',
            default                         => 'Done',
        };
    }

    /**
     * [action, details] as they should read, for a stored row in either the
     * current or the old form. Rows that controllers wrote themselves are
     * already in words and come back unchanged.
     */
    public static function describe(?string $action, ?string $details): array
    {
        if (!preg_match(self::LEGACY_DETAILS, (string) $details, $d)
            || !preg_match(self::LEGACY_ACTION, (string) $action, $a)) {
            return [$action, $details];
        }

        $status = (int) $d[1];

        // The old rows did not note whether a form came back with errors,
        // so a redirect only says it was sent. Sign-in is the exception: a
        // failed one leaves nobody signed in, and the middleware only
        // records signed-in requests.
        $outcome = $status >= 300 && $status < 400 && $a[2] !== 'login'
            ? 'Submitted'
            : self::outcome($status);

        return [self::action($a[1], $a[2]), $outcome];
    }

    /**
     * Headings for the Logs action filter, in the order the list shows them,
     * each with the words that put an action under it. First match wins, so
     * "Add recognition photos for exhibit" is Recognition, not Exhibits.
     */
    public const CATEGORIES = [
        'Recognition'          => ['recognition'],
        'Exhibits'             => ['exhibit', 'gallery'],
        'Groups'               => ['group'],
        'Visitors & admission' => ['visitor', 'admission', 'id verified'],
        'Staff attendance'     => ['check in', 'correction', 'attendance', 'schedule'],
        'Museum settings'      => ['museum', 'floor map', 'fee', 'discount'],
        'Accounts & sign-in'   => ['sign in', 'sign out', 'password', 'staff', 'account'],
    ];

    /** Display order: Exhibits leads, Other trails. */
    public const CATEGORY_ORDER = [
        'Exhibits', 'Recognition', 'Visitors & admission', 'Groups',
        'Staff attendance', 'Museum settings', 'Accounts & sign-in', 'Other',
    ];

    /** Which heading a kind of action sits under in the filter list. */
    public static function category(string $kind): string
    {
        $k = strtolower($kind);
        foreach (self::CATEGORIES as $category => $words) {
            foreach ($words as $w) {
                if (str_contains($k, $w)) {
                    return $category;
                }
            }
        }

        return 'Other';
    }

    /** The action without its record number, for grouping in a filter list. */
    public static function kind(?string $action): string
    {
        $name = preg_match(self::LEGACY_ACTION, (string) $action, $a)
            ? self::action($a[1], $a[2])
            : (string) $action;

        return preg_replace('/ #\d+/', '', $name);
    }
}
