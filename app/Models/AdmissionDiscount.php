<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of visitor who pays less, or nothing: senior citizens, PWDs,
 * children under a certain age. Set up by an administrator on the Museum
 * Info page (see App\Support\Admission for how it is applied).
 *
 *   proof        what the desk asks to see - "Senior citizen ID"
 *   percent_off  100 is free; 20 is the statutory senior/PWD discount
 *   min_age /    optional. When set, the claim is only accepted from a
 *   max_age      visitor whose stated age falls inside the range.
 *
 * Residents of Baler (or Aurora) are not a row here. Being a local is the
 * visitor type, and it changes what the form asks - a barangay or a town -
 * as well as the price.
 */
class AdmissionDiscount extends Model
{
    protected $primaryKey = 'discount_id';

    protected $fillable = [
        'name', 'proof', 'percent_off', 'min_age', 'max_age', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'min_age'     => 'integer',
            'max_age'     => 'integer',
            'active'      => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The desk and the API resolve the categories once per request; a
        // change has to be seen by the next one.
        static::saved(fn () => MuseumInfo::forgetAdmissionFee());
        static::deleted(fn () => MuseumInfo::forgetAdmissionFee());
    }

    public function isFree(): bool
    {
        return $this->percent_off >= 100;
    }

    /** Whether a visitor of this age may claim it. No range, anyone may. */
    public function fitsAge(?int $age): bool
    {
        if ($this->min_age === null && $this->max_age === null) {
            return true;
        }
        if ($age === null) {
            return false;
        }

        return ($this->min_age === null || $age >= $this->min_age)
            && ($this->max_age === null || $age <= $this->max_age);
    }

    /** "Free", "20% off". */
    public function getBenefitAttribute(): string
    {
        return $this->isFree() ? 'Free' : $this->percent_off . '% off';
    }

    /** "60 and over", "7 and under", "13–17", or null with no range. */
    public function getAgeRangeAttribute(): ?string
    {
        return match (true) {
            $this->min_age !== null && $this->max_age !== null => "{$this->min_age}–{$this->max_age}",
            $this->min_age !== null => "{$this->min_age} and over",
            $this->max_age !== null => "{$this->max_age} and under",
            default => null,
        };
    }

    /** What the visitor app and the desk script need, and nothing else. */
    public function toPublicArray(): array
    {
        return [
            'id'          => $this->discount_id,
            'name'        => $this->name,
            'proof'       => $this->proof,
            'percent_off' => $this->percent_off,
            'min_age'     => $this->min_age,
            'max_age'     => $this->max_age,
            'benefit'     => $this->benefit,
            'age_range'   => $this->age_range,
        ];
    }
}
