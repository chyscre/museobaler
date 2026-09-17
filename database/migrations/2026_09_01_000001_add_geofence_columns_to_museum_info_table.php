<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            // Previously hardcoded as MUSEUM_LAT/MUSEUM_LNG/GEOFENCE_RADIUS
            // constants in public/visitor/js/app.js — moving them here lets
            // staff update the geofence from the admin panel instead of
            // requiring a code change + redeploy.
            $table->decimal('latitude', 10, 7)->nullable()->after('admission');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->integer('geofence_radius_m')->default(150)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geofence_radius_m']);
        });
    }
};
