<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Calls the oversight role what it is.
     *
     * "SuperAdmin" promised powers the role never had. There is no system
     * configuration in this panel for anyone to hold, and the museum's own
     * settings - hours, fee, geofence, halls - belong to the staff in the
     * building. What the role actually does is oversee: the staff, their
     * attendance, the visitor and exhibit records, the feedback, the reports,
     * and the audit trail. That is the head of the Municipal Tourism Office,
     * which the museum sits under, so the role is now named for the person.
     *
     * The audit log stores the actor's role as plain text on each row, so
     * it is rewritten too - otherwise the badge and filter on the Logs page
     * would show two names for one job.
     */
    public function up(): void
    {
        $this->rename('SuperAdmin', 'TourismHead', "ENUM('TourismHead','Administrator')");
    }

    public function down(): void
    {
        $this->rename('TourismHead', 'SuperAdmin', "ENUM('SuperAdmin','Administrator')");
    }

    private function rename(string $from, string $to, string $enum): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Widen first so rows can hold either value while they are rewritten.
            DB::statement("ALTER TABLE staff MODIFY COLUMN role ENUM('SuperAdmin','TourismHead','Administrator') NOT NULL DEFAULT 'Administrator'");
        }

        DB::table('staff')->where('role', $from)->update(['role' => $to]);
        DB::table('logs')->where('role', $from)->update(['role' => $to]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE staff MODIFY COLUMN role {$enum} NOT NULL DEFAULT 'Administrator'");
        }
    }
};
