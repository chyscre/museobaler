<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id('visitor_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->integer('age')->nullable();
            $table->enum('sex', ['Male', 'Female', 'Other', 'Prefer not to say'])->nullable();
            $table->enum('visitor_type', ['Local', 'Tourist', 'Foreign'])->default('Local');
            $table->enum('visit_type', ['Solo', 'Group', 'School', 'Family', 'Walk-in'])->default('Solo');
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable()->default('Philippines');
            $table->string('email')->nullable();
            $table->string('auth_provider')->default('manual');
            $table->string('explore_mode')->default('Storyline');
            $table->timestamp('last_visit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
