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
        $policy = $this->permissionsPolicyOn('/dashboard');

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

        foreach (['/dashboard', '/exhibits', '/museum'] as $path) {
            $other = (string) $this->withHeader('User-Agent', $laptop)->actingAs($staff)->get($path)
                ->assertOk()->headers->get('Content-Security-Policy');
            $this->assertStringNotContainsString('unsafe-eval', $other, "$path must not allow eval");
        }

        // And the served copy of the library must not need it at all.
        $bundle = file_get_contents(public_path('js/vendor/teachablemachine-image.min.js'));
        $this->assertStringNotContainsString("(0, eval)", $bundle);
        $this->assertStringNotContainsString('new Function(', $bundle);
    }

    /**
     * The visitor app's own policy, which no request in this suite goes
     * through: public/visitor is static, so Apache serves it from a
     * directory-level .htaccess and Laravel's middleware never runs.
     *
     * This exists because the test above reads only
     * teachablemachine-image.min.js and was taken to mean the whole scanner
     * ran without eval. tf.min.js does not: it carries a regenerator shim
     * that builds `Function("return this")` while the model loads. Denying
     * eval there threw an EvalError inside _loadModel(), which swallows it
     * and silently drops to the pHash fallback - image recognition was off
     * in production for anyone who did not open a browser console. Laragon
     * does serve this file's headers, so a local browser can catch it - but
     * only once the old service worker is unregistered, because a worker
     * keeps the policy it was installed with and sw.js itself never changed.
     */
    public function test_the_visitor_app_policy_admits_what_the_model_actually_needs(): void
    {
        $policy = file_get_contents(public_path('visitor/.htaccess'));

        $this->assertStringContainsString("'unsafe-eval'", $policy,
            'tf.min.js evals while loading the model; without this the app falls back to pHash.');

        $this->assertStringContainsString('connect-src', $policy);

        // The premise of the whole test: assert the eval is really in there,
        // so that if a future bundle drops it this fails loudly rather than
        // leaving a permission nobody can justify.
        $tf = file_get_contents(public_path('js/vendor/tf.min.js'));
        $this->assertStringContainsString('Function(', $tf);
    }

    /**
     * The visitor app serves its own fonts, and must keep doing so.
     *
     * This replaces an assertion that connect-src named fonts.googleapis.com.
     * That was the other way out of the same bug: sw.js routes every
     * cross-origin GET through staleWhileRevalidate, which re-issues it as
     * fetch(), and a fetch() from a worker answers to connect-src rather
     * than to the style-src or font-src that let the <link> through. With
     * the Google hosts missing from connect-src, both stylesheets were
     * refused inside the worker and the <link>s got back Response.error().
     *
     * Every Material Icons span carries its glyph name as text content, so
     * the failure rendered as visitors reading "wifi" and "login" across the
     * UI. Young Serif and Instrument Sans died at the same moment but only
     * dropped to system faces, which is why it went unnoticed.
     *
     * Same-origin files sidestep all of it - connect-src 'self' covers them
     * under any policy, including the one an already-installed worker is
     * still holding - so what is pinned here is the absence of the remote
     * hosts and the presence of the files that replaced them.
     */
    public function test_the_visitor_app_serves_its_own_fonts(): void
    {
        $html = file_get_contents(public_path('visitor/index.html'));

        // Comments in this file discuss fonts.googleapis.com on purpose;
        // what must never come back is a tag that fetches from it.
        $this->assertDoesNotMatchRegularExpression(
            '/(?:href|src)\s*=\s*["\']https:\/\/fonts\.(?:googleapis|gstatic)\.com/',
            $html,
            'The visitor app must not fetch fonts from Google: the service worker re-requests '
            .'them through fetch(), which answers to connect-src, and the icon font failing '
            .'leaves every icon rendering as its own glyph name.'
        );

        $css = file_get_contents(public_path('visitor/css/fonts.css'));

        foreach (['Material Icons Round', 'Instrument Sans', 'Young Serif'] as $family) {
            $this->assertStringContainsString("font-family: '{$family}'", $css);
        }

        // The files the stylesheet names, and that sw.js precaches.
        foreach ([
            'material-icons-round',
            'instrument-sans-latin',
            'instrument-sans-latin-ext',
            'young-serif-latin',
            'young-serif-latin-ext',
        ] as $face) {
            $path = public_path("visitor/fonts/{$face}.woff2");
            $this->assertFileExists($path);
            $this->assertSame('wOF2', file_get_contents($path, false, null, 0, 4),
                "{$face}.woff2 is not a woff2 file - a truncated or error-page download.");
        }

        // A worker that does not precache them puts the icons back at the
        // mercy of the network on a first offline load.
        $worker = file_get_contents(public_path('visitor/sw.js'));
        $this->assertStringContainsString('./fonts/material-icons-round.woff2', $worker);
    }

    public function test_the_rest_of_the_headers_still_stand(): void
    {
        $staff    = Staff::factory()->administrator()->create();
        $response = $this->actingAs($staff)->get('/dashboard');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }
}
