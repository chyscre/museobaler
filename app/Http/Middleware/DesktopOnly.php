<?php

namespace App\Http\Middleware;

use App\Support\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the admin panel on the machines it was built for.
 *
 * The panel is a desktop tool and the stylesheet says so: one breakpoint in
 * the whole of resources/css/app.css, and all it does is shrink the sidebar
 * to icons. The exhibit editor, the front desk register, the records tables
 * and every report are laid out for a monitor. Nobody is writing an exhibit
 * label on a phone, and letting them try just produces a broken screen and a
 * support call.
 *
 * The exceptions need a camera, and are exceptions by design. Clocking in
 * requires scanning the rotating code on the staff-room screen, which needs a
 * camera, which means a phone - see StaffAttendanceController::scan(), where
 * the QR proves the phone is looking at the museum's screen, the session
 * proves who is holding it, and the geofence proves it is on the grounds. All
 * three legs are needed, so the phone session has to exist.
 *
 * So the phone session is kept and narrowed to what it is for. A staff phone
 * is a clock-in device; the panel stays on the office computer.
 *
 * NOTE: this is a fit-for-purpose guard, not a security boundary. A
 * user-agent string is trivially changed, and nothing here is relied on to
 * keep anybody away from anything - EnsureRole does that, on every route,
 * regardless of device.
 */
class DesktopOnly
{
    /**
     * What a phone is allowed to reach.
     *
     * Attendance because it needs the camera. The password routes because a
     * newly-issued account may well be handed over and first signed into on
     * a phone standing in the staff room, and RequirePasswordChange would
     * otherwise bounce it somewhere this middleware bounces it back from.
     * The recognition photo pages for the same reason as attendance: the
     * photos the model learns from are taken standing in front of the
     * exhibit, and the camera is in the staff member's pocket. The training
     * panel itself stays on the desk.
     */
    private const PHONE_ROUTES = [
        'my.attendance',
        'my.attendance.scan',
        'my.attendance.pin',
        'recognition.photos',
        'recognition.photos.upload',
        'recognition.background',
        'recognition.background.upload',
        'recognition.photo.destroy',
        'recognition.photos.remove',
        'recognition.background.remove',
        'password.edit',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('access.desktop_only', true)) {
            return $next($request);
        }

        if (!Device::isPhone($request->userAgent())) {
            return $next($request);
        }

        if ($request->routeIs(self::PHONE_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'message' => 'The admin panel is only available on a computer.',
            ], 403);
        }

        $user = $request->user();

        // Museum staff have somewhere to be sent. The Tourism office does not
        // clock in, so there is no phone screen that belongs to them at all -
        // they get told plainly instead of being redirected in a circle.
        if ($user && !$user->isTourismHead()) {
            return redirect()->route('my.attendance')
                ->with('error', 'The admin panel is only available on a computer. On your phone you can check in and out.');
        }

        return response()->view('errors.desktop-only', [], 403);
    }

}
