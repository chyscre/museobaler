<?php

namespace App\Support;

/**
 * The eight municipalities of Aurora province.
 *
 * Used when free admission is set to cover every Aurora resident (see
 * App\Support\Admission): a local then names their town rather than a Baler
 * barangay. The visitor app reads this list from GET /api/v1/museum.
 */
class AuroraTowns
{
    public const ALL = [
        'Baler',
        'Casiguran',
        'Dilasag',
        'Dinalungan',
        'Dingalan',
        'Dipaculao',
        'Maria Aurora',
        'San Luis',
    ];

    public static function isOne(?string $name): bool
    {
        return $name !== null && in_array($name, self::ALL, true);
    }
}
