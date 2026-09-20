<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free admission is for residents of the municipality of Baler, not the
     * whole of Aurora. A local therefore names their barangay rather than
     * their town: the town is always Baler, and the barangay is what the
     * residency ID at the desk actually says.
     *
     * Locals registered before this change carry a town in `city` and no
     * barangay. They are left as they are - re-checking eight towns' worth
     * of history is not something the desk can do, and their ID check
     * already happened under the old rule.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('barangay', 100)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('barangay');
        });
    }
};
