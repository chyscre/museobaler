<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * map_x / map_y  where the exhibit's pin sits on its floor of the museum
     *                map, as a percentage (0–100) of the floor plan's width
     *                and height. Percentages rather than pixels so the plan
     *                can be redrawn at another size without moving anything.
     *                Null means nobody has placed it yet; the map page then
     *                drops it somewhere on its floor until an admin drags it
     *                into place and saves.
     */
    public function up(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->decimal('map_x', 5, 2)->nullable()->after('hall');
            $table->decimal('map_y', 5, 2)->nullable()->after('map_x');
        });
    }

    public function down(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->dropColumn(['map_x', 'map_y']);
        });
    }
};
