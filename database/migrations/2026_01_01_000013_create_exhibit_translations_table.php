<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibit_translations', function (Blueprint $table) {
            $table->id('translation_id');
            $table->foreignId('exhibit_id')->constrained('exhibits', 'exhibit_id')->cascadeOnDelete();
            $table->string('language_code', 10);
            $table->string('language_label');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('fun_facts')->nullable();
            $table->string('audio_file')->nullable();
            $table->timestamps();

            $table->unique(['exhibit_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibit_translations');
    }
};
