<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Exhibit;
use App\Models\Log;
use App\Models\Scan;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function index(Request $request)
    {
        // ── Visitor tab ──────────────────────────────────────
        // Group is needed per row: isCleared() follows the group's payment
        // for members, and the row says which party they came with.
        $query = Visitor::with('group');
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                  ->orWhere('last_name',  'like', "%$search%")
                  ->orWhere('country',    'like', "%$search%");
            });
        }
        if ($vtype = $request->input('vtype')) $query->where('visitor_type', $vtype);
        if ($visit = $request->input('visit')) $query->where('visit_type', $visit);
        // Admission filter — lets the front desk pull up everyone who still
        // owes a fee, or every local whose ID has not been sighted yet.
        match ($request->input('adm')) {
            'pending'     => $query->pendingClearance(),
            'unpaid'      => $query->where('payment_status', 'Unpaid'),
            'paid'        => $query->where('payment_status', 'Paid'),
            'unverified'  => $query->where('visitor_type', 'Local')->where('id_verified', false),
            default       => null,
        };
        match ($request->input('vsort', 'newest')) {
            'oldest' => $query->oldest(),
            'name'   => $query->orderBy('last_name')->orderBy('first_name'),
            default  => $query->orderByDesc('created_at'),
        };
        $visitors = $query->paginate(15)->withQueryString();

        $stats = [
            'total'      => Visitor::count(),
            'local'      => Visitor::where('visitor_type', 'Local')->count(),
            'tourist'    => Visitor::where('visitor_type', 'Tourist')->count(),
            'foreign'    => Visitor::where('visitor_type', 'Foreign')->count(),
            'unpaid'     => Visitor::where('payment_status', 'Unpaid')->count(),
            'unverified' => Visitor::where('visitor_type', 'Local')->where('id_verified', false)->count(),
            // Everyone currently locked out of the visitor app waiting on staff.
            'pending'    => Visitor::pendingClearance()->count(),
        ];

        // ── Scan tab ──────────────────────────────────────────
        $scanStats = Exhibit::with('category')
            ->withCount('scans')
            ->orderByDesc('scans_count')
            ->get();

        // ── Attendance tab ────────────────────────────────────
        $attDate  = $request->input('att_date', today()->toDateString());
        $attQuery = Attendance::with('visitor')->orderByDesc('created_at');
        if ($attDate) $attQuery->whereDate('visit_date', $attDate);
        $attendances  = $attQuery->paginate(30, ['*'], 'att_page')->withQueryString();
        $todayCount   = Attendance::whereDate('visit_date', today())->count();
        $totalAtt     = Attendance::whereNotNull('visitor_id')->count();
        $anonAtt      = Attendance::whereNull('visitor_id')->count();

        $chartLabels = [];
        $chartValues = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $chartLabels[] = $d->format('D, M j');
            $chartValues[] = Attendance::whereDate('visit_date', $d->toDateString())->count();
        }

        // ── Groups tab ────────────────────────────────────────
        // Parties the desk registered as one record. Until this tab existed
        // the only place they appeared was the read-only logbook report, so
        // a group could be registered and could never be marked paid - every
        // tourist party stayed Unpaid forever and the outstanding figure on
        // the daily report grew with money that had in fact been collected.
        $groupQuery = VisitGroup::with('registeredBy')->withCount('visitors');
        if ($gdate = $request->input('gdate')) {
            $groupQuery->whereDate('visit_date', $gdate);
        }
        match ($request->input('gpay')) {
            'unpaid' => $groupQuery->where('payment_status', 'Unpaid'),
            'paid'   => $groupQuery->where('payment_status', 'Paid'),
            'free'   => $groupQuery->where('payment_status', 'Free'),
            default  => null,
        };
        $groups = $groupQuery->orderByDesc('visit_date')->orderByDesc('created_at')
            ->paginate(15, ['*'], 'gpage')->withQueryString();

        $groupStats = [
            'today'       => VisitGroup::whereDate('visit_date', today())->count(),
            'heads_today' => (int) VisitGroup::whereDate('visit_date', today())->sum('headcount'),
            'unpaid'      => VisitGroup::where('payment_status', 'Unpaid')->count(),
            'outstanding' => (float) VisitGroup::where('payment_status', 'Unpaid')->sum('total_fee'),
        ];

        $activeTab = $request->input('tab', 'visitors');

        return view('records.index', compact(
            'visitors', 'stats', 'scanStats',
            'groups', 'groupStats', 'gdate',
            'attendances', 'todayCount', 'totalAtt', 'anonAtt', 'attDate',
            'chartLabels', 'chartValues', 'activeTab'
        ));
    }

    /**
     * Record that a visitor paid the admission fee at the entrance counter.
     * Locals enter free, so there is nothing to collect from them.
     */
    public function markPaid(Visitor $visitor)
    {
        if ($visitor->payment_status === 'Free') {
            return back()->with('error', 'Local visitors are admitted free — there is no fee to collect.');
        }
        if ($visitor->payment_status === 'Paid') {
            return back()->with('error', 'This admission fee is already marked as paid.');
        }

        $visitor->update([
            'payment_status' => 'Paid',
            'paid_at'        => now(),
        ]);

        $fee = number_format((float) $visitor->admission_fee, 2);
        $this->log('Admission Paid', "Collected PHP {$fee} from {$visitor->first_name} {$visitor->last_name}");

        return back()->with('success', 'Admission fee marked as paid.');
    }

    /**
     * Confirm that a local visitor presented a valid proof of residency
     * at the entrance desk, which is what entitles them to free admission.
     */
    public function verifyId(Visitor $visitor)
    {
        if ($visitor->visitor_type !== 'Local') {
            return back()->with('error', 'Only local visitors need an ID checked for free admission.');
        }
        if ($visitor->id_verified) {
            return back()->with('error', 'This ID has already been verified.');
        }

        $visitor->update([
            'id_verified' => true,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ]);

        $this->log('ID Verified', "Verified residency ID for {$visitor->first_name} {$visitor->last_name} ({$visitor->city})");

        return back()->with('success', 'Residency ID verified — free admission granted.');
    }

    private function log(string $action, string $details): void
    {
        Log::create([
            'user_id'    => auth()->id(),
            'user_name'  => auth()->user()->name,
            'role'       => auth()->user()->role ?? 'Administrator',
            'action'     => $action,
            'details'    => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
