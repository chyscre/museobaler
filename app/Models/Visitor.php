<?php

namespace App\Models;

use App\Support\Admission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
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

    /**
     * The most free companions one visitor can bring. A family's worth;
     * more than this is a party, and the desk registers it as a group.
     */
    public const MAX_COMPANIONS = 10;

    protected $fillable = [
        'first_name', 'last_name', 'middle_name',
        'age', 'sex', 'visitor_type', 'visit_type',
        'city', 'barangay', 'province', 'country', 'email', 'password', 'auth_provider',
        'explore_mode',
        'group_id', 'companion_of', 'source', 'registered_by',
        'admission_fee', 'discount_id', 'discount_name', 'discount_percent',
        'payment_status', 'paid_at',
        'id_verified', 'verified_at', 'verified_by',
        'last_visit',
    ];

    /**
     * SECURITY: never serialise the credential columns. Nothing in the admin
     * panel should ever read them back, and this stops one leaking through
     * a JSON response or a debug dump.
     */
    protected $hidden = ['password', 'api_token', 'token_expires_at', 'remember_token', 'google_id'];

    protected function casts(): array
    {
        return [
            'last_visit'       => 'datetime',
            'paid_at'          => 'datetime',
            'verified_at'      => 'datetime',
            'token_expires_at'  => 'datetime',
            'email_verified_at' => 'datetime',
            'id_verified'      => 'boolean',
            'admission_fee'    => 'decimal:2',
            'password'         => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Every change to today's visit is copied into its row in the visit
        // history, from whichever path made it: desk, app, Records, group.
        static::saved(function (Visitor $visitor) {
            if ($visitor->wasRecentlyCreated || $visitor->wasChanged(Visit::TRACKED)) {
                Visit::record($visitor);
            }
        });
    }

    // -- Email verification --------------------------------------------------

    /** True once they have proved they own the inbox. No token is issued before. */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** Stamp the proof, once; a later proof leaves the first date alone. */
    public function markEmailVerified(): void
    {
        if ($this->email_verified_at === null) {
            $this->forceFill(['email_verified_at' => now()])->save();
        }
        VisitorEmailVerification::where('visitor_id', $this->visitor_id)->delete();
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
     * Locals enter free (subject to showing a valid ID); everyone else pays
     * the fee set on the Museum Info page, less any discount category they
     * claimed. See App\Support\Admission.
     */
    public static function feeFor(string $visitorType, ?AdmissionDiscount $discount = null): float
    {
        return Admission::priceFor($visitorType, $discount);
    }

    /**
     * The discount columns for a claimed category - its name and percentage
     * copied now, so a later edit to the category does not rewrite what this
     * visitor was charged - or all null for none.
     */
    public static function discountColumns(?AdmissionDiscount $discount): array
    {
        return [
            'discount_id'      => $discount?->discount_id,
            'discount_name'    => $discount?->name,
            'discount_percent' => $discount?->percent_off,
        ];
    }

    /**
     * Whether this visitor enters free on a document the desk has to see:
     * a local's residency ID, or the proof for a category that is free
     * (a senior citizen ID where seniors enter free). A category that only
     * takes something off is checked when the reduced fee is collected, so
     * Mark Paid covers it.
     */
    public function needsIdCheck(): bool
    {
        return $this->visitor_type === 'Local' || (int) $this->discount_percent >= 100;
    }

    /** needsIdCheck(), as a query. */
    public function scopeAwaitsIdCheck($query)
    {
        return $query->where(fn ($q) => $q->where('visitor_type', 'Local')
            ->orWhere('discount_percent', '>=', 100));
    }

    public function scopePaysAtCounter($query)
    {
        return $query->where('visitor_type', '!=', 'Local')
            ->where(fn ($q) => $q->whereNull('discount_percent')->orWhere('discount_percent', '<', 100));
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
        // A group loaded before group_id changed is the wrong party: joinGroup()
        // saves - which writes today's visit row - before it reloads.
        if ($this->relationLoaded('group') && (int) $this->group?->group_id !== (int) $this->group_id) {
            $this->unsetRelation('group');
        }

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

        // Admission is per visit, so what clears somebody is staff acting on
        // THIS visit - not a flag set on an earlier one. Both branches ask
        // the date as well as the flag, the way the group branch above has
        // always asked visit_date.
        //
        // Reading the date here rather than trusting touchReturning() to have
        // reset the flag is deliberate. That method only runs on login, and a
        // token is good for 24 hours, so an app resumed the next morning
        // never called it: a visitor who paid at 6pm Monday was still
        // 'cleared' on Tuesday. The gate is re-evaluated on every request,
        // so asking the date here closes that without depending on which
        // path the app took to get here.
        if ($this->needsIdCheck()) {
            $own = $this->id_verified && $this->verified_at?->isToday()
                ? 'cleared'
                : 'pending_id';
        } else {
            $own = $this->payment_status === 'Paid' && $this->paid_at?->isToday()
                ? 'cleared'
                : 'pending_payment';
        }

        // Companions enter free on a document, like a free visitor does. The
        // desk action that clears the holder checks theirs too (verifyId,
        // markPaid), so this only holds a holder back if companions were
        // somehow added after that - their IDs have not been seen.
        if ($own === 'cleared' && $this->hasUncheckedCompanions()) {
            return 'pending_id';
        }

        return $own;
    }

    /**
     * The public shape of the admission state, safe to hand to the app.
     * Never includes the password or token hash.
     */
    public function clearancePayload(): array
    {
        $state      = $this->clearance();
        $companions = $this->companionSummary();

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
            // The category they claimed, so the waiting screen can say which
            // document to show ("Senior citizen ID") rather than "residency".
            'discount'       => $this->discount_name ? [
                'name'        => $this->discount_name,
                'percent_off' => (int) $this->discount_percent,
                'proof'       => Admission::discount($this->discount_id)?->proof,
            ] : null,
            'resident_place' => Admission::residentPlace(),
            'payment_status' => $this->payment_status,
            'id_verified'    => (bool) $this->id_verified,
            'explore_mode'   => $this->explore_mode,
            'clearance'      => $state,
            'cleared'        => $state === 'cleared',
            // Who they came with, so the app can say "you're with Maria's
            // group" and explain that the group's payment is what they wait on.
            // The code is shown so a member can pass it to the rest of the
            // party; it only works today and only up to the headcount
            // (VisitGroup::joinable), the same as reading it off the desk.
            'group'          => $this->isWithGroupToday() ? [
                'label'          => $this->group->label,
                'headcount'      => (int) $this->group->headcount,
                'payment_status' => $this->group->payment_status,
                'join_code'      => $this->group->join_code,
                'signed_in_by'   => $this->group->contact_name,
                'reference'      => AdmissionPayment::referenceFor($this->group),
                'joined'         => $this->group->visitors()->count(),
            ] : null,
            // The free people they brought today, and today's admission as a
            // receipt: their own line, a ₱0.00 line per category of
            // companion, and the transaction number once the desk has one.
            'companions'     => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'count' => $c['count']], $companions),
            'headcount'      => 1 + array_sum(array_column($companions, 'count')),
            'admission'      => $this->isWithGroupToday() ? null : $this->admissionToday($companions),
        ];
    }

    /**
     * Today's admission, itemised. Before the desk has acted this is what
     * the app shows as the amount due; afterwards it is the ledger entry,
     * with its MDB number, exactly as recorded.
     *
     * @param  list<array{name: string, count: int}>  $companions
     */
    public function admissionToday(array $companions): array
    {
        $entry = AdmissionPayment::where('visitor_id', $this->visitor_id)
            ->where('payer', AdmissionPayment::INDIVIDUAL)
            ->where('kind', AdmissionPayment::PAYMENT)
            ->whereDate('recorded_at', today())
            ->latest('recorded_at')->latest('payment_id')
            ->first();

        if ($entry && !empty($entry->breakdown['lines'])) {
            return [
                'reference' => $entry->reference,
                'lines'     => $entry->breakdown['lines'],
                'total'     => (float) $entry->amount,
            ];
        }

        $fee = (float) $this->admission_fee;

        return [
            'reference' => null,
            'lines'     => array_merge(
                Admission::visitorLines($this->visitor_type, $fee, $this->discount_name, $this->discount_percent),
                Admission::companionLines($companions),
            ),
            'total'     => $fee,
        ];
    }

    // -- Companions ----------------------------------------------------------

    /**
     * Today's companions: the free people this visitor brought along. See
     * the add_companions migration.
     */
    public function companionsToday()
    {
        return $this->companions()->whereDate('last_visit', today());
    }

    /**
     * Today's companions by category, in the same shape as a group's
     * discount snapshot.
     *
     * @return list<array{id: int, name: string, percent_off: int, count: int}>
     */
    public function companionSummary(): array
    {
        if ($this->companion_of !== null || !$this->exists) {
            return [];
        }

        return $this->companionsToday()
            ->selectRaw('discount_id, discount_name, discount_percent, COUNT(*) as heads')
            ->groupBy('discount_id', 'discount_name', 'discount_percent')
            ->orderBy('discount_id')
            ->get()
            ->map(fn ($c) => [
                'id'          => (int) $c->discount_id,
                'name'        => (string) $c->discount_name,
                'percent_off' => (int) $c->discount_percent,
                'count'       => (int) $c->heads,
            ])->all();
    }

    /** Companions here today whose documents the desk has not seen. */
    public function hasUncheckedCompanions(): bool
    {
        if ($this->companion_of !== null || !$this->exists) {
            return false;
        }

        return $this->companionsToday()
            ->where(fn ($q) => $q->where('id_verified', false)->orWhereNull('verified_at')
                ->orWhereDate('verified_at', '<', today()))
            ->exists();
    }

    /**
     * Replace today's companions with these counts (category id => heads).
     *
     * Each one is a nameless visitors row, like an express entry, so the
     * reports count them. They take the holder's type and where they are
     * from - a grandmother travelling with a family from Manila is a tourist
     * from Manila too - and only a free category.
     *
     * With a staff id the desk is looking at them and their documents, so
     * they are checked on the spot; from the app they wait for the desk.
     *
     * Rows being replaced are deleted, with their visit rows: they were
     * counted minutes ago on this visitor's say-so and nobody has seen them.
     * The caller refuses the change once they have been (see the API).
     *
     * @param  array<int|string, int|string|null>  $counts
     * @return list<array{id: int, name: string, percent_off: int, count: int}>  what was recorded
     */
    public function setCompanions(array $counts, ?int $byStaffId = null): array
    {
        $wanted = Admission::companionCounts($counts);

        DB::transaction(function () use ($wanted, $byStaffId) {
            $old = $this->companionsToday()->pluck('visitor_id');
            Visit::whereIn('visitor_id', $old)->delete();
            self::whereIn('visitor_id', $old)->delete();

            $checked = $byStaffId !== null
                ? ['id_verified' => true, 'verified_at' => now(), 'verified_by' => $byStaffId]
                : ['id_verified' => false];

            foreach ($wanted as $c) {
                $discount = Admission::discount($c['id']);
                for ($i = 0; $i < $c['count']; $i++) {
                    self::create($checked + self::discountColumns($discount) + [
                        'companion_of'   => $this->visitor_id,
                        'visitor_type'   => $this->visitor_type,
                        'visit_type'     => $this->visit_type,
                        'city'           => $this->city,
                        'barangay'       => $this->barangay,
                        'province'       => $this->province,
                        'country'        => $this->country,
                        'auth_provider'  => 'manual',
                        'source'         => 'express',
                        'registered_by'  => $byStaffId,
                        'admission_fee'  => 0.00,
                        'payment_status' => 'Free',
                        'last_visit'     => now(),
                    ]);
                }
            }

            Visit::record($this);
        });

        return $wanted;
    }

    /**
     * The desk has seen today's companions' documents. One at a time rather
     * than one UPDATE, so each companion's own visit row records it too.
     */
    public function checkCompanions(int $byStaffId): int
    {
        $rows = $this->companionsToday()
            ->where(fn ($q) => $q->where('id_verified', false)->orWhereNull('verified_at')
                ->orWhereDate('verified_at', '<', today()))
            ->get();

        $rows->each->update(['id_verified' => true, 'verified_at' => now(), 'verified_by' => $byStaffId]);

        return $rows->count();
    }

    /** Today's entry was taken back, so theirs goes with it. */
    public function uncheckCompanions(): void
    {
        $this->companionsToday()->get()
            ->each->update(['id_verified' => false, 'verified_at' => null, 'verified_by' => null]);
    }

    /**
     * Mark a returning visitor as here again.
     *
     * Admission is charged per visit, so a paying visitor whose last visit
     * was on an earlier date owes the fee again and their payment status
     * resets to Unpaid. Locals stay Free - there is no fee to re-charge -
     * but their ID check no longer carries over either: clearance() reads
     * verified_at, so a residency check sighted on an earlier day stops
     * clearing them the moment the date rolls, without this method having to
     * run at all.
     *
     * The flags themselves are left standing rather than cleared, because
     * isCleared() reads them to say how a PAST visit ended, and a Records
     * page that reported every previous visitor as "Waiting for ID check"
     * would be rewriting history to describe today.
     */
    public function touchReturning(): void
    {
        $newDay = $this->last_visit === null || !$this->last_visit->isToday();

        if ($this->visitor_type !== 'Local' && $newDay) {
            // Priced at today's rules. A senior is still a senior, so the
            // category carries over - at its current percentage, and only
            // while the museum still offers it.
            $discount = $this->discount_id ? Admission::discount($this->discount_id) : null;
            $this->fill(self::discountColumns($discount));

            $this->admission_fee  = self::feeFor($this->visitor_type, $discount);
            $this->payment_status = $this->admission_fee > 0 ? 'Unpaid' : 'Free';
            $this->paid_at        = null;
        }

        // The party they last came with is not here today, so they came on
        // their own. group_id stays as history (see isWithGroupToday); the
        // visit type was the party's and does not carry over.
        if ($newDay && $this->group_id !== null && !$this->isWithGroupToday()) {
            $this->visit_type = 'Walk-in';
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

        // The group's headcount is the whole party, so anyone they had
        // declared bringing along is in it already. Keeping them as well
        // would count them twice.
        if ($this->companionsToday()->exists()) {
            $this->setCompanions([]);
        }
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

        return $this->needsIdCheck()
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
        return $this->needsIdCheck() && !$this->group
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
        return $query
            // Here today. Clearance lapses overnight, so without this every
            // visitor who ever came and did not return would queue at the
            // desk forever - the list is who is waiting at the door now, not
            // everyone whose last visit has gone stale.
            ->whereDate('last_visit', today())
            ->whereNull('group_id')
            // Companions likewise: the holder's Verify or Mark Paid checks them.
            ->whereNull('companion_of')
            ->where(function ($q) {
                $q->where(function ($local) {
                    $local->awaitsIdCheck()
                        ->where(fn ($c) => $c->where('id_verified', false)
                            ->orWhereNull('verified_at')
                            ->orWhereDate('verified_at', '<', today()));
                })->orWhere(function ($paying) {
                    $paying->paysAtCounter()
                        ->where(fn ($c) => $c->where('payment_status', '!=', 'Paid')
                            ->orWhereNull('paid_at')
                            ->orWhereDate('paid_at', '<', today()));
                });
            });
    }

    /** Every day they came, newest first. See App\Models\Visit. */
    public function visits()
    {
        return $this->hasMany(Visit::class, 'visitor_id', 'visitor_id')->orderByDesc('visit_date');
    }

    public function scans()
    {
        return $this->hasMany(Scan::class, 'visitor_id', 'visitor_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'visitor_id', 'visitor_id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'visitor_id', 'visitor_id');
    }

    public function group()
    {
        return $this->belongsTo(VisitGroup::class, 'group_id', 'group_id');
    }

    /** Every free companion they have brought, on any day. See companionsToday(). */
    public function companions()
    {
        return $this->hasMany(self::class, 'companion_of', 'visitor_id');
    }

    /** For a companion: whose visit they came on. */
    public function holder()
    {
        return $this->belongsTo(self::class, 'companion_of', 'visitor_id');
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
        $name = trim("{$this->first_name} {$this->last_name}");
        if ($name !== '') {
            return $name;
        }

        // A companion is known by whose visit they came on.
        if ($this->companion_of !== null) {
            return ($this->discount_name ?: 'Companion') . ' · with ' . ($this->holder?->full_name ?? 'a visitor');
        }

        // An express entry gives no name; say what they were counted as.
        return $this->isExpress()
            ? 'Express entry · ' . ($this->discount_name ?: $this->visitor_type)
            : 'Visitor #' . $this->visitor_id;
    }

    /** Counted at the desk without a name: see DeskController::storeExpress(). */
    public function isExpress(): bool
    {
        return $this->source === 'express';
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
