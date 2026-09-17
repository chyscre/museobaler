<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the Curator role.
     *
     * The museum does not staff a separate curator post - whoever writes the
     * exhibit labels is one of the same people running the museum. Keeping a
     * role that nobody actually holds just makes the account screen confusing
     * and leaves a permission tier that has to be reasoned about forever.
     *
     * Existing curators become Administrators rather than Guides: their whole
     * job was writing exhibit content, and after this only Administrators can
     * do that. Demoting them would silently take that away.
     */
    public function up(): void
    {
        DB::table('staff')->where('role', 'Curator')->update(['role' => 'Administrator']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE staff MODIFY COLUMN role
                 ENUM('SuperAdmin','Administrator','Guide','FrontDesk')
                 NOT NULL DEFAULT 'Guide'"
            );

            return;
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->string('role', 30)->default('Guide')->change();
        });
    }

    public function down(): void
    {
        // Deliberately does not restore anyone to Curator: which of the
        // Administrators used to be one is not recorded, and guessing would
        // quietly strip someone's access.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE staff MODIFY COLUMN role
             ENUM('SuperAdmin','Administrator','Curator','Guide','FrontDesk')
             NOT NULL DEFAULT 'Guide'"
        );
    }
};
