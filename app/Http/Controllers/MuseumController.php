<?php

namespace App\Http\Controllers;

use App\Models\AdmissionDiscount;
use App\Models\Exhibit;
use App\Models\Log;
use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use App\Support\Admission;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MuseumController extends Controller
{
    public function index()
    {
        $info  = MuseumInfo::firstOrCreate(['info_id' => 1], [
            'name'       => 'Museo de Baler',
            'tagline'    => 'Baler, Aurora, Philippines',
            'address'    => 'Quezon St., Baler, Aurora 3200',
            'hours'      => 'Tue–Sun: 8:00 AM – 5:00 PM',
            'closed_on'  => 'Mondays & Holidays',
            'phone'      => '(042) 284-5678',
            'email'      => 'museobaler@aurora.gov.ph',
            'admission_fee' => MuseumInfo::DEFAULT_ADMISSION_FEE,
        ]);
        $halls = MuseumHall::orderBy('sort_order')->get();
        // Every category, the paused ones too - this is where they are resumed.
        $discounts = AdmissionDiscount::orderBy('sort_order')->orderBy('discount_id')->get();

        return view('museum.index', compact('info', 'halls', 'discounts'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'name'              => 'nullable|string|max:150',
            'tagline'           => 'nullable|string|max:255',
            'story'             => 'nullable|string|max:5000',
            'story2'            => 'nullable|string|max:5000',
            'address'           => 'nullable|string|max:255',
            'hours'             => 'nullable|string|max:100',
            'closed_on'         => 'nullable|string|max:100',
            'phone'             => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:150',
            // Report letterhead. SVG is left out on purpose: it can carry
            // script, and a logo does not need it.
            'report_logo'       => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo'       => 'nullable|boolean',
            // The uploaded letterhead. Allowed larger than the logo because
            // it spans the page and a banner scaled up from 2 MB of pixels
            // prints soft, which is the one thing an office notices.
            'report_header_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:4096',
            'remove_header'       => 'nullable|boolean',
            'admission_fee'     => 'required|numeric|min:0|max:99999.99',
            // Left out, the current setting stands.
            'resident_scope'    => 'nullable|in:' . implode(',', array_keys(Admission::SCOPES)),
            'discounts'         => 'nullable|string|max:20000',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'geofence_radius_m' => 'nullable|integer|min:20|max:2000',
        ], [
            'report_logo.mimes' => 'The logo must be a PNG, JPG or WEBP image.',
            'report_logo.max'   => 'The logo must be 2 MB or smaller.',
            'report_header_image.mimes' => 'The letterhead must be a PNG, JPG or WEBP image.',
            'report_header_image.max'   => 'The letterhead must be 4 MB or smaller.',
        ]);

        // Checked before anything is written, so a bad row cannot leave the
        // museum with half its settings saved.
        // filled(), not has(): the field is written by the page's script, and
        // a post it never ran on arrives empty. Empty means "not sent", not
        // "delete them all" - a list emptied on purpose arrives as "[]".
        $discounts = $request->filled('discounts') ? $this->validatedDiscounts($request->input('discounts')) : null;

        $info = MuseumInfo::firstOrCreate(['info_id' => 1]);
        $info->fill($request->only([
            'name', 'tagline', 'story', 'story2',
            'address', 'hours', 'closed_on', 'phone', 'email', 'admission_fee',
            'latitude', 'longitude', 'geofence_radius_m',
        ]));
        if ($request->filled('resident_scope')) {
            $info->resident_scope = $request->input('resident_scope');
        }

        // Before the save, so the admission sentence it writes already
        // lists the categories as they now stand.
        if ($discounts !== null) {
            $this->syncDiscounts($discounts);
        }

        $info->report_logo = $this->swapBrandingFile(
            $request, 'report_logo', 'remove_logo', 'report-logo', $info->report_logo
        );

        $info->report_header_image = $this->swapBrandingFile(
            $request, 'report_header_image', 'remove_header', 'report-header', $info->report_header_image
        );

        $info->save();

        // Sync halls. filled(), for the reason given for the discounts above:
        // with has(), a post whose hall list was never written - the Save
        // button used to submit the form without running the script that
        // writes it - read as an empty list and deleted every hall.
        if ($request->filled('halls')) {
            $halls = json_decode($request->input('halls'), true) ?? [];
            $kept  = [];
            foreach ($halls as $h) {
                $id = !empty($h['id']) ? (int) $h['id'] : null;
                $record = $id ? MuseumHall::find($id) : new MuseumHall();
                $record->fill([
                    'name'        => $h['name']  ?? '',
                    // The floor map picks a plan by this exact label, so anything
                    // else (a typo, an old free-text value) falls back to the ground.
                    'floor'       => in_array($h['floor'] ?? null, MuseumHall::FLOORS, true) ? $h['floor'] : MuseumHall::FLOORS[0],
                    'description' => $h['desc']  ?? '',
                    'icon'        => $h['icon']  ?? '',
                    'sort_order'  => (int) ($h['sort'] ?? 0),
                ]);
                $record->save();
                $kept[] = $record->hall_id;
            }
            // Remove halls not in the submitted list. Exhibits that were in a
            // removed hall keep everything else and simply have no hall until
            // staff pick one (hall_id is set null by the database).
            MuseumHall::whereNotIn('hall_id', $kept)->delete();
        }

        $this->log('Museum Info Updated', 'Updated museum information and halls');

        return redirect()->route('museum.index')->with('success', 'Museum info saved.');
    }

    public function map()
    {
        $storyline = Exhibit::where('status', true)
            ->where('storyline_order', '>', 0)
            ->orderBy('storyline_order')
            ->with('museumHall')
            ->get(['exhibit_id', 'name', 'hall_id', 'storyline_order']);

        $allExhibits = Exhibit::where('status', true)
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->with('museumHall')
            ->get(['exhibit_id', 'name', 'hall_id', 'exhibit_code', 'storyline_order', 'map_x', 'map_y']);

        // What the map draws its pins from. Positions are percentages of the
        // floor plan; null ones get a spot picked by the page until saved.
        $pins = $allExhibits->map(fn ($ex) => [
            'id'        => $ex->exhibit_id,
            'code'      => $ex->exhibit_code,
            'name'      => $ex->name,
            'hall'      => $ex->hall,
            'floor'     => $ex->floor === '2nd Floor' ? 'second' : 'ground',
            'storyline' => (int) $ex->storyline_order,
            'x'         => $ex->map_x,
            'y'         => $ex->map_y,
        ])->values();

        $activeCount   = Exhibit::where('status', true)->count();
        $archivedCount = Exhibit::where('status', false)->count();

        return view('museum.map', compact('storyline', 'allExhibits', 'pins', 'activeCount', 'archivedCount'));
    }

    /**
     * Save where the pins were dragged to. The page sends every pin on both
     * floors at once, so one save is the whole layout.
     */
    public function savePositions(Request $request)
    {
        $data = $request->validate([
            'positions'              => 'required|array',
            'positions.*.id'         => 'required|integer|exists:exhibits,exhibit_id',
            'positions.*.x'          => 'required|numeric|min:0|max:100',
            'positions.*.y'          => 'required|numeric|min:0|max:100',
        ]);

        foreach ($data['positions'] as $p) {
            Exhibit::where('exhibit_id', $p['id'])->update([
                'map_x' => round($p['x'], 2),
                'map_y' => round($p['y'], 2),
            ]);
        }

        $this->log('Museum Map Updated', 'Repositioned ' . count($data['positions']) . ' exhibit pin(s) on the floor plan');

        return response()->json(['ok' => true, 'saved' => count($data['positions'])]);
    }

    /**
     * The discount categories posted from the Museum Info page, checked.
     *
     * They arrive as one JSON list, the way the halls do, because the page
     * edits them as rows in place. Each is validated as if it had been its
     * own form, and the first problem is reported against `discounts` with
     * the row's name, so the admin can find it.
     *
     * @return list<array<string, mixed>>
     */
    private function validatedDiscounts(?string $json): array
    {
        $rows = json_decode((string) $json, true);
        if (!is_array($rows)) {
            throw ValidationException::withMessages(['discounts' => 'The discount list could not be read. Reload the page and try again.']);
        }

        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            foreach (['min_age', 'max_age', 'proof', 'id'] as $optional) {
                if (($row[$optional] ?? null) === '') {
                    $row[$optional] = null;
                }
            }

            $v = Validator::make($row, [
                'id'          => 'nullable|integer',
                'name'        => 'required|string|max:80',
                'proof'       => 'nullable|string|max:150',
                'percent_off' => 'required|integer|min:1|max:100',
                'min_age'     => 'nullable|integer|min:0|max:120',
                'max_age'     => 'nullable|integer|min:0|max:120',
                'active'      => 'nullable|boolean',
            ], [
                'name.required'        => 'Every discount needs a name.',
                'percent_off.required' => 'Say how much comes off, from 1% to 100% (free).',
                'percent_off.min'      => 'Say how much comes off, from 1% to 100% (free).',
                'percent_off.max'      => 'Say how much comes off, from 1% to 100% (free).',
            ]);

            $label = trim((string) ($row['name'] ?? '')) ?: 'Discount ' . ($i + 1);

            if ($v->fails()) {
                throw ValidationException::withMessages(['discounts' => "{$label}: " . $v->errors()->first()]);
            }
            if (isset($row['min_age'], $row['max_age']) && (int) $row['min_age'] > (int) $row['max_age']) {
                throw ValidationException::withMessages(['discounts' => "{$label}: the youngest age is above the oldest."]);
            }
            // Two options the desk cannot tell apart.
            if (in_array(mb_strtolower(trim($row['name'])), array_map(fn ($c) => mb_strtolower($c['name']), $clean), true)) {
                throw ValidationException::withMessages(['discounts' => "{$label} is listed twice."]);
            }

            $clean[] = [
                'id'          => isset($row['id']) ? (int) $row['id'] : null,
                'name'        => trim($row['name']),
                'proof'       => isset($row['proof']) ? trim($row['proof']) : null,
                'percent_off' => (int) $row['percent_off'],
                'min_age'     => isset($row['min_age']) ? (int) $row['min_age'] : null,
                'max_age'     => isset($row['max_age']) ? (int) $row['max_age'] : null,
                'active'      => (bool) ($row['active'] ?? true),
                'sort_order'  => $i,
            ];
        }

        return $clean;
    }

    /**
     * Make the categories match the page. A row taken off the page is
     * deleted: visitors who claimed it keep its name and percentage on their
     * own record, so what they paid still reads correctly, and a returning
     * one is simply charged the full fee.
     */
    private function syncDiscounts(array $rows): void
    {
        $kept = [];
        foreach ($rows as $row) {
            $record = ($row['id'] ? AdmissionDiscount::find($row['id']) : null) ?? new AdmissionDiscount();
            $record->fill(Arr::except($row, ['id']))->save();
            $kept[] = $record->discount_id;
        }

        AdmissionDiscount::whereNotIn('discount_id', $kept)->get()->each->delete();
    }

    /**
     * Replace, clear or keep one branding image, returning what to store.
     *
     * The old file is deleted whenever it stops being referenced, because
     * the alternative is a branding folder that only ever grows and that
     * nobody will ever be sure is safe to empty.
     *
     * The stored name carries a timestamp rather than the uploaded
     * filename: the uploaded one is attacker-controlled, and two uploads
     * called logo.png a month apart must not collide - nor share a cached
     * copy in the browser of everyone who printed last month's reports.
     */
    private function swapBrandingFile(
        Request $request,
        string $field,
        string $removeField,
        string $prefix,
        ?string $current,
    ): ?string {
        $uploaded = $request->file($field);

        if (!$request->boolean($removeField) && !$uploaded) {
            return $current;
        }

        $this->deleteLogo($current);

        if (!$uploaded) {
            return null;
        }

        $dir = public_path(MuseumInfo::LOGO_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = $prefix . '-' . time() . '.' . strtolower($uploaded->getClientOriginalExtension());
        $uploaded->move($dir, $name);

        return $name;
    }

    private function deleteLogo(?string $file): void
    {
        if (!$file) return;
        $path = public_path(MuseumInfo::LOGO_DIR . '/' . basename($file));
        if (is_file($path)) {
            @unlink($path);
        }
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
