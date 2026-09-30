<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\AdmissionDiscount;
use App\Support\Admission;
use App\Models\VisitGroup;
use App\Services\Qr;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        'barangay'    => 'nullable|string|max:100',
        'province'    => 'nullable|string|max:100',
        'country'     => 'nullable|string|max:100',
        'email'       => 'nullable|email|max:150',
        'discount_id' => 'nullable|integer',
    ];

    // -- Desk-assisted registration ---------------------------------------

    public function create()
    {
        return view('desk.register', [
            // What the forms ask a local for, and the discounts they offer.
            'admission'    => Admission::forClients(),
            'todayCount'   => Visitor::whereDate('created_at', today())->count(),
            'todayHeads'   => $this->headcountToday(),
            'recent'       => Visitor::with('group')->whereDate('created_at', today())
                                  ->latest('created_at')->limit(8)->get(),
            // Today's groups sit above the individuals: the desk needs the
            // join code in front of it while the party is still standing
            // there, and needs Mark Paid the moment the money changes hands.
            'recentGroups' => VisitGroup::withCount('visitors')
                                  ->whereDate('visit_date', today())
                                  ->latest('created_at')->limit(8)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::REQUIRED_RULES + self::OPTIONAL_RULES + [
            'visit_type' => 'nullable|in:Solo,Group,School,Family,Walk-in',
        ]);

        $discount = null;
        if ($data['visitor_type'] !== 'Local' && !empty($data['discount_id'])) {
            $discount = Admission::discount($data['discount_id']);
            if ($error = Admission::refusal($discount, $data['age'] ?? null)) {
                return back()->withInput()->withErrors(['discount_id' => $error]);
            }
        }

        $visitor = $this->createVisitor($data, $discount, 'desk', auth()->id());

        $this->log('Visitor Registered',
            "Registered {$visitor->full_name} ({$visitor->visitor_type}"
            . ($discount ? ", {$discount->name}" : '') . ') at the desk');

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
            'local_count'   => 'nullable|integer|min:0|max:500',
            // Heads per discount category, keyed by its id. Ids that are not
            // an active category are ignored rather than refused: the form
            // only offers active ones, so a stale id is a page left open.
            'discounts'     => 'nullable|array',
            'discounts.*'   => 'nullable|integer|min:0|max:500',
            // Still accepted for anything that posts the old shape; the form
            // no longer asks it. "Paying heads" is not a question a party
            // can answer at a counter - "anyone from Baler?" is.
            'paying_count'  => 'nullable|integer|min:0|max:500',
            'city'          => 'nullable|string|max:100',
            'province'      => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'notes'         => 'nullable|string|max:500',
        ]);

        $headcount = (int) $data['headcount'];

        // Locals enter free. The desk says how many of the party are from
        // Baler (or Aurora) and the paying count follows; a Local group is
        // all of them.
        if ($data['visitor_type'] === 'Local') {
            $localCount = $headcount;
        } elseif (isset($data['local_count'])) {
            $localCount = (int) $data['local_count'];
        } elseif (isset($data['paying_count'])) {
            $localCount = $headcount - (int) $data['paying_count'];
        } else {
            $localCount = 0;
        }

        $place     = Admission::residentPlace();
        $discounts = $data['visitor_type'] === 'Local' ? [] : Admission::groupDiscounts($data['discounts'] ?? []);
        $price     = Admission::groupPrice($data['visitor_type'], $headcount, $localCount, $discounts);

        if ($localCount > $headcount || $localCount < 0) {
            return back()->withInput()->with('error', "The number from {$place} cannot exceed the headcount.");
        }
        if ($price['over']) {
            return back()->withInput()->with('error', 'The locals and discounted visitors add up to more than the headcount.');
        }

        $payingCount = $price['paying'];
        $fee         = $price['fee'];

        // array_merge, not `$data + [...]`: with the union operator the FORM
        // wins on any key present in both. The browser always posts the
        // "Paying heads" box, and left blank - which is the default - it
        // arrives as null, which then beat the computed count and MySQL
        // refused the row. The values worked out above must be the ones
        // that are written.
        $group = VisitGroup::create(array_merge(Arr::except($data, ['discounts']), [
            // The code the members type into the app to be counted as part of
            // this party rather than as a sixth visitor owing a second fee.
            'join_code'      => VisitGroup::freshJoinCode(),
            'local_count'    => $localCount,
            'discounts'      => $discounts ?: null,
            'paying_count'   => $payingCount,
            'total_fee'      => $fee,
            'payment_status' => $fee > 0 ? 'Unpaid' : 'Free',
            'visit_date'     => today(),
            'registered_by'  => auth()->id(),
        ]));

        $this->log('Group Registered',
            "Registered group '{$group->contact_name}' ({$group->headcount} pax, {$localCount} local"
            . ($group->discount_summary ? ", {$group->discount_summary}" : '')
            . ", {$group->visitor_type}, code {$group->join_code})");

        $message = "Group of {$group->headcount} registered.";
        if ($fee > 0) {
            $message .= ' Collect PHP ' . number_format($fee, 2) . " for {$payingCount} paying.";
        }
        if ($localCount > 0 && $data['visitor_type'] !== 'Local') {
            $message .= " Check {$localCount} {$place} " . ($localCount === 1 ? 'ID' : 'IDs') . '.';
        }
        foreach ($discounts as $d) {
            $message .= " Check {$d['count']} × " . self::proofFor($d['id'], $d['name']) . '.';
        }

        // Flashed separately so the view can set it in large type: this is
        // the thing the desk reads out to the party.
        return redirect()->route('desk.register')
            ->with('success', $message)
            ->with('join_code', ['code' => $group->join_code, 'label' => $group->label]);
    }

    /**
     * A local turned up in a party that was charged for everyone - or the
     * other way round.
     *
     * The desk changes how many of the party are from Baler; the model
     * re-prices the group and settles the difference. If the group had
     * already paid and the fee drops, the money goes back across the counter
     * and is recorded as a refund, so the logbook stops counting it as
     * revenue. No second signature: the person is standing there waiting for
     * their ₱50, and the audit entry is the record.
     */
    public function correctGroup(Request $request, VisitGroup $group)
    {
        $data = $request->validate([
            'local_count' => 'required|integer|min:0|max:500',
        ]);

        if ((int) $data['local_count'] > (int) $group->headcount - $group->discountedCount()) {
            return back()->with('error', $group->discountedCount() > 0
                ? 'The locals and discounted visitors cannot add up to more than the headcount.'
                : 'The number from ' . Admission::residentPlace() . ' cannot exceed the headcount.');
        }

        if (!$group->visit_date->isToday()) {
            // Yesterday's money has been counted and banked. Correcting it
            // from the desk would quietly change a report somebody has
            // already read; that is a conversation with the Tourism office.
            return back()->with('error', 'Only today\'s groups can be corrected at the desk.');
        }

        $before = $group->local_count;
        $change = $group->correctLocals((int) $data['local_count'], auth()->id());

        $summary = "{$group->label}: {$before} → {$group->local_count} from " . Admission::residentPlace() . ', fee PHP '
                 . number_format($change['was_fee'], 2) . ' → PHP ' . number_format($change['fee'], 2);

        if ($change['refund'] > 0) {
            $this->log('Group Refund', $summary . ' — refunded PHP ' . number_format($change['refund'], 2));

            return back()->with('success',
                'Corrected. Hand back PHP ' . number_format($change['refund'], 2) . " to {$group->contact_name} — it is recorded as a refund.");
        }

        $this->log('Group Corrected', $summary . ($change['owed'] > 0 ? ' — PHP ' . number_format($change['owed'], 2) . ' now owed' : ''));

        if ($change['owed'] > 0) {
            return back()->with('success',
                'Corrected. Collect a further PHP ' . number_format($change['owed'], 2) . ', then mark the group paid.');
        }

        return back()->with('success', 'Corrected. ' . ($change['fee'] > 0
            ? 'The group now owes PHP ' . number_format($change['fee'], 2) . '.'
            : 'The group enters free.'));
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

        return view('desk.poster', [
            'registerUrl' => $registerUrl,
            // 360px to match the printed size the poster stylesheet asks for.
            'qr'          => Qr::dataUri($registerUrl, 360),
            'museum'      => \App\Models\MuseumInfo::first(),
        ]);
    }

    // -- Shared -----------------------------------------------------------

    /**
     * A single walk-in typed by the desk.
     *
     * Deliberately never a group member: the desk registers a party as a
     * headcount, not as people, and members who want to be in the system as
     * people join from their own phone with the group's code. Typing a
     * member's details here would make them a second, separately-charged
     * visitor - the exact double count the join code exists to prevent.
     */
    private function createVisitor(array $data, ?AdmissionDiscount $discount, string $source, ?int $staffId): Visitor
    {
        $fee = Visitor::feeFor($data['visitor_type'], $discount);
        unset($data['discount_id']);

        // A local is a resident by definition. Under the Baler rule the town
        // is Baler and the barangay is theirs to state; under the Aurora rule
        // they state the town. Both are optional at the desk, where the ID
        // they hand over is the real check. Nobody else has a barangay here.
        if ($data['visitor_type'] === 'Local') {
            $where = Admission::residence($data['city'] ?? null, $data['barangay'] ?? null);
            $data['city']     = $where['city'];
            $data['barangay'] = $where['barangay'];
            $data['province'] = $where['province'];
            $data['country']  = $where['country'];
        } else {
            unset($data['barangay']);
        }

        return DB::transaction(fn () => Visitor::create($data + Visitor::discountColumns($discount) + [
            'visit_type'    => $data['visit_type'] ?? 'Walk-in',
            'country'       => $data['country'] ?? 'Philippines',
            'auth_provider' => 'manual',
            'source'        => $source,
            'registered_by' => $staffId,
            'admission_fee' => $fee,
            // A local owes nothing but still has to show proof of residency,
            // which is a separate check the desk makes in Records. So does
            // anyone in a free category, with that category's proof.
            'payment_status'=> $fee > 0 ? 'Unpaid' : 'Free',
            'id_verified'   => false,
            'last_visit'    => now(),
        ]));
    }

    private function feeHint(Visitor $visitor): string
    {
        if ($visitor->visitor_type === 'Local') {
            return ' Local - check their ID, no fee.';
        }

        if (!$visitor->discount_name) {
            return ' Collect PHP ' . number_format((float) $visitor->admission_fee, 2) . '.';
        }

        $proof = self::proofFor($visitor->discount_id, $visitor->discount_name);

        return $visitor->needsIdCheck()
            ? " {$visitor->discount_name} - check their {$proof}, no fee."
            : ' Collect PHP ' . number_format((float) $visitor->admission_fee, 2) . " ({$visitor->discount_name}) - check their {$proof}.";
    }

    /** What the desk asks to see for a category: its proof, or "<name> ID". */
    private static function proofFor(?int $discountId, string $name): string
    {
        return Admission::discount($discountId)?->proof ?: "{$name} ID";
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
