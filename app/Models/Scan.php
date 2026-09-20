<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $primaryKey = 'scan_id';

    protected $fillable = ['exhibit_id', 'visitor_id', 'language_code', 'scanned_at'];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * Stamp scanned_at in the app's own clock rather than leaning on the
     * column default - same reason as Feedback::submitted_at: the database's
     * CURRENT_TIMESTAMP follows its own zone, and on SQLite that is always UTC.
     */
    protected static function booted(): void
    {
        static::creating(function (Scan $scan) {
            $scan->scanned_at ??= now();
        });
    }

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id', 'exhibit_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }
}
