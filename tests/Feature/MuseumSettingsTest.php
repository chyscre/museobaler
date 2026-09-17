<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for MuseumController::update(), which previously called
 * MuseumInfo::firstOrCreate(['id' => 1]) against a table whose primary key
 * is info_id — every save attempt would throw. Also covers the new
 * admin-editable geofence fields (latitude/longitude/geofence_radius_m),
 * which replaced hardcoded constants in the visitor app's JS.
 */
class MuseumSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_save_museum_info_including_geofence(): void
    {
        $admin = Staff::factory()->administrator()->create();

        $response = $this->actingAs($admin)->post('/museum', [
            'name'              => 'Museo de Baler',
            'admission_fee'     => 50,
            'latitude'          => 15.7604405,
            'longitude'         => 121.5616958,
            'geofence_radius_m' => 200,
        ]);

        $response->assertRedirect(route('museum.index'));

        $info = MuseumInfo::where('info_id', 1)->first();
        $this->assertNotNull($info, 'MuseumInfo row was not created/updated — the info_id lookup broke again.');
        $this->assertSame('Museo de Baler', $info->name);
        $this->assertEquals(15.7604405, (float) $info->latitude);
        $this->assertEquals(121.5616958, (float) $info->longitude);
        $this->assertSame(200, $info->geofence_radius_m);
    }

    public function test_geofence_radius_out_of_range_is_rejected(): void
    {
        $admin = Staff::factory()->administrator()->create();

        $response = $this->actingAs($admin)->post('/museum', [
            'geofence_radius_m' => 5000, // over the 2000m max
        ]);

        $response->assertSessionHasErrors('geofence_radius_m');
    }
}
