<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tour extends Model
{
    protected $primaryKey = 'tour_id';

    protected $fillable = [
        'guide_staff_id', 'tour_type', 'visitor_id', 'group_id',
        'headcount', 'started_at', 'ended_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function createdBy()
    {
        return $this->belongsTo(Staff::class, 'created_by', 'staff_id');
    }

    public function guide()
    {
        return $this->belongsTo(Staff::class, 'guide_staff_id', 'staff_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }

    public function group()
    {
        return $this->belongsTo(VisitGroup::class, 'group_id', 'group_id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'tour_id', 'tour_id');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->started_at !== null && $this->ended_at === null;
    }
}
