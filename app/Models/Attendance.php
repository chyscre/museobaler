<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'visitor_id', 'visitor_name', 'latitude', 'longitude', 'accuracy', 'method', 'visit_date',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }
}
