<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffSchedule extends Model
{
    protected $primaryKey = 'schedule_id';

    protected $fillable = [
        'staff_id', 'weekday', 'shift_start', 'shift_end', 'grace_minutes', 'is_rest_day',
    ];

    protected function casts(): array
    {
        return ['is_rest_day' => 'boolean'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }
}
