<?php

namespace App\Services;

use App\Models\MuseumInfo;

/**
 * Distance check against the museum's configured location.
 *
 * This is the second half of the staff attendance guarantee. The rotating
 * code proves the phone saw the screen recently; the geofence proves the
 * phone is actually at the museum, which is what kills relaying a still-fresh
 * code to somebody sitting at home.
 *
 * Reuses the same museum_info coordinates the visitor PWA already uses, so
 * there is one place to correct if the pin is off.
 */
class GeofenceService
{
    /**
     * Readings vaguer than this are refused. A phone reporting +/- 500m has
     * not actually established that its owner is on the grounds.
     */
    public const MAX_ACCEPTABLE_ACCURACY_M = 100;

    public function museum(): ?MuseumInfo
    {
        return MuseumInfo::first();
    }

    /**
     * Whether the distance check actually runs.
     *
     * Off only when ATTENDANCE_GEOFENCE=false AND this is not production. A
     * development laptop is not in Baler, and the alternative - repointing
     * the museum's real pin at whoever is testing - is how the real
     * coordinates end up wrong on the day it matters. Production cannot turn
     * this off: the setting is simply not consulted there.
     */
    public function enforced(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return (bool) config('access.geofence', true);
    }

    /** Metres between two coordinates, via the haversine formula. */
    public function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Returns ['ok' => bool, 'distance' => ?int, 'reason' => ?string].
     *
     * When the museum has no coordinates saved yet the check passes with a
     * null distance: refusing every check-in because an admin has not filled
     * in the map pin would leave staff unable to clock in at all.
     */
    public function check(?float $lat, ?float $lng, ?int $accuracy): array
    {
        $museum = $this->museum();

        // Switched off for testing (never in production - see enforced()).
        // The distance is still worked out when the phone sent a position,
        // so the row records how far off-site the test scan really was
        // rather than pretending it was at the door.
        if (!$this->enforced()) {
            $distance = null;

            if ($museum && $museum->latitude !== null && $museum->longitude !== null && $lat !== null && $lng !== null) {
                $distance = (int) round($this->distance(
                    (float) $museum->latitude, (float) $museum->longitude, $lat, $lng
                ));
            }

            return ['ok' => true, 'distance' => $distance, 'reason' => null, 'skipped' => true];
        }

        if (!$museum || $museum->latitude === null || $museum->longitude === null) {
            return ['ok' => true, 'distance' => null, 'reason' => null];
        }

        if ($lat === null || $lng === null) {
            return [
                'ok'       => false,
                'distance' => null,
                'reason'   => 'Location is required. Allow location access and try again.',
            ];
        }

        if ($accuracy !== null && $accuracy > self::MAX_ACCEPTABLE_ACCURACY_M) {
            return [
                'ok'       => false,
                'distance' => null,
                'reason'   => 'Your location is too imprecise to confirm. Step outside or wait for a better GPS fix.',
            ];
        }

        $distance = (int) round($this->distance(
            (float) $museum->latitude, (float) $museum->longitude, $lat, $lng
        ));

        $radius = (int) ($museum->geofence_radius_m ?: 150);

        if ($distance > $radius) {
            return [
                'ok'       => false,
                'distance' => $distance,
                'reason'   => 'You must be at the museum to record attendance.',
            ];
        }

        return ['ok' => true, 'distance' => $distance, 'reason' => null];
    }
}
