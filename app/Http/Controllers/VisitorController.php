<?php

namespace App\Http\Controllers;

use App\Models\AdmissionPayment;
use App\Models\Attendance;
use App\Models\Exhibit;
use App\Models\Log;
use App\Models\Scan;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\DayPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitorController extends Controller
{
    public function index(Request $request)
    {
        // ── Visitor tab ──────────────────────────────────────
        // Group is needed per row: isCleared() follows the group's payment
        // for members, and the row says which party they came with.
        $query = Visitor::with(['group', 'visits.group', 'visits.payment']);
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                  ->orWhere('last_name',  'like', "%$search%")
                  ->orWhere('country',    'like', "%$search%")
                  // Express entries have no name; "senior" finds them.
                  ->orWhere('discount_name', 'like', "%$search%");
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
            'unverified'  => $query->awaitsIdCheck()->where('id_verified', false),
            default       => null,
        };
        $vsort = $request->input('vsort', 'newest');
        match ($vsort) {
            'oldest' => $query->oldest(),
            'name'   => $query->orderBy('last_name')->orderBy('first_name'),
            default  => $query->orderByDesc('created_at'),
        };

        // A page is a day at the museum - the logbook read the way the paper
        // one was, a day at a time. "Oldest first" walks the calendar
        // forwards; the other sorts order within the day.
        //
        // A visitor is on every day they were HERE, not only the day they
        // registered. Paging on created_at alone put a returning visitor on
        // their sign-up day and nowhere else, so today's page left out
        // everyone who had been before - the page looked like today's
        // logbook and was missing half of it. Registration, the last visit,
        // every attendance and every row in their visit history each count
        // as a day they came.
        $visitorDay = DayPage::using(
            $query,
            days: function ($q) {
                $plain = (clone $q)->reorder();

                return collect()
                    ->merge((clone $plain)->selectRaw('DATE(created_at) as day')->distinct()->pluck('day'))
                    ->merge((clone $plain)->whereNotNull('last_visit')->selectRaw('DATE(last_visit) as day')->distinct()->pluck('day'))
                    ->merge(Attendance::whereIn('visitor_id', (clone $plain)->select('visitors.visitor_id'))
                        ->distinct()->pluck('visit_date'))
                    ->merge(Visit::whereIn('visitor_id', (clone $plain)->select('visitors.visitor_id'))
                        ->distinct()->pluck('visit_date'));
            },
            onDay: fn ($q, string $date) => $q->where(fn ($w) => $w
                ->whereDate('created_at', $date)
                ->orWhereDate('last_visit', $date)
                ->orWhereHas('attendances', fn ($a) => $a->whereDate('visit_date', $date))
                ->orWhereHas('visits', fn ($v) => $v->whereDate('visit_date', $date))),
            newestFirst: $vsort !== 'oldest',
        );
        $visitors   = $visitorDay->rows;

        $stats = [
            'total'      => Visitor::count(),
            'local'      => Visitor::where('visitor_type', 'Local')->count(),
            'tourist'    => Visitor::where('visitor_type', 'Tourist')->count(),
            'foreign'    => Visitor::where('visitor_type', 'Foreign')->count(),
            'unpaid'     => Visitor::where('payment_status', 'Unpaid')->count(),
            'unverified' => Visitor::awaitsIdCheck()->where('id_verified', false)->count(),
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
            'visitors', 'visitorDay', 'stats', 'scanStats',
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
        $this->arrivedToday($visitor);

        // Who is free is a property of the person, not of a status left over
        // from an earlier visit. Reading 'Free' here refused a returning
        // visitor whose fee had been the party's on some previous day: the
        // gate wanted today's fee, the button offered to take it, and this
        // line answered that they were a local who owed nothing.
        if ($visitor->visitor_type === 'Local') {
            return $this->answer(false, 'Local visitors are admitted free — there is no fee to collect.');
        }
        if ($visitor->needsIdCheck()) {
            return $this->answer(false, "{$visitor->discount_name} visitors are admitted free — verify their ID instead.");
        }
        // "Already paid" means paid for THIS visit. The fee falls due again
        // on a later day, so a flag left over from an earlier visit must not
        // stop the desk collecting today's - that refusal would leave a
        // visitor the gate treats as unpaid with no way to become paid.
        if ($visitor->payment_status === 'Paid' && $visitor->paid_at?->isToday()) {
            return $this->answer(false, 'This admission fee is already marked as paid for today.');
        }

        // The row says this visit is paid; the ledger keeps the payment after
        // the row is reset for the next visit.
        $payment = DB::transaction(function () use ($visitor) {
            $visitor->update([
                'payment_status' => 'Paid',
                'paid_at'        => now(),
            ]);

            return AdmissionPayment::forVisitor($visitor, auth()->id());
        });

        $fee = number_format((float) $visitor->admission_fee, 2);
        $this->log('Admission Paid', "Collected PHP {$fee} from {$visitor->full_name} ({$payment->reference})");

        return $this->answer(true, "Admission fee marked as paid — transaction {$payment->reference}.");
    }

    /**
     * How the desk actions reply. A form gets the page back with the message;
     * the buttons on a notification ask for JSON, because a redirect followed
     * in the background spends the message on a page nobody sees.
     */
    private function answer(bool $ok, string $message)
    {
        if (request()->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /**
     * Staff acting on a visitor at the counter means they are here today.
     *
     * If the row still describes an earlier visit, start today's first -
     * the same step the app takes on sign-in - so the fee is today's price
     * and what happens next is recorded in today's visit, not written over
     * the last one.
     */
    private function arrivedToday(Visitor $visitor): void
    {
        if (!$visitor->last_visit?->isToday()) {
            $visitor->touchReturning();
        }
    }

    /**
     * Confirm that a visitor presented the document that entitles them to
     * free admission: a local's proof of residency, or the proof for a free
     * category (a senior citizen ID, a PWD ID).
     */
    public function verifyId(Visitor $visitor)
    {
        $this->arrivedToday($visitor);

        if (!$visitor->needsIdCheck()) {
            return $this->answer(false, 'Only visitors admitted free need an ID checked.');
        }
        // Same reasoning as markPaid(): the ID is sighted per visit, so a
        // check done on an earlier day is history, not today's clearance.
        if ($visitor->id_verified && $visitor->verified_at?->isToday()) {
            return $this->answer(false, 'This ID has already been verified today.');
        }

        $visitor->update([
            'id_verified' => true,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ]);

        $what = $visitor->visitor_type === 'Local' ? 'residency ID' : "{$visitor->discount_name} ID";
        $this->log('ID Verified', "Verified {$what} for {$visitor->full_name} ({$visitor->city})");

        return $this->answer(true, ucfirst($what) . ' verified — free admission granted.');
    }

    /**
     * Take back today's admission: the fee was marked paid on the wrong
     * row, an ID turned out not to say what it was claimed to, or someone
     * is being asked to leave.
     *
     * Puts the visitor back where they stood before the desk acted - waiting
     * for payment or for an ID check - and ends their app session at once,
     * so the museum's content is locked on their phone now rather than when
     * the next request happens to be refused. Money they paid on their own
     * today goes into the ledger as a refund, the same way a group correction
     * does, so the day's takings do not keep counting it.
     *
     * Only today's admission is revoked. A past visit is history, and a group
     * member's entry is the party's - one member cannot be un-paid on their
     * own, so that is a correction on the group.
     */
    public function revoke(Request $request, Visitor $visitor)
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $reason = trim((string) $request->input('reason'));

        if ($visitor->isWithGroupToday()) {
            return back()->with('error', "{$visitor->full_name} came in with {$visitor->group->label}, whose admission covers them. Correct the group on the Groups tab instead.");
        }
        if ($visitor->clearance() !== 'cleared') {
            return back()->with('error', 'This visitor has not been let in today, so there is nothing to revoke.');
        }

        $refund = DB::transaction(function () use ($visitor) {
            $refund = null;

            if ($visitor->needsIdCheck()) {
                $visitor->update(['id_verified' => false, 'verified_at' => null, 'verified_by' => null]);
            } else {
                $paid = AdmissionPayment::netForVisitorOn($visitor, today());
                $visitor->update(['payment_status' => 'Unpaid', 'paid_at' => null]);
                if ($paid > 0) {
                    $refund = AdmissionPayment::refundForVisitor($visitor, $paid, auth()->id());
                }
            }

            // SECURITY: the gate re-checks clearance on every request, but a
            // live token would still let the app sit on cached content. Ending
            // the session sends them back to sign-in and then to the waiting
            // screen, which is where they now stand.
            $visitor->revokeToken();

            return $refund;
        });

        $details = "Revoked today's entry for {$visitor->full_name}";
        if ($refund) {
            $details .= ' — refunded PHP ' . number_format((float) $refund->amount, 2) . " ({$refund->reference})";
        }
        $this->log('Admission Revoked', $details . ($reason !== '' ? " · Reason: {$reason}" : ''));

        return back()->with('success', $refund
            ? 'Entry revoked. Hand back PHP ' . number_format((float) $refund->amount, 2) . " — recorded as refund {$refund->reference}."
            : 'Entry revoked. They are signed out of the app and back to waiting at the desk.');
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
