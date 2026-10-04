<?php

namespace App\Models;

use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'user_id', 'user_name', 'role', 'action', 'details', 'ip_address',
    ];

    /** The action as it should read, older request-style rows included. */
    public function getDisplayActionAttribute(): ?string
    {
        return AuditTrail::describe($this->action, $this->details)[0];
    }

    /** The details as they should read, older request-style rows included. */
    public function getDisplayDetailsAttribute(): ?string
    {
        return AuditTrail::describe($this->action, $this->details)[1];
    }
}
