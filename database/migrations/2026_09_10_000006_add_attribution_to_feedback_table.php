<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback is about the museum by default, because most visits have no
     * guide. Only a visit that actually had one gets the extra "how was your
     * guide" question, so both columns stay null on the majority of rows —
     * that is the expected shape, not missing data.
     */
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->foreignId('tour_id')->nullable()->after('visitor_id')
                  ->constrained('tours', 'tour_id')->nullOnDelete();
            $table->unsignedBigInteger('staff_id')->nullable()->after('tour_id');
            $table->tinyInteger('guide_rating')->nullable()->after('rating');

            // visitor = the visitor named the guide themselves
            // duty    = inferred from who was on duty, weaker, never used to rank
            // none    = no guide on this visit
            $table->enum('attributed_by', ['none', 'visitor', 'duty'])->default('none')->after('guide_rating');

            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['tour_id']);
            $table->dropIndex(['staff_id']);
            $table->dropColumn(['tour_id', 'staff_id', 'guide_rating', 'attributed_by']);
        });
    }
};
