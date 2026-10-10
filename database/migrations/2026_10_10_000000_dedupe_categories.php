<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fold same-named categories into one and stop it happening again.
     *
     * DatabaseSeeder adds its categories with insertOrIgnore, which only
     * ignores rows that break a unique index - and name had none, so every
     * re-seed added the whole list again (History, Culture, ... twice over).
     * Each name keeps its oldest row; exhibits on a newer copy move to it.
     */
    public function up(): void
    {
        $dupes = DB::table('categories')
            ->select('name', DB::raw('MIN(category_id) as keep_id'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            $extra = DB::table('categories')
                ->where('name', $d->name)
                ->where('category_id', '!=', $d->keep_id)
                ->pluck('category_id');

            DB::table('exhibits')->whereIn('category_id', $extra)->update(['category_id' => $d->keep_id]);
            DB::table('categories')->whereIn('category_id', $extra)->delete();
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
