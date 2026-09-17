<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The entrance desk.
 *
 * This is what replaces the paper logbook, so the design constraint is speed
 * rather than completeness. The book was fast because the VISITOR wrote in
 * it while the desk served the next person - two things happening at once. A
 * form the desk staff has to type for every arrival is strictly slower than
 * the book, and a system slower than the book gets abandoned within a week.
 *
 * The museum has one computer and no counter tablet, so there are two ways
 * in, and only the second has the desk typing:
 *
 *   app   the visitor registers on their own phone, prompted by the printed
 *         entrance poster this controller generates - they fill it in while
 *         they queue, which is the parallelism the paper book had
 *   desk  staff type it for someone who cannot
 *
 * Both write the same visitors rows; only the source differs. The `kiosk`
 * source still exists on older rows from when a counter tablet was supported.
 */
class DeskController extends Controller
{
    /** Fields beyond these are optional - a longer form than the book loses. */
    private const REQUIRED_RULES = [
        'first_name'   => 'required|string|max:100',
        'last_name'    => 'required|string|max:100',
        'visitor_type' => 'required|in:Local,Tourist,Foreign',
    ];

    private const OPTIONAL_RULES = [
        'middle_name' => 'nullable|string|max:100',
        'age'         => 'nullable|integer|min:1|max:120',
        'sex'         => 'nullable|in:Male,Female,Other,Prefer not to say',
        'city'        => 'nullable|string|max:100',
        'province'    => 'nullable|string|max:100',
        'country'     => 'nullable|string|max:100',
        'email'       => 'nullable|email|max:150',
    ];

    // -- Desk-assisted registration ---------------------------------------

    public function create()
    {
        return view('desk.register', [
            'todayCount' => Visitor::whereDate('created_at', today())->count(),
            'todayHeads' => $this->headcountToday(),
            'recent'     => Visitor::whereDate('created_at', today())
                                ->latest('created_at')->limit(8)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::REQUIRED_RULES + self::OPTIONAL_RULES + [
            'visit_type' => 'nullable|in:Solo,Group,School,Family,Walk-in',
        ]);

        $visitor = $this->createVisitor($data, 'desk', auth()->id());

        $this->log('Visitor Registered',
            "Registered {$visitor->full_name} ({$visitor->visitor_type}) at the desk");

        return redirect()->route('desk.register')
            ->with('success', "{$visitor->full_name} registered." . $this->feeHint($visitor));
    }

    /**
     * A party arriving together - the museum's most common arrival is four
     * or five people from another province, and making each of them fill in
     * a separate form is exactly the slowdown that sends staff back to paper.
     * One record, one headcount, one payment.
     */
    public function storeGroup(Request $request)
    {
        $data = $request->validate([
            'group_name'    => 'nullable|string|max:150',
            'group_type'    => 'required|in:Family,Group,School,Tour',
            'contact_name'  => 'required|string|max:150',
            'contact_phone' => 'nullable|string|max:40',
            'visitor_type'  => 'required|in:Local,Tourist,Foreign',
            'headcount'     => 'required|integer|min:1|max:500',
            'paying_count'  => 'nullable|integer|min:0|max:500',
            'city'          => 'nullable|string|max:100',
            'province'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'notes'         => 'nullable|string|max:500',
        ]);

        // Locals enter free; everyone else pays per head unless the desk says
        // otherwise (a mixed party of locals and out-of-town relatives).
        $payingCount = $data['visitor_type'] === 'Local'
            ? 0
            : (int) ($data['paying_count'] ?? $data['headcount']);

        if ($payingCount > $data['headcount']) {
            return back()->withInput()->with('error', 'Paying count cannot exceed the headcount.');
        }

        $fee = VisitGroup::feeFor($data['visitor_type'], $payingCount);

        $group = VisitGroup::create($data + [
            'paying_count'   => $payingCount,
            'total_fee'      => $fee,
            'payment_status' => $fee > 0 ? 'Unpaid' : 'Free',
            'visit_date'     => today(),
            'registered_by'  => auth()->id(),
        ]);

        $this->log('Group Registered',
            "Registered group '{$group->contact_name}' ({$group->headcount} pax, {$group->visitor_type})");

        $message = "Group of {$group->headcount} registered.";
        if ($fee > 0) {
            $message .= ' Collect PHP ' . number_format($fee, 2) . " for {$payingCount} paying.";
        }

        return redirect()->route('desk.register')->with('success', $message);
    }

    public function markGroupPaid(VisitGroup $group)
    {
        if ($group->payment_status === 'Paid') {
            return back()->with('error', 'That group is already marked paid.');
        }

        $group->update(['payment_status' => 'Paid', 'paid_at' => now()]);

        $this->log('Group Payment',
            "Collected PHP " . number_format((float) $group->total_fee, 2) . " from group '{$group->contact_name}'");

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * A printable "Register here" poster for the entrance.
     *
     * Without a counter tablet there are only two ways a visitor gets into
     * the system: their own phone, or the desk typing for them. Typing is
     * serial and slower than the paper book it replaced, so the poster is
     * what keeps the desk from becoming the bottleneck — visitors scan it
     * and fill the form in themselves while they queue.
     */
    public function poster()
    {
        // Built from the live request rather than a hardcoded path, so the
        // same poster works on localhost, the LAN IP and a tunnel URL.
        //
        // index.html directly, not the directory or index.php: index.php only
        // exists to stash a ?scan= code before bouncing to index.html, and a
        // bare directory URL would leave which one loads up to Apache's
        // DirectoryIndex order.
        $registerUrl = url('/visitor/index.html');

        require_once public_path('phpqrcode/phpqrcode.php');

        ob_start();
        \QRcode::png($registerUrl, false, QR_ECLEVEL_H, 12, 2);
        $png = base64_encode(ob_get_clean());

        return view('desk.poster', [
            'registerUrl' => $registerUrl,
            'qr'          => 'data:image/png;base64,' . $png,
            'museum'      => \App\Models\MuseumInfo::first(),
        ]);
    }

    // -- Shared -----------------------------------------------------------

    private function createVisitor(array $data, string $source, ?int $staffId, ?int $groupId = null): Visitor
    {
        $fee = Visitor::feeFor($data['visitor_type']);

        return DB::transaction(fn () => Visitor::create($data + [
            'visit_type'    => $data['visit_type'] ?? ($groupId ? 'Group' : 'Walk-in'),
            'country'       => $data['country'] ?? 'Philippines',
            'auth_provider' => 'manual',
            'source'        => $source,
            'registered_by' => $staffId,
            'group_id'      => $groupId,
            'admission_fee' => $groupId ? 0 : $fee,
            // A local owes nothing but still has to show proof of residency,
            // which is a separate check the desk makes in Records.
            'payment_status'=> $groupId ? 'Free' : ($fee > 0 ? 'Unpaid' : 'Free'),
            'id_verified'   => false,
            'last_visit'    => now(),
        ]));
    }

    private function feeHint(Visitor $visitor): string
    {
        if ($visitor->visitor_type === 'Local') {
            return ' Local - check their ID, no fee.';
        }

        return ' Collect PHP ' . number_format((float) $visitor->admission_fee, 2) . '.';
    }

    /** Individual walk-ins plus every head counted in a group today. */
    private function headcountToday(): int
    {
        $solo = Visitor::whereDate('created_at', today())->whereNull('group_id')->count();
        $grouped = (int) VisitGroup::whereDate('visit_date', today())->sum('headcount');

        return $solo + $grouped;
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
