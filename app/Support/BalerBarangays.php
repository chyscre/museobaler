<?php

namespace App\Support;

/**
 * The thirteen barangays of the municipality of Baler, Aurora.
 *
 * Free admission is for Baler residents only, so a local names one of these
 * rather than a town. The visitor app carries the same list in its own
 * markup (public/visitor/index.html); the visitor API validates against
 * this class (RegisterVisitorRequest). Keep the two in step.
 */
class BalerBarangays
{
    public const ALL = [
        'Barangay I (Poblacion)',
        'Barangay II (Poblacion)',
        'Barangay III (Poblacion)',
        'Barangay IV (Poblacion)',
        'Barangay V (Poblacion)',
        'Buhangin',
        'Calabuanan',
        'Obligacion',
        'Pingit',
        'Reserva',
        'Sabang',
        'Suklayin',
        'Zabali',
    ];

    public static function isOne(?string $name): bool
    {
        return $name !== null && in_array($name, self::ALL, true);
    }
}
