<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day a visitor came: a copy of how that visit went.
 *
 * The visitors row is the person plus their CURRENT visit, and a return
 * visit overwrites the second half. This keeps each visit after the
 * visitor row has moved on. See the create_visits migration.
 *
 * Written only by record(), from Visitor's saved hook, and only for today.
 * A past day's row is therefore never touched again: the history can grow
 * but not be rewritten.
 */
class Visit extends Model
{
    protected $primaryKey = 'visit_id';

    /** The visitor columns that describe the visit rather than the person. */
    public const TRACKED = [
        'last_visit', 'group_id', 'visitor_type', 'visit_type',
        'discount_id', 'discount_name', 'discount_percent',
        'admission_fee', 'payment_status', 'paid_at', 'id_verified', 'verified_at',
    ];

    protected $fillable = [
        'visitor_id', 'visit_date', 'arrived_at', 'group_id', 'visitor_type', 'visit_type',
        'discount_id', 'discount_name', 'discount_percent', 'admission_fee',
        'payment_status', 'paid_at', 'id_verified', 'verified_at', 'source', 'backfilled',
    ];

    protected function casts(): array
    {
        return [
            'visit_date'    => 'date',
            'arrived_at'    => 'datetime',
            'paid_at'       => 'datetime',
            'verified_at'   => 'datetime',
            'id_verified'   => 'boolean',
            'backfilled'    => 'boolean',
            'admission_fee' => 'decimal:2',
        ];
    }

    /**
     * Copy the visitor's current visit into today's row, creating it on the
     * first write of the day. Does nothing unless the visit is today's.
     */
    public static function record(Visitor $visitor): void
    {
        if ($visitor->last_visit === null || !$visitor->last_visit->isToday()) {
            return;
        }

        // whereDate, not an exact match: the date cast writes a time on some
        // drivers and the backfill wrote none.
        $day   = $visitor->last_visit->toDateString();
        $visit = self::where('visitor_id', $visitor->visitor_id)->whereDate('visit_date', $day)->first()
            ?? new self(['visitor_id' => $visitor->visitor_id, 'visit_date' => $day]);

        $visit->arrived_at ??= $visitor->last_visit;
        $visit->source     ??= $visitor->source;

        $visit->fill([
            // Only a party that is here today. The visitor's group_id is the
            // last party they came with, kept as history, and copying it
            // filed every later solo visit under that old group.
            'group_id'         => $visitor->isWithGroupToday() ? $visitor->group_id : null,
            'visitor_type'     => $visitor->visitor_type,
            'visit_type'       => $visitor->visit_type,
            'discount_id'      => $visitor->discount_id,
            'discount_name'    => $visitor->discount_name,
            'discount_percent' => $visitor->discount_percent,
            'admission_fee'    => $visitor->admission_fee,
            'payment_status'   => $visitor->payment_status,
            'paid_at'          => $visitor->paid_at,
            'id_verified'      => $visitor->id_verified,
            'verified_at'      => $visitor->verified_at,
        ])->save();
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }

    public function group()
    {
        return $this->belongsTo(VisitGroup::class, 'group_id', 'group_id');
    }

    /** The money taken for this visit, if any (an individual's own fee). */
    public function payment()
    {
        return $this->hasOne(AdmissionPayment::class, 'visit_id', 'visit_id')
            ->where('kind', AdmissionPayment::PAYMENT);
    }

    /** "Paid ₱50.00", "Free · Senior citizen", "With Maria's group". */
    public function getSummaryAttribute(): string
    {
        if ($this->payment_status === null) {
            return 'Came (details from before visits were kept)';
        }
        if ($this->group_id) {
            return 'With ' . ($this->group?->label ?? 'a group');
        }
        if ($this->payment_status === 'Free') {
            return 'Free' . ($this->discount_name ? ' · ' . $this->discount_name : '');
        }

        return $this->payment_status . ' ₱' . number_format((float) $this->admission_fee, 2)
            . ($this->discount_name ? ' · ' . $this->discount_name : '');
    }
}
