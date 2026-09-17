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
                    'floor'       => $h['floor'] ?? '',
                    'description' => $h['desc']  ?? '',
                    'icon'        => $h['icon']  ?? '',
                    'sort_order'  => (int) ($h['sort'] ?? 0),
                ]);
                $record->save();
                $kept[] = $record->hall_id;
            }
            // Remove halls not in the submitted list
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
            ->get(['exhibit_id', 'name', 'hall', 'storyline_order']);

        $allExhibits = Exhibit::where('status', true)
            ->orderBy('storyline_order')
            ->orderBy('name')
            ->get(['exhibit_id', 'name', 'hall', 'exhibit_code']);

        $activeCount   = Exhibit::where('status', true)->count();
        $archivedCount = Exhibit::where('status', false)->count();

        return view('museum.map', compact('storyline', 'allExhibits', 'activeCount', 'archivedCount'));
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
