<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VisitGroup extends Model
{
    protected $primaryKey = 'group_id';
    protected $table      = 'visit_groups';

    protected $fillable = [
        'join_code',
        'group_name', 'group_type', 'contact_name', 'contact_phone',
        'visitor_type', 'city', 'province', 'country',
        'headcount', 'local_count', 'paying_count', 'total_fee', 'payment_status', 'paid_at',
        'refunded_amount', 'refunded_at', 'refunded_by',
        'visit_date', 'registered_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'visit_date'      => 'date',
            'paid_at'         => 'datetime',
            'refunded_at'     => 'datetime',
            'total_fee'       => 'decimal:2',
            'refunded_amount' => 'decimal:2',
        ];
    }

    /**
     * How many of a party pay, from how many of them are from Baler.
     *
     * The desk is asked "anyone from Baler?" - the question a party can
     * actually answer at a counter - and this turns the answer into the fee.
     * A Local group is all locals, whatever number was typed.
     */
    public static function payingFor(string $visitorType, int $headcount, int $localCount): int
    {
        if ($visitorType === 'Local') {
            return 0;
        }

        return max(0, $headcount - max(0, $localCount));
    }

    /**
     * Change how many of the party are locals, and settle the money.
     *
     * Before payment this simply re-prices the group. After payment there is
     * cash to account for: if the fee drops, the difference goes back across
     * the counter and is recorded as a refund, so the logbook stops reporting
     * it as revenue; if it rises - a "local" turned out not to be one - the
     * difference is owed and the group goes back to Unpaid for it.
     *
     * total_fee is always what the group OWES after the correction.
     * refunded_amount is what has been handed back in total. Gross collected
     * for a Paid group is therefore total_fee + refunded_amount, which is
     * what the cash drawer actually saw come in.
     *
     * Returns a short account of what changed, for the flash and the log.
     */
    public function correctLocals(int $localCount, int $byStaffId): array
    {
        $localCount = min(max(0, $localCount), (int) $this->headcount);
        $wasFee     = (float) $this->total_fee;
        $wasStatus  = $this->payment_status;

        $paying = self::payingFor($this->visitor_type, (int) $this->headcount, $localCount);
        $newFee = self::feeFor($this->visitor_type, $paying);

        $this->local_count  = $localCount;
        $this->paying_count = $paying;
        $this->total_fee    = $newFee;

        $refund = 0.0;
        $owed   = 0.0;

        if ($wasStatus === 'Paid') {
            if ($newFee < $wasFee) {
                $refund = $wasFee - $newFee;
                $this->refunded_amount = (float) $this->refunded_amount + $refund;
                $this->refunded_at     = now();
                $this->refunded_by     = $byStaffId;
                // Still Paid: what is owed has been paid. Nothing to collect.
            } elseif ($newFee > $wasFee) {
                $owed = $newFee - $wasFee;
                $this->payment_status = 'Unpaid';
            }
        } else {
            // Not paid yet: nothing has changed hands, so only the label moves.
            $this->payment_status = $newFee > 0 ? 'Unpaid' : 'Free';
        }

        $this->save();

        return [
            'was_fee' => $wasFee,
            'fee'     => $newFee,
            'refund'  => $refund,
            'owed'    => $owed,
        ];
    }

    /**
     * Members who joined from their own phones saying they are from Baler,
     * over and above the locals the desk counted. Each one is a person the
     * group is very likely paying for who should be entering free.
     */
    public function unaccountedLocals(): int
    {
        if ($this->visitor_type === 'Local') {
            return 0;
        }

        $joinedLocals = $this->visitors()->where('visitor_type', 'Local')->count();

        return max(0, $joinedLocals - (int) $this->local_count);
    }

    /** What actually came in over the counter for this group, before any refund. */
    public function grossCollected(): float
    {
        if ($this->payment_status !== 'Paid') {
            return 0.0;
        }

        return (float) $this->total_fee + (float) $this->refunded_amount;
    }

    /**
     * No 0/O, no 1/I, no vowels that spell things. The code is read off the
     * desk screen and typed on a phone in a queue; every ambiguous glyph is a
     * member standing at the counter arguing about a letter.
     */
    public const JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const JOIN_CODE_LENGTH   = 6;

    /**
     * Locals enter free, so a mixed party only pays for its non-local members.
     * The desk enters that count directly rather than the system guessing it.
     */
    public static function feeFor(string $visitorType, int $payingCount): float
    {
        return $visitorType === 'Local' ? 0.00 : MuseumInfo::admissionFee() * max(0, $payingCount);
    }

    /**
     * A code no other group is using today. Uniqueness only has to hold
     * within the day, because the code is only valid on the group's own
     * visit_date - so the space stays small enough to type and the odds of a
     * collision on any one day stay negligible.
     */
    public static function freshJoinCode(?Carbon $date = null): string
    {
        $date = $date ?? today();

        do {
            $code = '';
            for ($i = 0; $i < self::JOIN_CODE_LENGTH; $i++) {
                $code .= self::JOIN_CODE_ALPHABET[random_int(0, strlen(self::JOIN_CODE_ALPHABET) - 1)];
            }
        } while (self::whereDate('visit_date', $date)->where('join_code', $code)->exists());

        return $code;
    }

    /**
     * The group a member's code points at, if it is still open to joining.
     *
     * Today only: a code from an earlier visit must not admit anybody. The
     * headcount cap is the other half of that - a leaked code can never let
     * in more people than the desk counted and charged for.
     */
    public static function joinable(string $code): ?self
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return self::whereDate('visit_date', today())
            ->where('join_code', $code)
            ->first();
    }

    public function isFull(): bool
    {
        return $this->visitors()->count() >= (int) $this->headcount;
    }

    /**
     * Whether being in this group gets a member through the entrance today.
     *
     * A paying group clears its members when the fee is collected. A Local
     * group clears them at once: the desk registered the party face to face,
     * looking at them, which is the residency check - there is no separate
     * ID step to wait for. Mirrors groupClearsMembers() in the visitor API.
     */
    public function clearsMembers(): bool
    {
        if (!$this->visit_date->isToday()) {
            return false;
        }

        return $this->visitor_type === 'Local' || $this->payment_status === 'Paid';
    }

    public function visitors()
    {
        return $this->hasMany(Visitor::class, 'group_id', 'group_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(Staff::class, 'registered_by', 'staff_id');
    }

    public function tour()
    {
        return $this->hasOne(Tour::class, 'group_id', 'group_id');
    }

    /** "Maria Santos's group" / "Baler Central School" — whatever it goes by. */
    public function getLabelAttribute(): string
    {
        return $this->group_name ?: "{$this->contact_name}'s group";
    }
}
