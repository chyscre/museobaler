<?php

namespace Tests\Feature\Api;

use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two endpoints the app reads before anyone has signed in, and the
 * headers every API answer carries.
 */
class PublicEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_museum_returns_info_halls_and_the_generated_admission_line(): void
    {
        MuseumInfo::create(['name' => 'Museo de Baler', 'admission_fee' => 75, 'latitude' => 15.76, 'longitude' => 121.56, 'geofence_radius_m' => 150]);
        MuseumHall::create(['name' => 'Hall B', 'floor' => 'Ground Floor', 'sort_order' => 2]);
        MuseumHall::create(['name' => 'Hall A', 'floor' => 'Ground Floor', 'sort_order' => 1]);

        $res = $this->getJson('/api/v1/museum')->assertOk();

        $res->assertJsonPath('info.name', 'Museo de Baler')
            ->assertJsonPath('info.admission_fee', 75)
            ->assertJsonPath('info.admission', 'Baler residents enter free with a valid ID · Visitors ₱75.00')
            ->assertJsonPath('halls.0.name', 'Hall A')
            ->assertJsonPath('halls.1.name', 'Hall B');
    }

    public function test_the_visitor_geofence_is_the_servers_call(): void
    {
        MuseumInfo::create(['name' => 'Museo de Baler']);

        config(['access.visitor_geofence' => false]);
        $this->getJson('/api/v1/museum')->assertJsonPath('info.geofence_enforced', false);

        config(['access.visitor_geofence' => true]);
        $this->getJson('/api/v1/museum')->assertJsonPath('info.geofence_enforced', true);
    }

    public function test_an_empty_install_still_answers(): void
    {
        $this->getJson('/api/v1/museum')
            ->assertOk()
            ->assertJsonPath('info.admission_fee', 50)
            ->assertJsonPath('halls', []);
    }

    public function test_notifications_lists_active_notices_newest_first(): void
    {
        Notice::create(['title' => 'Old', 'body' => 'b', 'type' => 'info', 'created_at' => now()->subDay()]);
        Notice::create(['title' => 'Hidden', 'body' => 'b', 'type' => 'info', 'is_active' => false]);
        Notice::create(['title' => 'New', 'body' => 'b', 'type' => 'alert']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.title', 'New')
            ->assertJsonPath('1.title', 'Old')
            ->assertJsonMissing(['title' => 'Hidden']);
    }

    public function test_every_api_answer_carries_the_hardening_headers(): void
    {
        $res = $this->getJson('/api/v1/notifications');

        $res->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Including the answer a probe is most likely to collect.
     *
     * The rate limiter ends a request before the rest of the stack runs, and
     * the header middleware used to be appended - so a 429 came back with no
     * nosniff, no CORP, the panel's Referrer-Policy instead of the API's, and
     * PHP's X-Powered-By still attached. Sixty requests is all it took to be
     * handed the one response that had none of the hardening on it.
     */
    public function test_a_throttled_answer_carries_them_too(): void
    {
        // The limiter's own ceiling, from AppServiceProvider.
        for ($i = 0; $i < 61; $i++) {
            $res = $this->getJson('/api/v1/notifications');

            if ($res->status() === 429) {
                $res->assertHeader('X-Content-Type-Options', 'nosniff')
                    ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
                    ->assertHeader('Referrer-Policy', 'no-referrer')
                    ->assertHeader('Retry-After')
                    ->assertHeaderMissing('X-Powered-By');

                return;
            }
        }

        $this->fail('the limiter never fired, so nothing was verified');
    }

    public function test_an_unlisted_origin_gets_no_cors_headers(): void
    {
        config(['cors.allowed_origins' => ['https://app.example', 'https://kiosk.example']]);

        $this->getJson('/api/v1/notifications', ['Origin' => 'https://evil.example'])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/api/v1/notifications', ['Origin' => 'https://app.example'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example');
    }

    public function test_the_api_is_json_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/no-such-thing')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
