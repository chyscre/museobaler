<?php

namespace App\Http\Controllers;

use App\Models\Exhibit;
use App\Models\Log;
use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use Illuminate\Http\Request;

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

        return view('museum.index', compact('info', 'halls'));
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
            'admission_fee'     => 'required|numeric|min:0|max:99999.99',
            'latitude'          => 'nullable|numeric|between:-90,90',
            'longitude'         => 'nullable|numeric|between:-180,180',
            'geofence_radius_m' => 'nullable|integer|min:20|max:2000',
        ]);

        $info = MuseumInfo::firstOrCreate(['info_id' => 1]);
        $info->update($request->only([
            'name', 'tagline', 'story', 'story2',
            'address', 'hours', 'closed_on', 'phone', 'email', 'admission_fee',
            'latitude', 'longitude', 'geofence_radius_m',
        ]));

        // Sync halls
        if ($request->has('halls')) {
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
