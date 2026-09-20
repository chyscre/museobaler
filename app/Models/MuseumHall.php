<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MuseumHall extends Model
{
    protected $primaryKey = 'hall_id';

    /** The two floors the building has; the floor map keys on these exact labels. */
    public const FLOORS = ['Ground Floor', '2nd Floor'];

    protected $fillable = ['name', 'floor', 'description', 'icon', 'sort_order'];

    public function exhibits()
    {
        return $this->hasMany(Exhibit::class, 'hall_id', 'hall_id');
    }
}
