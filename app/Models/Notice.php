<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A notice on the visitor app's board (the `notifications` table).
 *
 * Not the staff bell - that is NotificationController in the panel, built
 * from visitor records. Nothing in the panel writes this table today; it
 * is read by the app and kept for the day someone adds an editor for it.
 */
class Notice extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'notif_id';

    protected $fillable = ['title', 'body', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
