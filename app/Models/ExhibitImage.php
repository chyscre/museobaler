<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExhibitImage extends Model
{
    protected $primaryKey = 'image_id';

    protected $fillable = ['exhibit_id', 'filename', 'caption', 'sort_order'];

    public function getUrlAttribute(): string
    {
        // Gallery pictures live beside the main ones in public/images/exhibits/
        if (file_exists(public_path('images/exhibits/' . $this->filename))) {
            return route('exhibit.image', ['filename' => $this->filename]);
        }
        // Uploaded before uploads moved out of Laravel public storage
        return \Illuminate\Support\Facades\Storage::disk('public')->url('exhibits/' . $this->filename);
    }

    public function exhibit()
    {
        return $this->belongsTo(Exhibit::class, 'exhibit_id', 'exhibit_id');
    }
}
