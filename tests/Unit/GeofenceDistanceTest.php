<?php

namespace Tests\Unit;

use App\Services\GeofenceService;
use PHPUnit\Framework\TestCase;

/**
 * The haversine maths on its own, with no database and no museum row.
 *
 * GeofenceService::check() is covered end to end in the feature tests, but the
 * distance formula underneath it is worth pinning separately: it is the number
 * that decides whether a staff member can clock in, and a sign error or a
 * degrees/radians slip would still produce plausible-looking metres.
 */
class GeofenceDistanceTest extends TestCase
{
    private GeofenceService $geofence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geofence = new GeofenceService;
    }

    public function test_the_same_point_is_zero_metres_away(): void
    {
        $this->assertSame(0.0, $this->geofence->distance(15.7594, 121.5631, 15.7594, 121.5631));
    }

    /**
     * One degree of latitude is a fixed arc: 6371000 * pi / 180 = 111194.9 m.
     * Any implementation that mixes up degrees and radians misses this wildly.
     */
    public function test_one_degree_of_latitude_is_about_111_kilometres(): void
    {
        $this->assertEqualsWithDelta(111194.9, $this->geofence->distance(0.0, 0.0, 1.0, 0.0), 1.0);
    }

    /**
     * At the museum's latitude a thousandth of a degree north is roughly 111 m,
     * which is the scale the geofence actually operates at - the default radius
     * is 150 m.
     */
    public function test_a_short_hop_at_the_museum_resolves_to_metres_not_kilometres(): void
    {
        $metres = $this->geofence->distance(15.7594, 121.5631, 15.7604, 121.5631);

        $this->assertEqualsWithDelta(111.2, $metres, 0.5);
    }

    /**
     * Longitude degrees shrink with the cosine of the latitude. At 15.76 deg N
     * that factor is about 0.9624, so the same step east is shorter than north.
     */
    public function test_longitude_degrees_shrink_away_from_the_equator(): void
    {
        $north = $this->geofence->distance(15.7594, 121.5631, 15.7604, 121.5631);
        $east  = $this->geofence->distance(15.7594, 121.5631, 15.7594, 121.5641);

        $this->assertLessThan($north, $east);
        $this->assertEqualsWithDelta(0.9624, $east / $north, 0.001);
    }

    public function test_distance_is_the_same_measured_either_way(): void
    {
        $there = $this->geofence->distance(15.7594, 121.5631, 15.8000, 121.6000);
        $back  = $this->geofence->distance(15.8000, 121.6000, 15.7594, 121.5631);

        $this->assertEqualsWithDelta($there, $back, 0.0001);
    }

    /**
     * The accuracy ceiling is a published constant rather than a literal buried
     * in check(), because the refusal message quotes it back to the visitor.
     */
    public function test_the_accuracy_ceiling_is_one_hundred_metres(): void
    {
        $this->assertSame(100, GeofenceService::MAX_ACCEPTABLE_ACCURACY_M);
    }
}
