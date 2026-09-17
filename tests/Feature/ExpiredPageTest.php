<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * What happens when a form is submitted with a dead CSRF token.
 *
 * Sessions here end after SESSION_LIFETIME minutes, again when the browser
 * closes, and again whenever an account's password changes. So meeting this
 * is routine and innocent: the login page was left open over lunch. Laravel's
 * stock answer is a blank "419 Page Expired" with nothing to click, which in
 * a municipal office is a phone call rather than a retry.
 *
 * The request still gets refused. It just gets refused somewhere the person
 * can carry on from.
 */
class ExpiredPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CSRF verification is skipped inside the test runner, so posting without
     * a token proves nothing. The handler itself is what is worth pinning, so
     * it is handed the exception directly - the same way the framework would.
     */
    private function render(Request $request, ?Staff $user = null)
    {
        $request->setLaravelSession($this->app['session']->driver());

        // back() resolves through the UrlGenerator's own request, not through
        // whatever object is passed to render(). Without binding it, a
        // synthetic request has no referer to go back to and every redirect
        // collapses to "/" - which would make this suite agree with itself
        // and disagree with the application.
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);

        // Binding 'request' fires Laravel's own auth rebind handler, which
        // replaces the user resolver with one that goes through the auth
        // manager. So who is signed in has to be declared after the bind, or
        // it is silently thrown away and every case looks signed out.
        $request->setUserResolver(fn () => $user);

        return $this->app->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_a_signed_out_visitor_is_sent_back_to_a_usable_login_page(): void
    {
        $request = Request::create('/login', 'POST', [
            'email'    => 'rosa@museobaler.com',
            'password' => 'Talahib-7-Gubat!',
        ]);

        $response = $this->render($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
    }

    public function test_the_email_is_carried_over_but_never_the_password(): void
    {
        // Retyping the address is friction; a password put back into a form
        // by the server is a password sitting in a page it need not be in.
        $request = Request::create('/login', 'POST', [
            'email'    => 'rosa@museobaler.com',
            'password' => 'Talahib-7-Gubat!',
        ]);

        $this->render($request);

        $input = session()->get('_old_input', []);

        $this->assertSame('rosa@museobaler.com', $input['email'] ?? null);
        $this->assertArrayNotHasKey('password', $input);
    }

    public function test_the_reason_is_shown_on_the_login_page(): void
    {
        // The login view renders $errors->first(), so the message has to
        // arrive as an error rather than a plain flash, or it is invisible.
        $request = Request::create('/login', 'POST', ['email' => 'rosa@museobaler.com']);

        $this->render($request);

        $errors = session()->get('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString('session expired', $errors->first('email'));
    }

    public function test_a_signed_in_person_is_returned_to_where_they_were(): void
    {
        // Their session is alive - only the tab was stale - so throwing them
        // out to the login screen would lose work for no reason.
        $staff = Staff::factory()->administrator()->create();

        $request = Request::create('/exhibits', 'POST', ['name' => 'Half-typed exhibit']);
        $request->headers->set('referer', url('/exhibits'));

        $response = $this->render($request, $staff);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/exhibits'), $response->headers->get('Location'));
        $this->assertStringContainsString('open a while', session()->get('error'));
    }

    public function test_the_phone_scanner_is_told_in_json_not_html(): void
    {
        // An HTML redirect reaching the attendance scanner reads as a silent
        // failure; the phone needs a reason it can show.
        $request = Request::create('/my/attendance/scan', 'POST', ['code' => 'MDB-ATT|x|y|z']);
        $request->headers->set('Accept', 'application/json');

        $response = $this->render($request);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('session expired', $response->getContent());
    }
}
