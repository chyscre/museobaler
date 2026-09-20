<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One visitor's answer to one survey question. `code` is copied from the
 * question at submit time so the row still means something if the question
 * is later deleted; `value` null is N/A.
 */
class FeedbackAnswer extends Model
{
    protected $primaryKey = 'answer_id';

    protected $fillable = ['feedback_id', 'question_id', 'code', 'value'];

    public function feedback()
    {
        return $this->belongsTo(Feedback::class, 'feedback_id', 'feedback_id');
    }

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id', 'question_id');
    }
}
