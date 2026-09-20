<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * When a visitor registered, the claim endpoint overwrote `method` from
     * 'geofence' to 'registered'. The attendance screen only knows 'geofence',
     * so every one of those rows displayed as a hand-entered "Manual" record.
     *
     * Only the geofence POST creates visitor attendance rows, and only those
     * rows can be claimed, so every 'registered' row was geofence-detected.
     * Registration itself is still visible from visitor_id.
     */
    public function up(): void
    {
        DB::table('attendances')
            ->where('method', 'registered')
            ->update(['method' => 'geofence']);
    }

    public function down(): void
    {
        DB::table('attendances')
            ->where('method', 'geofence')
            ->whereNotNull('visitor_id')
            ->update(['method' => 'registered']);
    }
};
