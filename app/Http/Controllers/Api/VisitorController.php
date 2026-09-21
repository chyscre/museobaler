<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinGroupRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterVisitorRequest;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\MailDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A visitor's account: sign-up, sign-in, sign-out, where they stand with
 * the desk, and joining a party.
 *
 * SECURITY: every answer that describes a visitor comes from the bearer
 * token, never from an id in the request. The old API took visitor_id
 * from the body once, and anyone could read or write anyone's record.
 */
class VisitorController extends Controller
{
    /**
     * POST /api/v1/visitors - register.
     *
     * Admission is resolved here from the visitor type. Local: free, but the
     * record starts with id_verified = 0 until staff sight a Baler ID.
     * Tourist and Foreign: the flat fee, collected at the counter. A group
     * code resolves before the insert, so a bad code costs a retype and not
     * a half-made account; a member owes nothing personally and takes the
     * visit type of the party.
     */
    public function register(RegisterVisitorRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Shape is not enough: the domain has to be one that can receive mail.
        if (!MailDomain::acceptsMail($data['email'])) {
            return response()->json([
                'error'   => 'email_domain_invalid',
                'field'   => 'email',
                'message' => 'That email address does not look real — please check the part after the @.',
            ], 422);
        }

        // SECURITY: this used to sign the caller straight in as the owner of
        // the matching email, which - now that accounts have passwords -
        // would let anyone take over an account by re-registering with its
        // address. A known email goes through login instead.
        if (Visitor::where('email', $data['email'])->exists()) {
            return response()->json([
                'error'   => 'email_taken',
                'field'   => 'email',
                'message' => 'That email is already registered. Tap Sign In instead.',
            ], 422);
        }

        $type = $data['visitor_type'];

        if ($type === 'Local') {
            $data['city']     = 'Baler';
            $data['province'] = 'Aurora';
            $data['country']  = 'Philippines';
        } else {
            $data['barangay'] = null;
        }

        $group = null;
        if (!empty($data['group_code'])) {
            [$group, $error] = $this->joinableGroup($data['group_code']);
            if ($error !== null) {
                return response()->json(['error' => $error, 'field' => 'group_code', 'message' => self::groupErrorMessage($error)], 422);
            }
        }

        $visitor = Visitor::create([
            'first_name'     => $data['first_name'],
            'last_name'      => $data['last_name'],
            'middle_name'    => $data['middle_name'] ?? null,
            'age'            => $data['age'] ?? null,
            'sex'            => $data['sex'] ?? 'Prefer not to say',
            'visit_type'     => $group ? $group->memberVisitType() : ($data['visit_type'] ?? 'Solo'),
            'visitor_type'   => $type,
            'country'        => $data['country'] ?? 'Philippines',
            'city'           => $data['city'] ?? null,
            'barangay'       => $data['barangay'] ?? null,
            'province'       => $data['province'] ?? null,
            'email'          => $data['email'],
            'password'       => $data['password'],
            'auth_provider'  => 'manual',
            'explore_mode'   => $data['explore_mode'] ?? 'Storyline',
            'admission_fee'  => $group || $type === 'Local' ? 0.00 : Visitor::feeFor($type),
            'payment_status' => $group || $type === 'Local' ? 'Free' : 'Unpaid',
            'group_id'       => $group?->group_id,
            'source'         => 'app',
            'id_verified'    => false,
            // Stamped now, or the first sign-in of the same day would look
            // like a new visit and put the fee back to Unpaid - re-locking a
            // visitor who had paid minutes earlier.
            'last_visit'     => now(),
        ]);

        $token = $visitor->issueToken();
        $visitor->load('group');

        return response()->json($visitor->clearancePayload() + [
            'returning'  => false,
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
        ], 201);
    }

    /**
     * POST /api/v1/visitors/login.
     *
     * SECURITY: an unknown email and a wrong password get the identical
     * answer, and the hash check runs either way, so neither the reply
     * nor a stopwatch says which addresses are registered. Throttled per
     * account and per address in AppServiceProvider.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $visitor = Visitor::with('group')->where('email', $request->input('email'))->first();

        if (!Visitor::passwordMatches($visitor, $request->input('password'))) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        if (Hash::needsRehash($visitor->password)) {
            $visitor->password = $request->input('password');
            $visitor->save();
        }

        // A new visit means the admission fee falls due again for paying types.
        $visitor->touchReturning();
        $token = $visitor->issueToken();

        return response()->json($visitor->clearancePayload() + [
            'returning'  => true,
            'found'      => true,
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
        ]);
    }

    /** POST /api/v1/visitors/logout - the token stops working at once. */
    public function logout(Request $request): JsonResponse
    {
        Visitor::findByToken($request->bearerToken())?->revokeToken();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/visitors/me - where they stand with the desk.
     *
     * The waiting screen polls this, so it flips to the museum the moment
     * staff records the payment or the ID check.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json($request->user('visitor')->clearancePayload());
    }

    /**
     * POST /api/v1/visitors/me/group - join a party after signing in.
     *
     * For the member who registered before the desk had signed the party in,
     * or a returning visitor who is with a group today. Somebody who already
     * paid on their own has nothing to gain and the museum has a fee to
     * refund if they switch, so that is a desk conversation, not a button.
     */
    public function joinGroup(JoinGroupRequest $request): JsonResponse
    {
        /** @var Visitor $visitor */
        $visitor = $request->user('visitor');

        if ($visitor->payment_status === 'Paid' && !$visitor->isWithGroupToday()) {
            return response()->json([
                'error'   => 'already_paid',
                'message' => 'You have already paid your own admission. Speak to the desk if you should have been part of a group.',
            ], 422);
        }

        [$group, $error] = $this->joinableGroup($request->input('group_code'));
        if ($error !== null) {
            return response()->json(['error' => $error, 'message' => self::groupErrorMessage($error)], 422);
        }

        $visitor->joinGroup($group);

        return response()->json($visitor->clearancePayload() + ['joined' => true]);
    }

    /**
     * SECURITY: a code that grants free entry needs limits. It only matches
     * a group whose visit_date is today, so yesterday's code admits nobody,
     * and it stops working once as many members have joined as the desk
     * counted and charged for, so a leaked code cannot let in a sixth
     * person on a party of five.
     *
     * @return array{0: VisitGroup|null, 1: string|null}
     */
    private function joinableGroup(string $code): array
    {
        $group = VisitGroup::joinable($code);

        if ($group === null) {
            return [null, 'group_not_found'];
        }
        if ($group->isFull()) {
            return [null, 'group_full'];
        }

        return [$group, null];
    }

    public static function groupErrorMessage(string $code): string
    {
        return match ($code) {
            'group_full' => 'Everyone in that group has already joined. Ask the person who signed you in to check the headcount at the desk.',
            default      => 'That group code was not found for today. Check it with the person who signed you in at the desk.',
        };
    }
}
