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

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id', 'exhibit_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }
}
