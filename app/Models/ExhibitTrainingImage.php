<?php

namespace App\Models;

use App\Services\Recognition;
use Illuminate\Database\Eloquent\Model;

class ExhibitTrainingImage extends Model
{
    protected $primaryKey = 'training_image_id';

    protected $fillable = ['exhibit_id', 'filename'];

    public function getUrlAttribute(): string
    {
        return route('recognition.photo', ['filename' => $this->filename]);
    }

    public function getPathAttribute(): string
    {
        return public_path(Recognition::DIR . '/' . basename($this->filename));
    }

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id', 'exhibit_id');
    }
}
