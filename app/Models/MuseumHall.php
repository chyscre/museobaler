<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MuseumHall extends Model
{
    protected $primaryKey = 'hall_id';

    protected $fillable = ['name', 'floor', 'description', 'icon', 'sort_order'];
}
