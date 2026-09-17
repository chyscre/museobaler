<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// SECURITY: proxies trusted to send X-Forwarded-* headers.
//
// Two different Cloudflare architectures need two different trust targets:
//
// 1. `cloudflared tunnel --url ...` (what this app currently uses for
//    testing): the tunnel daemon runs on THIS machine, receives the public
//    connection from Cloudflare's edge, then forwards it to Laravel over
//    localhost. Confirmed via /debug-proxy: Laravel sees remote_addr ::1,
//    NOT a Cloudflare datacenter IP — because cloudflared, not Cloudflare's
//    edge, is what actually connects to the origin. So loopback must be
//    trusted, not Cloudflare's public ranges.
// 2. A real production setup with Cloudflare proxying directly to a public
//    origin server would have Cloudflare's edge IPs as the direct
//    connection — kept below in case this app is ever deployed that way.
//    Refresh from https://www.cloudflare.com/ips/ if that's in use and
//    requests start being treated as untrusted again.
//
// Deliberately NOT trusting '*' (all proxies): that would let anyone hit
// the origin directly over the internet and spoof X-Forwarded-For, which
// would defeat LoginRateLimiter's per-IP tracking. Loopback is safe to
// trust unconditionally — only a process already running on this machine
// can present as connecting from 127.0.0.1/::1, an external attacker over
// the network cannot spoof that.
//
// A plain variable, not a const: this file is re-required on every test's
// fresh application boot (Tests\TestCase re-runs bootstrap/app.php per
// test), and PHP constants can't be redefined within the same process —
// a `const` here throws "already defined" on the second test.
$trustedProxies = [
    '127.0.0.1', '::1', // cloudflared / any local tunnel daemon
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($trustedProxies): void {
        // SECURITY: Apply security headers to every web response
        //
        // AuthenticateSession binds a session to the password it was opened
        // with, which is what gives PasswordController's logoutOtherDevices()
        // call any effect. Without it, a temporary password that Tourism
        // handed over could still be sitting in an open session somewhere
        // after the staff member had replaced it.
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\AuditLog::class,
        ]);

        // SECURITY: Register named middleware aliases for use in routes
        $middleware->alias([
            'login.throttle'  => \App\Http\Middleware\LoginRateLimiter::class,
            'role'            => \App\Http\Middleware\EnsureRole::class,
            'password.rotate' => \App\Http\Middleware\RequirePasswordChange::class,
            'desktop'         => \App\Http\Middleware\DesktopOnly::class,
        ]);

        // Trust Cloudflare as a reverse proxy — fixes scheme/cookie handling
        // (e.g. "Page Expired" on login) when accessed through a Cloudflare
        // Tunnel, without trusting arbitrary forwarded headers from anyone
        // who connects directly to the origin.
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // "419 Page Expired" is a dead end, and almost nobody who meets it
        // has done anything wrong.
        //
        // A CSRF token lives and dies with its session, and this system ends
        // sessions after SESSION_LIFETIME minutes and again when the browser
        // closes. Changing an account's password ends its sessions too, now
        // that AuthenticateSession is in the stack. So the ordinary way to
        // land here is to leave the login page open over lunch and then use
        // it - and Laravel's default answer to that is a blank error page
        // with no way forward, which in a municipal office is a phone call.
        //
        // None of this weakens the check: the request is still refused. It
        // just refuses it somewhere the person can carry on from.
        //
        // Typed on the HTTP exception rather than on TokenMismatchException:
        // Handler::prepareException() has already rewritten it into a 419
        // HttpException by the time render callbacks are consulted, so a
        // callback hinting the original type never matches. The original is
        // still on ->getPrevious(), which is what distinguishes a real CSRF
        // failure from a hand-written abort(419).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || !$e->getPrevious() instanceof TokenMismatchException) {
                return null; // not ours — let it render the usual way
            }

            // The attendance scan posts JSON from a phone. An HTML redirect
            // there reads as a silent failure rather than a reason.
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Your session expired. Reload the page and scan again.',
                ], 419);
            }

            // Session still alive, just a stale tab: put them back where they
            // were rather than throwing away where they had got to.
            if ($request->user()) {
                return back()
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token']))
                    ->with('error', 'That page had been open a while, so the form was refused. Please try again.');
            }

            // The email is worth carrying over; the password never is.
            return redirect()->route('login')
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Your session expired while that page was open. Please sign in again.']);
        });
    })->create();
