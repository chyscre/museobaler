<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the two roles the Tourism-oversight model needs:
     *
     *  - SuperAdmin  the Municipal Tourism Office. Off-site oversight: owns
     *                staff accounts, schedules, audit logs and reports. They
     *                do not run the museum day to day.
     *  - FrontDesk   the entrance desk post. Registers visitors, collects the
     *                admission fee and sights a local's ID. Keeping it apart
     *                from Guide means the revenue trail only ever carries
     *                people who were actually on the desk.
     */
    public function up(): void
    {
        // MySQL enums have to be redeclared in full to gain a member.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE staff MODIFY COLUMN role
                 ENUM('SuperAdmin','Administrator','Curator','Guide','FrontDesk')
                 NOT NULL DEFAULT 'Curator'"
            );

            return;
        }

        // SQLite (the test suite) renders an enum as a CHECK constraint listing
        // the original three roles, so inserting a SuperAdmin fails there too.
        // Widening it to a plain string drops that constraint; the allowed
        // values are enforced by StaffController's validation either way.
        Schema::table('staff', function (Blueprint $table) {
            $table->string('role', 30)->default('Curator')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Anything on a role that is about to disappear falls back to Curator,
        // otherwise MySQL silently truncates it to an empty string.
        DB::table('staff')->whereIn('role', ['SuperAdmin', 'FrontDesk'])->update(['role' => 'Curator']);

        DB::statement(
            "ALTER TABLE staff MODIFY COLUMN role
             ENUM('Administrator','Curator','Guide')
             NOT NULL DEFAULT 'Curator'"
        );
    }
};
