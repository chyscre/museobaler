<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $primaryKey = 'category_id';

    protected $fillable = ['name', 'description'];

    public function exhibits()
    {
        return $this->hasMany(Exhibit::class, 'category_id', 'category_id');
    }
}
