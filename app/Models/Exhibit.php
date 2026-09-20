<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exhibit extends Model
{
    protected $primaryKey = 'exhibit_id';

    protected $fillable = [
        'exhibit_code', 'name', 'description', 'fun_facts',
        'category_id', 'hall_id', 'map_x', 'map_y', 'authors',
        'languages', 'source_language', 'storyline_order', 'image', 'qr_file',
        'status', 'date_published',
    ];

    protected function casts(): array
    {
        return [
            'status'          => 'boolean',
            'date_published'  => 'date',
            'storyline_order' => 'integer',
            'map_x'           => 'float',
            'map_y'           => 'float',
        ];
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) return null;
        // Images in public/images/exhibits/ served via proxy route
        if (file_exists(public_path('images/exhibits/' . $this->image))) {
            return route('exhibit.image', ['filename' => $this->image]);
        }
        // Newly uploaded files in Laravel public storage
        return \Illuminate\Support\Facades\Storage::disk('public')->url('exhibits/' . $this->image);
    }

    /**
     * The hall this exhibit sits in. Its name and floor are read through
     * the hall and floor accessors below, so views keep printing
     * $exhibit->hall exactly as they did when those were columns.
     */
    public function museumHall()
    {
        return $this->belongsTo(MuseumHall::class, 'hall_id', 'hall_id');
    }

    public function getHallAttribute(): ?string
    {
        return $this->museumHall?->name;
    }

    public function getFloorAttribute(): ?string
    {
        return $this->museumHall?->floor;
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function translations()
    {
        return $this->hasMany(ExhibitTranslation::class, 'exhibit_id', 'exhibit_id');
    }

    public function images()
    {
        return $this->hasMany(ExhibitImage::class, 'exhibit_id', 'exhibit_id')->orderBy('sort_order');
    }

    public function scans()
    {
        return $this->hasMany(Scan::class, 'exhibit_id', 'exhibit_id');
    }

    public function trainingImages()
    {
        return $this->hasMany(ExhibitTrainingImage::class, 'exhibit_id', 'exhibit_id');
    }
}
