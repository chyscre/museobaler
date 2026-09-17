<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\Log;
use App\Models\Staff;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Manual attendance, with the two people it takes to create it.
 *
 * A phone dies, GPS refuses to fix, the kiosk is off - attendance still has
 * to be recordable or staff simply stop using the system. But whoever can
 * type attendance in unsupervised can also type their own, which defeats the
 * point of the Tourism office watching it.
 *
 * So it is split: the museum Administrator files the request (they were on
 * site and can vouch for the person), the Tourism office approves it (they
 * hold the oversight and were not). The attendance row only exists once
 * approved, and it carries both names.
 */
class AttendanceCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'Pending');

        $query = AttendanceCorrection::with(['staff', 'requestedBy', 'reviewedBy'])
            ->orderByDesc('requested_at');

        if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
            $query->where('status', $status);
        }

        return view('staff-attendance.corrections', [
            'corrections' => $query->paginate(25)->withQueryString(),
            'status'      => $status,
            'pendingCount'=> AttendanceCorrection::where('status', 'Pending')->count(),
            // Only museum staff can have an attendance record corrected.
            'staffList'   => Staff::where('status', true)->where('role', Staff::ROLE_ADMIN)->orderBy('name')->get(),
        ]);
    }

    /** Filed by the museum Administrator. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'staff_id'       => 'required|exists:staff,staff_id',
            'work_date'      => 'required|date|before_or_equal:today',
            'type'           => 'required|in:in,out',
            'requested_time' => 'required|date_format:H:i',
            'reason'         => 'required|string|min:10|max:500',
        ]);

        // Nobody files their own attendance, no matter their role - that is
        // the whole separation this workflow exists to enforce.
        if ((int) $data['staff_id'] === (int) auth()->id()) {
            return back()->with('error', 'You cannot file a correction for your own attendance. Ask the Tourism office.');
        }

        $exists = StaffAttendance::where('staff_id', $data['staff_id'])
            ->whereDate('work_date', $data['work_date'])
            ->where('type', $data['type'])
            ->exists();

        if ($exists) {
            return back()->with('error', 'That staff member already has a check-' . $data['type'] . ' on that date.');
        }

        $duplicate = AttendanceCorrection::where('staff_id', $data['staff_id'])
            ->whereDate('work_date', $data['work_date'])
            ->where('type', $data['type'])
            ->where('status', 'Pending')
            ->exists();

        if ($duplicate) {
            return back()->with('error', 'A pending request for that date already exists.');
        }

        $correction = AttendanceCorrection::create($data + [
            'requested_by' => auth()->id(),
            'requested_at' => now(),
            'status'       => 'Pending',
        ]);

        $this->log('Correction Filed',
            "Filed attendance correction for {$correction->staff->name} on {$correction->work_date->toDateString()} ({$data['type']})");

        return back()->with('success', 'Correction filed. It takes effect once the Tourism office approves it.');
    }

    /** Reviewed by the Tourism office. */
    public function review(Request $request, AttendanceCorrection $correction)
    {
        $data = $request->validate([
            'decision'    => 'required|in:Approved,Rejected',
            'review_note' => 'nullable|string|max:500',
        ]);

        if ($correction->status !== 'Pending') {
            return back()->with('error', 'That request has already been reviewed.');
        }

        // The filer cannot also approve. Without this the two-person rule is
        // just a longer form for the same single person.
        if ((int) $correction->requested_by === (int) auth()->id()) {
            return back()->with('error', 'You filed this request, so you cannot approve it.');
        }

        $correction->update([
            'status'      => $data['decision'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        if ($data['decision'] === 'Approved') {
            StaffAttendance::updateOrCreate(
                [
                    'staff_id'  => $correction->staff_id,
                    'work_date' => $correction->work_date->toDateString(),
                    'type'      => $correction->type,
                ],
                [
                    'scanned_at'  => Carbon::parse(
                        $correction->work_date->toDateString() . ' ' . $correction->requested_time
                    ),
                    'method'      => 'manual',
                    'recorded_by' => auth()->id(),
                    'ip_address'  => $request->ip(),
                ]
            );
        }

        $this->log('Correction ' . $data['decision'],
            "{$data['decision']} attendance correction for {$correction->staff->name} on {$correction->work_date->toDateString()}");

        return back()->with('success', "Correction {$data['decision']}.");
    }

    private function log(string $action, string $details): void
    {
        Log::create([
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'role'       => auth()->user()->role,
            'action'     => $action,
            'details'    => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
