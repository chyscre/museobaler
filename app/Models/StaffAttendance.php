<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;

class StaffAttendance extends Model
{
    use StoresPlainDate;

    protected $primaryKey = 'staff_attendance_id';
    protected $table      = 'staff_attendances';

    protected $fillable = [
        'staff_id', 'work_date', 'type', 'scanned_at',
        'latitude', 'longitude', 'accuracy', 'distance_m',
        'method', 'recorded_by', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            // work_date is handled by StoresPlainDate, not a cast — see the
            // trait for why the built-in date cast is wrong for a DATE column.
            'scanned_at' => 'datetime',
            'latitude'   => 'decimal:7',
            'longitude'  => 'decimal:7',
        ];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(Staff::class, 'recorded_by', 'staff_id');
    }

    public function scopeOnDate($query, $date)
    {
        return $query->whereDate('work_date', $date);
    }
}
