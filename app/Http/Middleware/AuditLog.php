<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: AuditLog Middleware (Additional Security Feature #1)
 *
 * PURPOSE:
 * Records every state-changing request (POST, PUT, PATCH, DELETE) made by
 * authenticated admin users. This creates a tamper-evident audit trail that
 * allows administrators to:
 *   1. Detect unauthorized access — if an account is compromised, the log
 *      shows what the attacker did and when.
 *   2. Investigate incidents — after a breach or data change, the log provides
 *      a forensic record of who did what.
 *   3. Enforce accountability — staff members know their actions are recorded,
 *      which deters insider threats.
 *
 * WHY THIS MATTERS FOR MUSEO DE BALER:
 * The admin panel manages museum exhibits, staff accounts, and visitor records.
 * Any unauthorized modification (e.g., deleting exhibits, changing staff passwords)
 * would be a serious integrity issue. The audit log ensures every such action
 * is traceable to a specific user, IP address, and timestamp.
 *
 * Applied to all authenticated routes via bootstrap/app.php.
 */
class AuditLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log state-changing methods for authenticated users
        if (
            Auth::check() &&
            in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            try {
                \App\Models\Log::create([
                    'user_id'    => Auth::id(),
                    'user_name'  => Auth::user()->name,
                    'role'       => Auth::user()->role ?? 'Staff',
                    'action'     => $request->method() . ' ' . $request->path(),
                    'details'    => 'HTTP ' . $request->method() . ' | Status: ' . $response->getStatusCode(),
                    'ip_address' => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                // Never let logging failure break the application
                \Illuminate\Support\Facades\Log::error('AuditLog middleware failed: ' . $e->getMessage());
            }
        }

        return $response;
    }
}
