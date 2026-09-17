<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collapses the museum's roles down to one.
     *
     * Museo de Baler runs on a handful of people who all do everything: the
     * same person mans the entrance desk, walks a school group round when one
     * books in, and edits an exhibit label afterwards. Splitting them into
     * Guide and FrontDesk modelled an org chart the museum does not have, and
     * every one of those tiers was something to reason about forever.
     *
     * What is left is the one distinction that is real:
     *
     *   SuperAdmin     the Municipal Tourism Office - oversight, off-site
     *   Administrator  museum staff - everything on-site
     *
     * Tourism keeps the powers that only make sense from outside: creating
     * accounts, setting schedules, approving attendance corrections, and
     * exporting the audit trail.
     */
    public function up(): void
    {
        DB::table('staff')->whereIn('role', ['Guide', 'FrontDesk'])->update(['role' => 'Administrator']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE staff MODIFY COLUMN role
                 ENUM('SuperAdmin','Administrator')
                 NOT NULL DEFAULT 'Administrator'"
            );

            return;
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->string('role', 30)->default('Administrator')->change();
        });
    }

    public function down(): void
    {
        // No one is put back on Guide or FrontDesk: which staff held those is
        // not recorded, and guessing would hand someone the wrong access.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE staff MODIFY COLUMN role
             ENUM('SuperAdmin','Administrator','Guide','FrontDesk')
             NOT NULL DEFAULT 'Administrator'"
        );
    }
};
