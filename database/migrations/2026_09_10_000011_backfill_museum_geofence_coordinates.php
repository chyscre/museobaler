<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The museum_info row predates the geofence columns, and the seeder that
     * carries the coordinates uses insertOrIgnore — so on any install that
     * already had a museum_info row, latitude/longitude stayed null.
     *
     * That silently disabled both geofences: GeofenceService::check() passes
     * everything through when the museum has no pin (so staff check-in fell
     * back to the rotating QR alone), and the visitor app quietly used the
     * hardcoded fallback constants instead of the admin-editable values.
     *
     * Backfills the same Baler coordinates the seeder and the visitor app
     * fallback already use. Only touches rows that have no pin set, so a
     * museum that has already corrected its location is left alone.
     */
    public function up(): void
    {
        DB::table('museum_info')
            ->whereNull('latitude')
            ->orWhereNull('longitude')
            ->update([
                'latitude'   => 15.760440549923766,
                'longitude'  => 121.56169583726937,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: clearing the pin would switch the
        // geofences back off, which is the bug this migration exists to fix.
    }
};
