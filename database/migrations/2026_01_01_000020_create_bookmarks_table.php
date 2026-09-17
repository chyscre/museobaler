<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id('bookmark_id');
            $table->foreignId('visitor_id')->constrained('visitors', 'visitor_id')->cascadeOnDelete();
            $table->foreignId('exhibit_id')->constrained('exhibits', 'exhibit_id')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['visitor_id', 'exhibit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
