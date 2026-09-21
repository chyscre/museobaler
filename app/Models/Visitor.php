<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * A visitor: the account the museum app runs under.
 *
 * Authenticatable so the visitor API can treat one as the request's user
 * (guard `visitor`, resolved from a bearer token - see AppServiceProvider).
 * The admin panel never signs in as one; it only reads and clears them.
 */
class Visitor extends Authenticatable
{
    use HasFactory;

    protected $primaryKey = 'visitor_id';

    /**
     * Sessions last a day. Long enough to cover a museum visit without
     * forcing a re-login mid-tour, short enough that a token found on a
     * shared or lost handset stops working quickly.
     */
    public const TOKEN_TTL_SECONDS = 86400;

    protected $fillable = [
        'first_name', 'last_name', 'middle_name',
        'age', 'sex', 'visitor_type', 'visit_type',
        'city', 'barangay', 'province', 'country', 'email', 'password', 'auth_provider',
        'explore_mode',
        'group_id', 'source', 'registered_by',
        'admission_fee', 'payment_status', 'paid_at',
        'id_verified', 'verified_at', 'verified_by',
        'last_visit',
    ];

    /**
     * SECURITY: never serialise the credential columns. Nothing in the admin
     * panel should ever read them back, and this stops one leaking through
     * a JSON response or a debug dump.
     */
    protected $hidden = ['password', 'api_token', 'token_expires_at', 'remember_token'];

    protected function casts(): array
    {
        return [
            'last_visit'       => 'datetime',
            'paid_at'          => 'datetime',
            'verified_at'      => 'datetime',
            'token_expires_at' => 'datetime',
            'id_verified'      => 'boolean',
            'admission_fee'    => 'decimal:2',
            'password'         => 'hashed',
        ];
    }

    // -- Session tokens ------------------------------------------------------

    /**
     * Issue a fresh session token and store its hash.
     *
     * Only the SHA-256 is kept: a leaked database does not hand out working
     * sessions, the same reason passwords are hashed. The raw token is
     * returned once, for the client, and never exists here again.
     */
    public function issueToken(): array
    {
        // random_bytes() is a cryptographically secure source. rand() and
        // uniqid() are predictable and must never mint a session token.
        $raw     = bin2hex(random_bytes(32));
        $expires = now()->addSeconds(self::TOKEN_TTL_SECONDS);

        $this->forceFill([
            'api_token'        => hash('sha256', $raw),
            'token_expires_at' => $expires,
        ])->save();

        return ['token' => $raw, 'expires_at' => $expires->format('Y-m-d H:i:s')];
    }

    /** Clear the session so the token stops working immediately. */
    public function revokeToken(): void
    {
        $this->forceFill(['api_token' => null, 'token_expires_at' => null])->save();
    }

    /**
     * The visitor behind a raw token, or null when it is missing, unknown or
     * expired. A 64-character hex string is the only shape a real token has;
     * anything else never reaches the database.
     */
    public static function findByToken(?string $raw): ?self
    {
        if ($raw === null || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }

        return self::query()
            ->where('api_token', hash('sha256', $raw))
            ->where('token_expires_at', '>', now())
            ->with('group')
            ->first();
    }

    /**
     * Password check that costs the same for an unknown email: pass null
     * when no row was found and a dummy hash is verified instead, so a
     * stopwatch cannot tell "no such account" from "wrong password".
     */
    public static function passwordMatches(?self $visitor, string $password): bool
    {
        $hash = $visitor?->password ?: self::DUMMY_HASH;

        return Hash::check($password, $hash) && $visitor !== null;
    }

    /**
     * A real bcrypt hash of a value nobody has, verified against when the
     * email is unknown so the reply takes as long as a wrong password. It
     * has to be a well-formed hash: Laravel's hasher refuses to check
     * anything else, and a refusal would be the timing tell.
     */
    private const DUMMY_HASH = '$2y$12$QllDVUk4FI76Qqpa9zKklOVuVqZEvtALffRNxp5TsQLiykfieZL5u';

    // -- Admission -----------------------------------------------------------

    /**
     * Baler locals enter free (subject to showing a valid ID); everyone else
     * pays the flat fee set on the Museum Info page.
     */
    public static function feeFor(string $visitorType): float
    {
        return $visitorType === 'Local' ? 0.00 : MuseumInfo::admissionFee();
    }

    /**
     * Whether this visitor arrived as part of a group that is here today.
     *
     * group_id is the group of the most recent visit and is kept as history,
     * so it can point at last month's party. Only today's group has any
     * bearing on whether the door opens.
     */
    public function isWithGroupToday(): bool
    {
        return $this->group !== null && $this->group->visit_date->isToday();
    }

    /**
     * What stands between this visitor and the museum's content, right now.
     *
     * With a group today, the group's standing is theirs. On their own,
     * locals are admitted free but only once staff has sighted a residency
     * ID, and everyone else once the fee has been collected.
     *
     * One of: cleared | pending_id | pending_payment | pending_group.
     *
     * This is the door's answer for today and drives the app. isCleared()
     * below is the Records page's answer and also counts a member of a past
     * group as cleared - their admission was the party's, on the day the
     * party came. Both are right for what they are asked.
     */
    public function clearance(): string
    {
        if ($this->isWithGroupToday()) {
            return $this->group->clearsMembers() ? 'cleared' : 'pending_group';
        }

        if ($this->visitor_type === 'Local') {
            return $this->id_verified ? 'cleared' : 'pending_id';
        }

        return $this->payment_status === 'Paid' ? 'cleared' : 'pending_payment';
    }

    /**
     * The public shape of the admission state, safe to hand to the app.
     * Never includes the password or token hash.
     */
    public function clearancePayload(): array
    {
        $state = $this->clearance();

        return [
            'visitor_id'     => (int) $this->visitor_id,
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'email'          => $this->email,
            'age'            => $this->age,
            'sex'            => $this->sex,
            'visit_type'     => $this->visit_type,
            'visitor_type'   => $this->visitor_type,
            'city'           => $this->city,
            'barangay'       => $this->barangay,
            'province'       => $this->province,
            'country'        => $this->country,
            'admission_fee'  => (float) $this->admission_fee,
            'payment_status' => $this->payment_status,
            'id_verified'    => (bool) $this->id_verified,
            'explore_mode'   => $this->explore_mode,
            'clearance'      => $state,
            'cleared'        => $state === 'cleared',
            // Who they came with, so the app can say "you're with Maria's
            // group" and explain that the group's payment is what they wait on.
            'group'          => $this->isWithGroupToday() ? [
                'label'          => $this->group->label,
                'headcount'      => (int) $this->group->headcount,
                'payment_status' => $this->group->payment_status,
            ] : null,
        ];
    }

    /**
     * Mark a returning visitor as here again.
     *
     * Admission is charged per visit, so a paying visitor whose last visit
     * was on an earlier date owes the fee again and their payment status
     * resets to Unpaid. Locals stay Free, and their ID check carries over.
     */
    public function touchReturning(): void
    {
        $newDay = $this->last_visit === null || !$this->last_visit->isToday();

        if ($this->visitor_type !== 'Local' && $newDay) {
            $this->admission_fee  = MuseumInfo::admissionFee();
            $this->payment_status = 'Unpaid';
            $this->paid_at        = null;
        }

        $this->last_visit = now();
        $this->save();
    }

    /**
     * Become one of a party's headcount instead of a visitor owing a fee of
     * their own. Rejoining the same group is a no-op rather than a second seat.
     */
    public function joinGroup(VisitGroup $group): void
    {
        if ((int) $this->group_id === (int) $group->group_id && $this->isWithGroupToday()) {
            return;
        }

        $this->forceFill([
            'group_id'       => $group->group_id,
            'visit_type'     => $group->memberVisitType(),
            'admission_fee'  => 0.00,
            'payment_status' => 'Free',
            'paid_at'        => null,
            'last_visit'     => now(),
        ])->save();

        $this->unsetRelation('group');
        $this->load('group');
    }


    /**
     * Whether the front desk has cleared this visitor to enter the museum app.
     *
     * With a group today, the group's standing is theirs: the party paid (or
     * was registered as locals, face to face) as one. On their own, locals
     * need a sighted residency ID and everyone else needs the fee paid.
     * See clearance() above for the difference between the two.
     */
    public function isCleared(): bool
    {
        if ($this->isWithGroupToday()) {
            return $this->group->clearsMembers();
        }

        // A member of a past group who has not been back: their admission
        // was the party's, on the day the party came. Joining a group sets
        // payment_status to Free, and only a return visit on a later day
        // resets it to Unpaid - so Free plus an old group_id is a history
        // row, not somebody standing at the door. Without this branch every
        // such member read "Waiting for payment" in Records forever.
        if ($this->group && $this->payment_status === 'Free') {
            return $this->group->visitor_type === 'Local'
                || $this->group->payment_status === 'Paid';
        }

        return $this->visitor_type === 'Local'
            ? (bool) $this->id_verified
            : $this->payment_status === 'Paid';
    }

    /** What the visitor is still waiting on, in words staff can act on. */
    public function clearanceLabel(): string
    {
        if ($this->isCleared()) return 'Cleared';

        // A group member waits on the same thing as anyone else — the money —
        // it is just collected once for the party. The row's "With …" line
        // says which party; the badge does not need to repeat it.
        return $this->visitor_type === 'Local' && !$this->group
            ? 'Waiting for ID check'
            : 'Waiting for payment';
    }

    /**
     * Visitors currently blocked at the entrance, newest first.
     *
     * Group members are left out on purpose. They are blocked too, but the
     * thing the desk acts on is the group - one Mark Paid on the Groups tab
     * unlocks all of them - so listing each member here would put five rows
     * in the queue for one action.
     */
    public function scopePendingClearance($query)
    {
        return $query->whereNull('group_id')->where(function ($q) {
            $q->where(function ($local) {
                $local->where('visitor_type', 'Local')->where('id_verified', false);
            })->orWhere(function ($paying) {
                $paying->where('visitor_type', '!=', 'Local')->where('payment_status', '!=', 'Paid');
            });
        });
    }

    public function scans()
    {
        return $this->hasMany(Scan::class, 'visitor_id', 'visitor_id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'visitor_id', 'visitor_id');
    }

    public function group()
    {
        return $this->belongsTo(VisitGroup::class, 'group_id', 'group_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(Staff::class, 'registered_by', 'staff_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(Staff::class, 'verified_by', 'staff_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * One cell for where they are from, in whatever form says the most:
     *   Local    "Sabang, Baler"        barangay and town
     *   Tourist  "Quezon City, Cavite"  city and province - the country is
     *                                   always the Philippines, so it is noise
     *   Foreign  "Osaka, Japan"         city and country
     * Whichever parts are missing are simply left out.
     */
    public function getLocationAttribute(): string
    {
        $city = trim((string) $this->city);

        if ($this->barangay) {
            return implode(', ', array_filter([trim((string) $this->barangay), $city ?: 'Baler']));
        }

        $second = $this->visitor_type === 'Foreign'
            ? trim((string) $this->country)
            : trim((string) $this->province);

        return implode(', ', array_filter([$city, $second]));
    }
}
