<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $primaryKey = 'visitor_id';

    protected $fillable = [
        'first_name', 'last_name', 'middle_name',
        'age', 'sex', 'visitor_type', 'visit_type',
        'city', 'province', 'country', 'email', 'auth_provider',
        'group_id', 'source', 'registered_by',
        'admission_fee', 'payment_status', 'paid_at',
        'id_verified', 'verified_at', 'verified_by',
        'last_visit',
    ];

    /**
     * SECURITY: never serialise the credential columns. The visitor API sets
     * them directly; nothing in the admin panel should ever read them back,
     * and this stops one leaking through a JSON response or a debug dump.
     */
    protected $hidden = ['password', 'api_token'];

    protected function casts(): array
    {
        return [
            'last_visit'       => 'datetime',
            'paid_at'          => 'datetime',
            'verified_at'      => 'datetime',
            'token_expires_at' => 'datetime',
            'id_verified'      => 'boolean',
            'admission_fee'    => 'decimal:2',
            'password'         => 'hashed',
        ];
    }

    /**
     * Baler locals enter free (subject to showing a valid ID); everyone else
     * pays the flat fee set on the Museum Info page.
     */
    public static function feeFor(string $visitorType): float
    {
        return $visitorType === 'Local' ? 0.00 : MuseumInfo::admissionFee();
    }

    /**
     * Whether the front desk has cleared this visitor to enter the museum app.
     * Locals need a sighted residency ID; everyone else needs the fee paid.
     * Mirrors visitorClearance() in public/api/_visitor_auth.php.
     */
    public function isCleared(): bool
    {
        return $this->visitor_type === 'Local'
            ? (bool) $this->id_verified
            : $this->payment_status === 'Paid';
    }

    /** What the visitor is still waiting on, in words staff can act on. */
    public function clearanceLabel(): string
    {
        if ($this->isCleared()) return 'Cleared';

        return $this->visitor_type === 'Local'
            ? 'Waiting for ID check'
            : 'Waiting for payment';
    }

    /** Visitors currently blocked at the entrance, newest first. */
    public function scopePendingClearance($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($local) {
                $local->where('visitor_type', 'Local')->where('id_verified', false);
            })->orWhere(function ($paying) {
                $paying->where('visitor_type', '!=', 'Local')->where('payment_status', '!=', 'Paid');
            });
        });
    }

    public function scans()
    {
        return $this->hasMany(Scan::class, 'visitor_id', 'visitor_id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'visitor_id', 'visitor_id');
    }

    public function group()
    {
        return $this->belongsTo(VisitGroup::class, 'group_id', 'group_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(Staff::class, 'registered_by', 'staff_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
