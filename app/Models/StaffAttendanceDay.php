<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;

/**
 * Holds the rotating-QR secret for one working day.
 *
 * The secret stays server-side: the staff-room screen only ever shows a code
 * derived from it, and that code changes every 60 seconds. Nothing here is
 * exposed to the browser.
 */
class StaffAttendanceDay extends Model
{
    use StoresPlainDate;

    protected $primaryKey   = 'work_date';
    protected $keyType      = 'string';
    public    $incrementing = false;
    protected $table        = 'staff_attendance_days';

    protected $fillable = ['work_date', 'day_secret', 'opened_by'];

    public function openedBy()
    {
        return $this->belongsTo(Staff::class, 'opened_by', 'staff_id');
    }

    protected $hidden = ['day_secret'];
}
