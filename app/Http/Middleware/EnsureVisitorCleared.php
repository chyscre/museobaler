<?php

namespace App\Http\Middleware;

use App\Models\Visitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: the admission gate.
 *
 * Museum content is only released once the front desk has cleared the
 * visitor: locals must have had a residency ID sighted, everyone else must
 * have paid the admission fee. Enforcing that in the app alone would be
 * theatre - the app runs on the visitor's own phone, where localStorage can
 * be edited freely. So the check lives here, in front of the endpoints that
 * actually serve exhibits, and the app's waiting screen is just a friendly
 * presentation of the same answer.
 *
 * Runs after AuthenticateVisitor. The 403 deliberately reports which step is
 * outstanding - the visitor is standing at the desk and needs to know
 * whether to pay or to show an ID. It reveals nothing an attacker could use,
 * since a token is required to get this far.
 *
 * This is also what unlocks an exhibit after a scan: clearance counts only
 * for today (Visitor::clearance() wants paid_at / verified_at to be today),
 * so yesterday's payment opens nothing. There is deliberately no location
 * check per scan. Indoor fixes in the stone building are vaguer than the
 * fence (see GeofenceService::visitorCheck()), and scans made with no signal
 * are queued on the phone and sent later, when a fix would be meaningless.
 * The known gap: a visitor cleared in the morning keeps access until
 * midnight. tests/Feature/Api/ScanLockTest.php pins the behaviour.
 */
class EnsureVisitorCleared
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Visitor $visitor */
        $visitor = $request->user('visitor');
        $state   = $visitor->clearance();

        if ($state !== 'cleared') {
            return response()->json([
                'error'     => 'not_cleared',
                'clearance' => $state,
                'message'   => match ($state) {
                    'pending_payment' => 'Please pay the admission fee at the entrance counter. A staff member will confirm it.',
                    'pending_group'   => 'Your group\'s admission has not been recorded yet. Once the person who signed you in pays at the counter, this unlocks by itself.',
                    default           => 'Please present your residency ID at the entrance desk. A staff member will verify it.',
                },
                'visitor'   => $visitor->clearancePayload(),
            ], 403);
        }

        return $next($request);
    }
}
