<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $primaryKey = 'visitor_id';

    protected $fillable = [
        'first_name', 'last_name', 'middle_name',
        'age', 'sex', 'visitor_type', 'visit_type',
        'city', 'barangay', 'province', 'country', 'email', 'auth_provider',
        'group_id', 'source', 'registered_by',
        'admission_fee', 'payment_status', 'paid_at',
        'id_verified', 'verified_at', 'verified_by',
        'last_visit',
    ];

    /**
     * SECURITY: never serialise the credential columns. The visitor API sets
     * them directly; nothing in the admin panel should ever read them back,
     * and this stops one leaking through a JSON response or a debug dump.
     */
    protected $hidden = ['password', 'api_token'];

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
     * Whether the front desk has cleared this visitor to enter the museum app.
     *
     * With a group today, the group's standing is theirs: the party paid (or
     * was registered as locals, face to face) as one. On their own, locals
     * need a sighted residency ID and everyone else needs the fee paid.
     * Mirrors visitorClearance() in public/api/_visitor_auth.php.
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
