<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('museum_info')) {
            Schema::create('museum_info', function (Blueprint $table) {
                $table->id('info_id');
                $table->string('name')->default('Museo de Baler');
                $table->string('tagline')->nullable();
                $table->text('story')->nullable();
                $table->text('story2')->nullable();
                $table->string('address')->nullable();
                $table->string('hours')->nullable();
                $table->string('closed_on')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('admission')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('museum_halls')) {
            Schema::create('museum_halls', function (Blueprint $table) {
                $table->id('hall_id');
                $table->string('name');
                $table->string('floor')->nullable();
                $table->string('description')->nullable();
                $table->string('icon')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('museum_halls');
        Schema::dropIfExists('museum_info');
    }
};
