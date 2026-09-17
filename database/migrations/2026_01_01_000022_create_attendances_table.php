<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id('attendance_id');
            $table->foreignId('visitor_id')->nullable()->constrained('visitors', 'visitor_id')->nullOnDelete();
            $table->string('visitor_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('accuracy')->nullable(); // meters
            $table->string('method')->default('geofence'); // geofence, manual
            $table->date('visit_date');
            $table->timestamps();

            // One attendance per visitor per day
            $table->unique(['visitor_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
