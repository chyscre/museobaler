<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: SecurityHeaders Middleware
 *
 * Adds HTTP security headers to every response from the admin panel.
 * These headers instruct the browser to enforce security policies that
 * protect against XSS, clickjacking, MIME sniffing, and information leakage.
 *
 * Applied globally via bootstrap/app.php.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // SECURITY: the one response this panel allows to be framed.
        //
        // The report screens preview a PDF in an <iframe> before anyone
        // commits to downloading it, and `frame-ancestors 'none'` forbids
        // that even from this same origin - so without this the preview pane
        // is a blank box.
        //
        // The exemption is deliberately two conditions, not one. It is only
        // the preview routes, and only when what came back is actually a
        // PDF, so an HTML preview fragment - which could carry markup built
        // from stored data - is still unframable. And it relaxes to 'self'
        // rather than removing the restriction: another site still cannot
        // frame it.
        //
        // A rendered PDF is not a clickjacking target in the way an admin
        // page is: clickjacking needs the framed page to perform a
        // privileged action on a click, and a PDF viewer performs none.
        $framable = $request->routeIs('reports.preview', 'reports.audit.preview')
            && str_starts_with((string) $response->headers->get('Content-Type'), 'application/pdf');

        // SECURITY: Content-Security-Policy
        // Restricts which sources the browser may load scripts, styles, images, etc. from.
        // 'self' means only from the same origin. This is the primary XSS mitigation at the HTTP layer.
        // NOTE: 'unsafe-inline' remains in script-src/style-src because the
        // admin views currently rely on ~85 inline onclick/onchange handlers
        // and inline <script> blocks. Removing it requires migrating those to
        // addEventListener + a per-request nonce (nonces make browsers ignore
        // 'unsafe-inline' entirely, so that migration must happen in one pass
        // and be verified in a real browser, not blind). Tracked as follow-up.
        // cdnjs.cloudflare.com was removed below - nothing references it.
        // 'unsafe-eval' is off everywhere but one page: the recognition
        // trainer. The Teachable Machine library it runs opens with an eval
        // (seedrandom's global lookup), which this policy turned into a
        // silent "Unavailable" on the Train button. The copy served from
        // public/js/vendor/ is patched not to need it; the CDN fallback is
        // the upstream file and still does, so that one page allows it.
        $eval = $request->routeIs('recognition.index') ? " 'unsafe-eval'" : '';
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline'$eval https://cdn.jsdelivr.net https://unpkg.com; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "img-src 'self' data: blob:; " .
            // The attendance scanner shows the camera in a <video>. Modern
            // browsers attach the stream directly and CSP never sees it, but
            // html5-qrcode's fallback for older ones goes through a blob: URL
            // on the element, which default-src 'self' would refuse.
            "media-src 'self' blob:; " .
            // The recognition trainer's libraries and MobileNet base are
            // served from public/js/vendor/, so training works without
            // internet; jsDelivr and storage.googleapis.com stay listed as
            // the trainer's fallback when the local copies do not load (see
            // recognition/index.blade.php). unpkg is for Lucide icons.
            //
            // http://localhost and http://127.0.0.1 used to be here. They
            // were a development convenience that shipped, and on a server
            // they widen the policy for nobody's benefit: a visitor's
            // browser resolving "localhost" means the visitor's own machine,
            // never ours.
            "connect-src 'self' https://unpkg.com https://cdn.jsdelivr.net https://storage.googleapis.com; " .
            "frame-ancestors " . ($framable ? "'self'" : "'none'") . ';'
        );

        // SECURITY: X-Frame-Options
        // Prevents the admin panel from being embedded in an <iframe> on another site.
        // This stops clickjacking attacks where an attacker overlays a transparent iframe
        // over a legitimate page to trick users into clicking malicious elements.
        //
        // The report preview is the one exception, and it is narrow: SAMEORIGIN,
        // not ALLOWALL. See $framable above for why a PDF preview is not a
        // clickjacking target.
        $response->headers->set('X-Frame-Options', $framable ? 'SAMEORIGIN' : 'DENY');

        // SECURITY: X-Content-Type-Options
        // Prevents the browser from MIME-sniffing a response away from the declared Content-Type.
        // Without this, a browser might execute a file uploaded as an image if it contains script content.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // SECURITY: Referrer-Policy
        // Controls how much referrer information is sent with requests.
        // 'strict-origin-when-cross-origin' sends the full URL for same-origin requests
        // but only the origin for cross-origin, preventing leaking admin URLs to third parties.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // SECURITY: Permissions-Policy
        // Disables browser features this application does not use, and scopes
        // the ones it does use to this origin only.
        //
        // This used to be camera=(), geolocation=() - an empty allowlist,
        // meaning nobody, the site itself included. That was written before
        // staff attendance existed, and attendance needs both: the camera to
        // read the rotating code off the staff-room screen, and the position
        // to prove the phone is on the grounds. With the empty lists in place
        // getUserMedia threw NotAllowedError no matter what the person had
        // tapped, so "camera access was refused" was true and the fix was
        // never in their settings. The museum-info screen's "use my current
        // location" button needs geolocation for the same reason.
        //
        // (self) keeps the protective intent: a third-party frame - which
        // frame-ancestors 'none' already forbids - could not use them, and
        // nothing embedded from a CDN can either. Only pages served from
        // this origin may ask, and the browser still asks the person.
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=()');

        // SECURITY: Remove X-Powered-By
        // Hides the PHP version from response headers, reducing information leakage
        // that attackers use to target known vulnerabilities in specific PHP versions.
        header_remove('X-Powered-By');

        return $response;
    }
}
