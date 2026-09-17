<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExhibitTranslation extends Model
{
    protected $primaryKey = 'translation_id';

    protected $fillable = [
        'exhibit_id', 'language_code', 'language_label',
        'title', 'description', 'fun_facts', 'audio_file',
    ];

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id', 'exhibit_id');
    }

    public function getAudioUrlAttribute(): ?string
    {
        if (!$this->audio_file) return null;
        // Audio files in public/audio/ served via proxy route
        if (file_exists(public_path('audio/' . $this->audio_file))) {
            return route('exhibit.audio', ['filename' => $this->audio_file]);
        }
        // Newly uploaded files in Laravel public storage
        return \Illuminate\Support\Facades\Storage::disk('public')->url('audio/' . $this->audio_file);
    }
}
