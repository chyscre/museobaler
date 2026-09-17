<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\Tour;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Guided visits.
 *
 * Most people at Museo de Baler walk around on their own, so this stays a
 * small, occasional record rather than a core flow. A guide gets assigned in
 * three situations: a visitor asks for one, a foreign tourist arrives, or a
 * school has booked an educational tour.
 *
 * The point of recording it is not to rank guides - the volume is far too low
 * for a rating to mean anything. It is so the Tourism office can see how many
 * guided tours actually happened, and so the visitor app knows whether to ask
 * the "how was your guide" question at all.
 */
class TourController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : today()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))
            : today();

        $tours = Tour::with(['guide', 'visitor', 'group'])
            ->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString();

        return view('tours.index', [
            'tours'  => $tours,
            'from'   => $from,
            'to'     => $to,
            'active' => Tour::with(['guide', 'visitor', 'group'])->whereNull('ended_at')->get(),
            'guides' => $this->availableGuides(),
            'todayVisitors' => Visitor::whereDate('created_at', today())
                                  ->whereNull('group_id')->latest('created_at')->limit(30)->get(),
            'todayGroups'   => VisitGroup::whereDate('visit_date', today())->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'guide_staff_id' => 'required|exists:staff,staff_id',
            'tour_type'      => 'required|in:Requested,Foreign,Educational',
            'subject'        => 'required|string',   // "visitor:12" or "group:4"
            'headcount'      => 'nullable|integer|min:1|max:500',
            'notes'          => 'nullable|string|max:500',
        ]);

        $guide = Staff::findOrFail($data['guide_staff_id']);

        // Assigning a guide who has not checked in today would put a tour on
        // someone who is not at the museum, and the Tourism office reads both
        // numbers side by side.
        $isPresent = StaffAttendance::where('staff_id', $guide->staff_id)
            ->whereDate('work_date', today())
            ->where('type', 'in')
            ->exists();

        if (!$isPresent) {
            return back()->with('error', "{$guide->name} has not checked in today.");
        }

        [$kind, $id] = array_pad(explode(':', $data['subject'], 2), 2, null);

        $visitorId = $kind === 'visitor' ? (int) $id : null;
        $groupId   = $kind === 'group'   ? (int) $id : null;

        if (!$visitorId && !$groupId) {
            return back()->with('error', 'Choose who the tour is for.');
        }

        $headcount = $data['headcount']
            ?? ($groupId ? (int) VisitGroup::find($groupId)?->headcount : 1);

        $tour = Tour::create([
            'guide_staff_id' => $guide->staff_id,
            'tour_type'      => $data['tour_type'],
            'visitor_id'     => $visitorId,
            'group_id'       => $groupId,
            'headcount'      => max(1, (int) $headcount),
            'started_at'     => now(),
            'notes'          => $data['notes'] ?? null,
            'created_by'     => auth()->id(),
        ]);

        $this->log('Tour Started', "Assigned {$guide->name} to a {$tour->tour_type} tour ({$tour->headcount} pax)");

        return back()->with('success', "{$guide->name} assigned.");
    }

    public function end(Tour $tour)
    {
        if ($tour->ended_at) {
            return back()->with('error', 'That tour is already finished.');
        }

        $tour->update(['ended_at' => now()]);

        $this->log('Tour Ended', "Ended tour #{$tour->tour_id} ({$tour->guide?->name})");

        return back()->with('success', 'Tour ended.');
    }

    /**
     * Staff who are actually on site right now — checked in and not yet out.
     *
     * Any museum staff member can guide; there is no separate guide post at a
     * museum this size. The Tourism office is excluded because they work from
     * their own office and are never the one walking a group round.
     */
    private function availableGuides()
    {
        $checkedIn = StaffAttendance::whereDate('work_date', today())
            ->where('type', 'in')
            ->pluck('staff_id');

        $checkedOut = StaffAttendance::whereDate('work_date', today())
            ->where('type', 'out')
            ->pluck('staff_id');

        return Staff::where('status', true)
            ->where('role', Staff::ROLE_ADMIN)
            ->whereIn('staff_id', $checkedIn->diff($checkedOut))
            ->orderBy('name')
            ->get();
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
