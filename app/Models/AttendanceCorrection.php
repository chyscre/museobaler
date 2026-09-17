<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to add or fix an attendance record by hand.
 *
 * Filing and approving are deliberately different people: the museum head
 * files (they were there and can vouch), the Tourism office approves (they
 * hold the oversight). A correction only becomes a real attendance row once
 * it is approved, and the resulting row carries both names.
 */
class AttendanceCorrection extends Model
{
    use StoresPlainDate;

    protected $primaryKey = 'correction_id';

    protected $fillable = [
        'staff_id', 'work_date', 'type', 'requested_time', 'reason',
        'requested_by', 'requested_at',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            // work_date is handled by StoresPlainDate — see the trait.
            'requested_at' => 'datetime',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(Staff::class, 'requested_by', 'staff_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(Staff::class, 'reviewed_by', 'staff_id');
    }
}
