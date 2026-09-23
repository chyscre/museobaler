<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security headers, and the one that quietly broke attendance.
 *
 * Permissions-Policy shipped as camera=(), geolocation=() - an empty
 * allowlist, meaning nobody, this site included. It was written before staff
 * attendance existed, and attendance needs both: the camera to read the code
 * off the staff-room screen and the position to prove the phone is on the
 * grounds. Every scan failed with "camera access was refused", and the person
 * holding the phone had, in fact, allowed it. The refusal came from here.
 *
 * These pin the header in the shape attendance needs, and the shape nothing
 * else must lose.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function permissionsPolicyOn(string $path): string
    {
        $staff = Staff::factory()->administrator()->create();

        return (string) $this->actingAs($staff)->get($path)
            ->assertOk()
            ->headers->get('Permissions-Policy');
    }

    public function test_the_attendance_page_may_use_the_camera_and_location(): void
    {
        $policy = $this->permissionsPolicyOn('/my/attendance');

        // (self): this origin, and only this origin, may ask. The browser
        // still puts the question to the person.
        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringContainsString('geolocation=(self)', $policy);
    }

    public function test_the_museum_info_page_may_use_location_for_its_pin_button(): void
    {
        $this->assertStringContainsString('geolocation=(self)', $this->permissionsPolicyOn('/museum'));
    }

    public function test_nothing_the_panel_does_not_use_is_allowed(): void
    {
        $policy = $this->permissionsPolicyOn('/');

        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('payment=()', $policy);
    }

    public function test_the_camera_is_never_opened_to_other_origins(): void
    {
        // (self) and not (*) - a page embedded from a CDN, or any third-party
        // frame, must not inherit the museum's right to open a camera.
        $policy = $this->permissionsPolicyOn('/my/attendance');

        $this->assertStringNotContainsString('camera=(*)', $policy);
        $this->assertStringNotContainsString('camera=*', $policy);
    }

    public function test_the_scanner_video_is_allowed_by_the_content_security_policy(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $csp = (string) $this->actingAs($staff)->get('/my/attendance')
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("media-src 'self' blob:", $csp);
        // And the scanner library itself, which comes from unpkg.
        $this->assertStringContainsString('https://unpkg.com', $csp);
    }

    /**
     * The trainer's library opened with an eval, and the policy's refusal of
     * it surfaced as nothing more than "Unavailable" on the Train button.
     * The served copy is patched not to eval; the CDN fallback is upstream
     * and still does, so the one page that runs it allows eval - and only
     * that page.
     */
    public function test_eval_is_allowed_on_the_recognition_page_and_nowhere_else(): void
    {
        $laptop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $staff  = Staff::factory()->administrator()->create();

        $trainer = (string) $this->withHeader('User-Agent', $laptop)->actingAs($staff)->get('/recognition')
            ->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'unsafe-eval'", $trainer);

        foreach (['/', '/exhibits', '/museum'] as $path) {
            $other = (string) $this->withHeader('User-Agent', $laptop)->actingAs($staff)->get($path)
                ->assertOk()->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('unsafe-eval', $other, "$path must not allow eval");
        }

        // And the served copy of the library must not need it at all.
        $bundle = file_get_contents(public_path('js/vendor/teachablemachine-image.min.js'));
        $this->assertStringNotContainsString("(0, eval)", $bundle);
        $this->assertStringNotContainsString('new Function(', $bundle);
    }

    public function test_the_rest_of_the_headers_still_stand(): void
    {
        $staff    = Staff::factory()->administrator()->create();
        $response = $this->actingAs($staff)->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }
}
