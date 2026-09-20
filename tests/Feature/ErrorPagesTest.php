<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The error screens say what happened, in the museum's own words, and never
 * what the framework would have said.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_gets_the_custom_404(): void
    {
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('That page is not here')
            ->assertSee('Back to the panel');
    }

    public function test_a_role_denial_gets_the_custom_403_with_a_way_home(): void
    {
        // Museum staff reaching for the Tourism-only staff list.
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/staff')
            ->assertForbidden()
            ->assertSee('Not part of your role')
            ->assertSee('Back to your home screen');
    }

    public function test_a_server_error_never_shows_a_stack_trace(): void
    {
        config(['app.debug' => false]);

        Route::get('/_boom', fn () => throw new \RuntimeException('secret detail /var/www/app.php'));

        $response = $this->get('/_boom');

        $response->assertStatus(500)
            ->assertSee('Something went wrong on our side')
            ->assertDontSee('secret detail')
            ->assertDontSee('/var/www/app.php');
    }

    public function test_the_health_endpoint_is_still_plain(): void
    {
        // deploy.sh polls /up before switching releases; it must not be
        // caught by anything above.
        $this->get('/up')->assertOk();
    }
}
