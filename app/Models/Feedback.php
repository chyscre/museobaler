<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $primaryKey = 'feedback_id';

    protected $fillable = [
        'visitor_id', 'tour_id', 'staff_id',
        'first_name', 'last_name', 'middle_name',
        'rating', 'guide_rating', 'attributed_by', 'comment', 'submitted_at',
        'client_type', 'region',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Stamp submitted_at in the app's own clock rather than leaning on the
     * column default: SQLite's CURRENT_TIMESTAMP is UTC, so a survey filed
     * after midnight Manila time would otherwise fall off today's report.
     */
    protected static function booted(): void
    {
        static::creating(function (Feedback $feedback) {
            $feedback->submitted_at ??= now();
        });
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

    /**
     * The ARTA survey answers behind the star rating, one row per question.
     * Feedback filed before the survey existed simply has none.
     */
    public function answers()
    {
        return $this->hasMany(FeedbackAnswer::class, 'feedback_id', 'feedback_id');
    }
}
