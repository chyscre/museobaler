<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Staff extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Two roles, because there are only two kinds of person here.
     *
     * TourismHead is the head of the Municipal Tourism Office, which the
     * museum sits under: oversight from off-site — the staff, their
     * attendance, the records, the reports. Not a superadmin: there is no
     * system configuration for anyone to hold, and the museum's own settings
     * belong to the people in the building.
     *
     * Administrator is museum staff — a handful of people who all do
     * everything, so there is nothing below it worth splitting out.
     */
    public const ROLE_TOURISM = 'TourismHead';
    public const ROLE_ADMIN   = 'Administrator';

    public const ROLES = [
        self::ROLE_TOURISM => 'Tourism Head',
        self::ROLE_ADMIN   => 'Museum Staff',
    ];

    public function getRouteKeyName()
    {
        return 'staff_id';
    }

    protected $primaryKey = 'staff_id';

    protected $fillable = [
        'name', 'email', 'password', 'role', 'status',
        'must_change_password', 'password_changed_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'             => 'hashed',
            'status'               => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at'  => 'datetime',
        ];
    }

    /**
     * True while this account is still using a password somebody else chose.
     *
     * Tourism issues a generated password and hands it over; until the staff
     * member replaces it, two people know the credential and the audit log
     * cannot say which of them acted. RequirePasswordChange keeps the account
     * penned in on the change-password screen until this clears.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    public function isTourismHead(): bool
    {
        return $this->role === self::ROLE_TOURISM;
    }

    /**
     * Every signed-in account is museum staff or the Tourism office, so this
     * is true for everyone today. It stays a named check rather than being
     * inlined as `true`, so adding a narrower role later has one obvious
     * place to be excluded instead of silently inheriting the whole panel.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_TOURISM, self::ROLE_ADMIN], true);
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'user_id', 'staff_id');
    }

    public function attendances()
    {
        return $this->hasMany(StaffAttendance::class, 'staff_id', 'staff_id');
    }

    public function schedules()
    {
        return $this->hasMany(StaffSchedule::class, 'staff_id', 'staff_id');
    }

    public function tours()
    {
        return $this->hasMany(Tour::class, 'guide_staff_id', 'staff_id');
    }
}
