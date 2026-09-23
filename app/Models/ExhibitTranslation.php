<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExhibitTranslation extends Model
{
    protected $primaryKey = 'translation_id';

    protected $fillable = [
        'exhibit_id', 'language_code', 'language_label',
        'title', 'description', 'fun_facts', 'audio_file',
        'audio_made_at', 'audio_text_hash',
    ];

    protected function casts(): array
    {
        return ['audio_made_at' => 'datetime'];
    }

    /**
     * The text a narration reads: title, then description, then the facts.
     * Must stay in step with narrationText() in public/js/exhibit-ai.js -
     * the browser narrates exactly this, and the hash of it is what says
     * whether the audio on file still matches the label.
     */
    public static function narrationText(?string $title, ?string $description, ?string $funFacts): string
    {
        $parts = array_filter([trim((string) $title), trim((string) $description), trim((string) $funFacts)]);

        return implode("\n\n", $parts);
    }

    public static function hashFor(?string $title, ?string $description, ?string $funFacts): string
    {
        return sha1(self::narrationText($title, $description, $funFacts));
    }

    /**
     * Does the audio on file still read what the label now says?
     *
     * true  - it does not: the text has been edited since it was narrated.
     * false - it matches.
     * null  - no audio, or it predates this being recorded and its age
     *         cannot be compared (nothing to claim either way).
     */
    public function getAudioStaleAttribute(): ?bool
    {
        if (!$this->audio_file) {
            return null;
        }

        if ($this->audio_text_hash) {
            return $this->audio_text_hash !== self::hashFor($this->title, $this->description, $this->fun_facts);
        }

        // Older rows: the only evidence is which happened last. A minute of
        // slack, because the audio is written moments before the row is saved.
        if ($this->audio_made_at && $this->updated_at) {
            return $this->updated_at->gt($this->audio_made_at->copy()->addMinute());
        }

        return null;
    }

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
