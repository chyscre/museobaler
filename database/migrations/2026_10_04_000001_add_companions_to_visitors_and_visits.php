<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companions: the free people a visitor brings along - a grandparent with
     * a senior citizen ID, a PWD, a small child - counted on the visitor's own
     * visit instead of as a party.
     *
     * Each companion is a nameless visitors row, the same as an express entry
     * at the desk (source 'express'), so every count and every breakdown by
     * type, category, age and origin includes them without a report having to
     * know they exist. companion_of says whose visit they came on; the day is
     * the row's last_visit, so a visitor who comes back with different people
     * gets new rows and the old ones stay with the old day.
     *
     * On the holder's visit row:
     *
     *   headcount    the holder plus today's companions. Not summed anywhere:
     *                each companion is a visitor (and a visit) of its own,
     *                and adding this on top would count them twice
     *   companions   which categories and how many, copied for the day
     *
     * See Visitor::setCompanions().
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignId('companion_of')->nullable()->after('group_id')
                ->constrained('visitors', 'visitor_id')->nullOnDelete();
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedSmallInteger('headcount')->default(1)->after('group_id');
            $table->json('companions')->nullable()->after('headcount');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['headcount', 'companions']);
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('companion_of');
        });
    }
};
