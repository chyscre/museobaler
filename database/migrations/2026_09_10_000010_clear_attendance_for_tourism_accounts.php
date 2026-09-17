<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Tourism office does not clock in.
     *
     * The head of tourism works from the municipal office and oversees the
     * museum; she is not one of the staff whose hours the DTR reports on.
     * Earlier seeding gave every account a schedule, which put her on the
     * attendance board reading "Absent" every single day.
     *
     * Clears the schedules and any attendance rows those produced. Nothing of
     * value is lost: a Tourism account has never had a real check-in, because
     * checking in requires standing inside the museum geofence.
     */
    public function up(): void
    {
        $tourismIds = DB::table('staff')->where('role', 'TourismHead')->pluck('staff_id');

        if ($tourismIds->isEmpty()) {
            return;
        }

        DB::table('staff_schedules')->whereIn('staff_id', $tourismIds)->delete();
        DB::table('staff_attendances')->whereIn('staff_id', $tourismIds)->delete();
    }

    public function down(): void
    {
        // Nothing to restore — these rows should never have existed.
    }
};
