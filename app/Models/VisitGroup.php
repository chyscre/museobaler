<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitGroup extends Model
{
    protected $primaryKey = 'group_id';
    protected $table      = 'visit_groups';

    protected $fillable = [
        'group_name', 'group_type', 'contact_name', 'contact_phone',
        'visitor_type', 'city', 'province', 'country',
        'headcount', 'paying_count', 'total_fee', 'payment_status', 'paid_at',
        'visit_date', 'registered_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'paid_at'    => 'datetime',
            'total_fee'  => 'decimal:2',
        ];
    }

    /**
     * Locals enter free, so a mixed party only pays for its non-local members.
     * The desk enters that count directly rather than the system guessing it.
     */
    public static function feeFor(string $visitorType, int $payingCount): float
    {
        return $visitorType === 'Local' ? 0.00 : MuseumInfo::admissionFee() * max(0, $payingCount);
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
}
