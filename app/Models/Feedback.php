<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $primaryKey = 'feedback_id';

    protected $fillable = [
        'visitor_id', 'tour_id', 'staff_id',
        'rating', 'guide_rating', 'attributed_by', 'comment', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class, 'tour_id', 'tour_id');
    }

    /**
     * The guide this feedback is about. Null on most rows, because most
     * visits at Museo de Baler are unguided — that is expected, not missing.
     */
    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }
}
