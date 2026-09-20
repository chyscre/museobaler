<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make exhibits point at the museum_halls table instead of carrying
     * their own hall and floor as fixed enums.
     *
     * The halls have been editable from the Museum Info page for a while,
     * but exhibits never noticed: their hall was one of five baked-in
     * labels and their floor a second baked-in pair, retyped in four Blade
     * files. Renaming a hall on the About screen did not rename it on any
     * exhibit, and adding a sixth hall was impossible without a migration.
     * After this, an exhibit's hall and floor are whatever its hall row
     * says, and there is exactly one place to change them.
     *
     * Existing assignments are kept: every hall label still in use gets a
     * museum_halls row (matched by prefix to a seeded row when one exists,
     * so "Hall A" finds "Hall A — History & Religion"), and each exhibit is
     * linked to it before the old columns go.
     */
    public function up(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->foreignId('hall_id')->nullable()->after('category_id')
                ->constrained('museum_halls', 'hall_id')->nullOnDelete();
        });

        $halls = DB::table('museum_halls')->orderBy('sort_order')->get();
        $nextOrder = (int) $halls->max('sort_order') + 1;

        $inUse = DB::table('exhibits')
            ->select('hall', 'floor')
            ->whereNotNull('hall')
            ->distinct()
            ->get();

        foreach ($inUse as $use) {
            $match = $halls->first(fn ($h) => str_starts_with($h->name, $use->hall));

            $hallId = $match->hall_id ?? DB::table('museum_halls')->insertGetId([
                'name'       => $use->hall,
                'floor'      => $use->floor ?: 'Ground Floor',
                'sort_order' => $nextOrder++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('exhibits')->where('hall', $use->hall)->update(['hall_id' => $hallId]);
        }

        Schema::table('exhibits', function (Blueprint $table) {
            $table->dropColumn(['hall', 'floor']);
        });
    }

    /**
     * Restores hall and floor as plain strings, copied back from the hall
     * row, rather than the original enums: an enum could not hold a hall
     * that staff created since, and would drop those exhibits' halls.
     */
    public function down(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->string('floor', 50)->nullable()->after('category_id');
            $table->string('hall', 100)->nullable()->after('floor');
        });

        foreach (DB::table('museum_halls')->get() as $hall) {
            DB::table('exhibits')->where('hall_id', $hall->hall_id)
                ->update(['hall' => $hall->name, 'floor' => $hall->floor]);
        }

        Schema::table('exhibits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hall_id');
        });
    }
};
