<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'user_id', 'user_name', 'role', 'action', 'details', 'ip_address',
    ];
}
