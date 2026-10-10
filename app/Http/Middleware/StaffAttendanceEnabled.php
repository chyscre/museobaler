<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the staff attendance module while config('access.staff_attendance')
 * is off.
 *
 * The routes stay registered rather than being removed, so a route() call
 * left anywhere still builds a link instead of failing the whole page - the
 * link just leads to a 404, as if the page did not exist.
 */
class StaffAttendanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('access.staff_attendance'), 404);

        return $next($request);
    }
}
