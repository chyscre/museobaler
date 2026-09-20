<?php
/**
 * The thirteen barangays of Baler - the plain-PHP twin of
 * App\Support\BalerBarangays for the visitor API, which does not boot
 * Laravel. Free admission is for Baler residents only, so a local must name
 * one of these. Keep in step with the Laravel class and the visitor app.
 */
const BALER_BARANGAYS = [
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

function isBalerBarangay(?string $name): bool
{
    return $name !== null && in_array($name, BALER_BARANGAYS, true);
}
