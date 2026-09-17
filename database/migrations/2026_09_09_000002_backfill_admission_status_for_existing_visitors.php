<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Visitors registered before admission tracking existed were never charged
     * and their IDs were never recorded, so leaving them at the column default
     * would park them in the front desk's "IDs to verify" queue forever.
     * Mark those historical local records as settled; nothing is owed on them.
     */
    public function up(): void
    {
        DB::table('visitors')
            ->where('visitor_type', 'Local')
            ->where('id_verified', false)
            ->update(['id_verified' => true, 'verified_by' => 'System (pre-existing record)']);
    }

    public function down(): void
    {
        DB::table('visitors')
            ->where('verified_by', 'System (pre-existing record)')
            ->update(['id_verified' => false, 'verified_by' => null]);
    }
};
