<?php

namespace App\Models;

use App\Support\Admission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One movement of money across the admission counter.
 *
 * The ledger the earnings report reads. A visitor's or a group's own row
 * describes the current visit and is overwritten on the next one; this is
 * written once and never edited, so a day's takings stay what they were.
 * See the create_admission_payments migration for why it exists, and
 * add_transaction_details for the reference, breakdown and visitor ids.
 *
 * Amounts are positive. `kind` says which way the money went, and
 * signedAmount() is what a total should add up.
 */
class AdmissionPayment extends Model
{
    protected $primaryKey = 'payment_id';

    public const PAYMENT = 'payment';
    public const REFUND  = 'refund';

    public const INDIVIDUAL = 'individual';
    public const GROUP      = 'group';

    /** The museum's mark at the front of every transaction number. */
    public const PREFIX = 'MDB';

    protected $fillable = [
        'reference', 'kind', 'payer', 'visitor_id', 'visit_id', 'group_id', 'payer_name', 'visitor_type',
        'headcount', 'visitor_ids', 'amount', 'breakdown', 'recorded_at', 'recorded_by', 'backfilled',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'recorded_at' => 'datetime',
            'backfilled'  => 'boolean',
            'visitor_ids' => 'array',
            'breakdown'   => 'array',
        ];
    }

    public function recordedBy()
    {
        return $this->belongsTo(Staff::class, 'recorded_by', 'staff_id');
    }

    public function visit()
    {
        return $this->belongsTo(Visit::class, 'visit_id', 'visit_id');
    }

    /** The transaction number printed on the report: MDB-20261002-0001. */
    public function getNumberAttribute(): string
    {
        return $this->reference ?? ('#' . $this->payment_id);
    }

    public function signedAmount(): float
    {
        return $this->kind === self::REFUND ? -(float) $this->amount : (float) $this->amount;
    }

    /** "2 × Full admission ₱50.00 · 1 × Senior citizen (free)", for a table cell. */
    public function getBreakdownSummaryAttribute(): ?string
    {
        $lines = $this->breakdown['lines'] ?? null;
        if (!$lines) {
            return null;
        }

        return implode(' · ', array_map(
            fn ($l) => $l['count'] . ' × ' . $l['label'] . ($l['unit'] > 0 ? ' ₱' . number_format($l['unit'], 2) : ''),
            $lines,
        ));
    }

    // -- Transaction numbers -----------------------------------------------

    /**
     * The next number in the day's sequence: MDB-20261002-0001, -0002...
     *
     * The day is the one the money moved on, in the museum's timezone. The
     * read locks the day's numbers, so two desks saving at once queue rather
     * than both taking the same one; the unique index is the backstop, and
     * record() retries if it ever fires.
     */
    public static function nextReference(Carbon $at): string
    {
        $prefix = self::PREFIX . '-' . $at->copy()->timezone(config('app.timezone'))->format('Ymd') . '-';

        // Longest first, then highest: past 9999 the numbers grow a digit,
        // and "10000" sorts below "9999" as a string.
        $last = self::query()
            ->where('reference', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(reference) DESC')
            ->orderByDesc('reference')
            ->lockForUpdate()
            ->value('reference');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Insert a ledger row under the next transaction number. */
    private static function record(array $attributes): self
    {
        $at = Carbon::parse($attributes['recorded_at'] ?? now());

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => self::create($attributes + [
                    'reference' => self::nextReference($at),
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    // -- Writing the ledger ------------------------------------------------

    public static function forVisitor(Visitor $visitor, ?int $byStaffId): self
    {
        $at = $visitor->paid_at ?? now();

        return self::record([
            'kind'         => self::PAYMENT,
            'payer'        => self::INDIVIDUAL,
            'visitor_id'   => $visitor->visitor_id,
            'visit_id'     => Visit::where('visitor_id', $visitor->visitor_id)
                                  ->whereDate('visit_date', $at)->value('visit_id'),
            'payer_name'   => $visitor->full_name,
            'visitor_type' => $visitor->visitor_type,
            'headcount'    => 1,
            'visitor_ids'  => [(int) $visitor->visitor_id],
            'amount'       => (float) $visitor->admission_fee,
            'breakdown'    => [
                'lines' => Admission::visitorLines($visitor->visitor_type, (float) $visitor->admission_fee,
                    $visitor->discount_name, $visitor->discount_percent),
                'total' => (float) $visitor->admission_fee,
            ],
            'recorded_at'  => $at,
            'recorded_by'  => $byStaffId,
        ]);
    }

    /**
     * Money handed back to a visitor whose entry was revoked.
     *
     * The amount is what they paid on their own that day, net of anything
     * already refunded - not their current admission_fee, which may have
     * been re-priced since. See VisitorController::revoke().
     */
    public static function refundForVisitor(Visitor $visitor, float $amount, ?int $byStaffId): self
    {
        $at = now();

        return self::record([
            'kind'         => self::REFUND,
            'payer'        => self::INDIVIDUAL,
            'visitor_id'   => $visitor->visitor_id,
            'visit_id'     => Visit::where('visitor_id', $visitor->visitor_id)
                                  ->whereDate('visit_date', $at)->value('visit_id'),
            'payer_name'   => $visitor->full_name,
            'visitor_type' => $visitor->visitor_type,
            'headcount'    => 1,
            'visitor_ids'  => [(int) $visitor->visitor_id],
            'amount'       => $amount,
            'breakdown'    => [
                'lines' => Admission::visitorLines($visitor->visitor_type, $amount,
                    $visitor->discount_name, $visitor->discount_percent),
                'total' => $amount,
            ],
            'recorded_at'  => $at,
            'recorded_by'  => $byStaffId,
        ]);
    }

    /** What a visitor paid on their own on one day, after refunds. */
    public static function netForVisitorOn(Visitor $visitor, Carbon $day): float
    {
        return (float) self::where('visitor_id', $visitor->visitor_id)
            ->where('payer', self::INDIVIDUAL)
            ->whereDate('recorded_at', $day)
            ->get(['kind', 'amount'])
            ->sum(fn (self $p) => $p->signedAmount());
    }

    /**
     * A group paying what it owes.
     *
     * What it owes is total_fee less what it has already paid, net of
     * refunds. On a first payment that is the whole fee. After an upward
     * correction it is only the difference. That is what the desk is told to
     * collect, so recording the full fee again would count the first payment
     * twice.
     */
    public static function forGroup(VisitGroup $group, ?int $byStaffId): ?self
    {
        $before = self::netFor($group);
        $due    = round((float) $group->total_fee - $before, 2);

        if ($due <= 0) {
            return null;
        }

        return self::record(self::groupColumns($group) + [
            'kind'        => self::PAYMENT,
            'amount'      => $due,
            'breakdown'   => self::groupBreakdown($group) + ['paid_before' => $before],
            'recorded_at' => $group->paid_at ?? now(),
            'recorded_by' => $byStaffId,
        ]);
    }

    public static function refundForGroup(VisitGroup $group, float $amount, ?int $byStaffId): self
    {
        return self::record(self::groupColumns($group) + [
            'kind'        => self::REFUND,
            'amount'      => $amount,
            'breakdown'   => self::groupBreakdown($group) + ['was' => round((float) $group->total_fee + $amount, 2)],
            'recorded_at' => now(),
            'recorded_by' => $byStaffId,
        ]);
    }

    /** What a group has paid so far, after refunds. */
    public static function netFor(VisitGroup $group): float
    {
        return (float) self::where('group_id', $group->group_id)
            ->get(['kind', 'amount'])
            ->sum(fn (self $p) => $p->signedAmount());
    }

    private static function groupColumns(VisitGroup $group): array
    {
        return [
            'payer'        => self::GROUP,
            'group_id'     => $group->group_id,
            'payer_name'   => $group->group_name ?: $group->contact_name,
            'visitor_type' => $group->visitor_type,
            'headcount'    => (int) $group->headcount,
            // The members who had joined from their phones by now. The rest
            // of the headcount was counted by the desk and has no record.
            'visitor_ids'  => $group->visitors()->orderBy('visitor_id')->pluck('visitor_id')
                                  ->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /** How the group's fee is made up, itemised at the time of the transaction. */
    private static function groupBreakdown(VisitGroup $group): array
    {
        $price = Admission::groupPrice($group->visitor_type, (int) $group->headcount,
            (int) $group->local_count, $group->discounts ?? []);

        return ['lines' => $price['lines'], 'total' => (float) $group->total_fee];
    }
}
