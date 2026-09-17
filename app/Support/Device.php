<?php

namespace App\Support;

/**
 * Is this request coming from a phone?
 *
 * Two places need the same answer and must not disagree: DesktopOnly, which
 * narrows what a phone session may reach, and the attendance screen, which
 * picks its layout. If those two drifted apart you would get the panel chrome
 * wrapped around the one page the panel is not allowed to show.
 *
 * NOTE: a user-agent string is trivially forged. Nothing here is a security
 * decision - it only decides what to render and where to send someone.
 * EnsureRole is what actually keeps people out of things.
 */
class Device
{
    /**
     * Phones only. Tablets deliberately count as desktop.
     *
     * The front desk register is meant to run on a counter tablet, so folding
     * tablets in with phones would break the busiest screen in the building.
     * Android says "Mobile" only on phones; an iPad is either "iPad" or, on
     * recent iPadOS, indistinguishable from a Mac - which lands on desktop,
     * and that is the harmless way to be wrong.
     */
    public static function isPhone(?string $agent): bool
    {
        $agent = $agent ?? '';

        if ($agent === '') {
            return false;
        }

        if (preg_match('/\b(iPad|Tablet|Silk|PlayBook)\b/i', $agent)) {
            return false;
        }

        if (stripos($agent, 'Android') !== false) {
            return stripos($agent, 'Mobile') !== false;
        }

        return (bool) preg_match('/\b(iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile)\b/i', $agent);
    }
}
