<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guided visit. Most visitors at Museo de Baler roam on their own, so
     * this table stays deliberately small: it only fills up when a guide is
     * actually assigned, which happens when a visitor asks, when a foreign
     * tourist arrives, or for a booked educational tour.
     *
     * Because the volume is low, tours are counted rather than scored — the
     * feedback attached here is context, not a staff performance ranking.
     */
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table) {
            $table->id('tour_id');
            $table->foreignId('guide_staff_id')->nullable()->constrained('staff', 'staff_id')->nullOnDelete();
            $table->enum('tour_type', ['Requested', 'Foreign', 'Educational']);

            // Exactly one of these is set: an individual visitor or a party.
            $table->foreignId('visitor_id')->nullable()->constrained('visitors', 'visitor_id')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('visit_groups', 'group_id')->nullOnDelete();

            $table->unsignedInteger('headcount')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tours');
    }
};
