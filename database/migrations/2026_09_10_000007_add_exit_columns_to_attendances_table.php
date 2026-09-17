<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema drift fix: public/api/attendance.php already writes exited_at
     * and duration_mins on the visitor geofence exit event, but no migration
     * ever created those columns — so that UPDATE has been failing silently
     * on a fresh database.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'exited_at')) {
                $table->timestamp('exited_at')->nullable()->after('visit_date');
            }
            if (!Schema::hasColumn('attendances', 'duration_mins')) {
                $table->integer('duration_mins')->nullable()->after('exited_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['exited_at', 'duration_mins']);
        });
    }
};
