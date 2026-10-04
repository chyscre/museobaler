<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a member joined their party with its code.
     *
     * Nothing kept it. The visitor row's group_id and last_visit are both
     * overwritten by the next visit, so a group's roster could say who was
     * in it but not when they joined. Kept on the visit row, which is that
     * day's and is never rewritten afterwards (Visit::record).
     *
     * Earlier joins are left empty rather than guessed.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('joined_group_at')->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('joined_group_at');
        });
    }
};
