<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The geofence's own record of who walked in and when they left.
 *
 * SECURITY: who a request is about is whoever the bearer token resolves
 * to, and a visitor_id in the body is only accepted when it says the same
 * thing. No token means an anonymous arrival - the fence detected a phone
 * that has not registered yet - which is a legitimate record and stays
 * one. That is why these routes are not behind visitor.auth: the token is
 * read when present and ignored when not, never required.
 */
class AttendanceController extends Controller
{
    /** POST /api/v1/attendance - the phone crossed into the fence. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id'   => ['nullable', 'integer'],
            'visitor_name' => ['nullable', 'string', 'max:255'],
            'latitude'     => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'    => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy'     => ['nullable', 'integer', 'min:0'],
        ]);

        $visitor = $this->ownVisitor($request);

        // One row per visitor per day: reopening the app mid-visit is the
        // same visit, and the app carries on with the id it is handed.
        if ($visitor !== null) {
            $existing = Attendance::query()
                ->where('visitor_id', $visitor->visitor_id)
                ->whereDate('visit_date', today())
                ->value('attendance_id');

            if ($existing !== null) {
                return response()->json(['ok' => true, 'already_logged' => true, 'attendance_id' => (int) $existing]);
            }
        }

        try {
            $row = Attendance::create([
                'visitor_id'   => $visitor?->visitor_id,
                'visitor_name' => $visitor?->full_name ?? trim((string) ($data['visitor_name'] ?? '')),
                'latitude'     => $data['latitude'] ?? null,
                'longitude'    => $data['longitude'] ?? null,
                'accuracy'     => $data['accuracy'] ?? null,
                'method'       => 'geofence',
                'visit_date'   => today(),
            ]);
        } catch (QueryException $e) {
            // Another request for this visitor arrived between the check above
            // and this insert - the app open on a second device, or reopened
            // while the first call was still in flight. The unique key on
            // (visitor_id, visit_date) is what keeps the visitor count honest,
            // so it stays; what must not happen is the loser of that race
            // being handed a server error when, from the visitor's side,
            // nothing went wrong at all. They are checked in.
            $existing = $visitor === null ? null : Attendance::query()
                ->where('visitor_id', $visitor->visitor_id)
                ->whereDate('visit_date', today())
                ->value('attendance_id');

            if ($existing === null) {
                throw $e;   // Not the race - a real database problem.
            }

            return response()->json(['ok' => true, 'already_logged' => true, 'attendance_id' => (int) $existing]);
        }

        return response()->json(['ok' => true, 'already_logged' => false, 'attendance_id' => (int) $row->attendance_id]);
    }

    /**
     * PATCH /api/v1/attendance/{id} - an exit, or a registration claiming
     * the anonymous row the fence made earlier.
     *
     * Somebody else's record is answered as a no-op rather than an error:
     * the app treats this call as fire-and-forget, and a 403 would tell a
     * probe which attendance ids are taken.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'event'         => ['nullable', 'in:claim,exit'],
            'duration_mins' => ['nullable', 'integer', 'min:0'],
            'visitor_id'    => ['nullable', 'integer'],
            'visitor_name'  => ['nullable', 'string', 'max:255'],
        ]);

        $visitor = $this->ownVisitor($request);
        $row     = Attendance::find($id);

        if ($row === null || ($row->visitor_id !== null && $row->visitor_id !== $visitor?->visitor_id)) {
            return response()->json(['ok' => true, 'claimed' => false]);
        }

        if (($data['event'] ?? 'claim') === 'exit') {
            // The app cannot watch location once it is backgrounded, so
            // instead of one exit at the end it marks the visitor as still
            // present every time it is hidden. This runs several times per
            // visit with a growing duration, and exited_at is really "last
            // confirmed on site" until the visit genuinely ends. Keeping the
            // longest duration stops a late fragment - the app reopened in
            // the car park - from truncating a two-hour visit to two minutes.
            $row->exited_at = now();
            if (isset($data['duration_mins'])) {
                $row->duration_mins = max((int) $row->duration_mins, (int) $data['duration_mins']);
            }
            $row->save();

            return response()->json(['ok' => true]);
        }

        // Claim: only today's anonymous rows, only by a signed-in visitor.
        if ($visitor === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if ($row->visitor_id !== null || !$row->visit_date->isToday()) {
            return response()->json(['ok' => true, 'claimed' => false]);
        }

        // `method` is left alone: it records how the arrival was detected.
        // Claiming used to overwrite it with 'registered', which the
        // attendance screen read as "not geofence" and labelled Manual.
        $row->visitor_id   = $visitor->visitor_id;
        $row->visitor_name = trim((string) ($data['visitor_name'] ?? '')) ?: $visitor->full_name;
        $row->save();

        return response()->json(['ok' => true, 'claimed' => true]);
    }

    /**
     * The visitor this caller may act as: the token's, provided the body
     * does not claim to be somebody else. Nobody, otherwise.
     */
    private function ownVisitor(Request $request): ?Visitor
    {
        $visitor = Visitor::findByToken($request->bearerToken());
        $claimed = (int) $request->input('visitor_id', 0);

        if ($visitor === null || ($claimed > 0 && $claimed !== (int) $visitor->visitor_id)) {
            return null;
        }

        return $visitor;
    }
}
