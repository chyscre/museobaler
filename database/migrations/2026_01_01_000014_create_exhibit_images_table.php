<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibit_images', function (Blueprint $table) {
            $table->id('image_id');
            $table->foreignId('exhibit_id')->constrained('exhibits', 'exhibit_id')->cascadeOnDelete();
            $table->string('filename');
            $table->string('caption')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibit_images');
    }
};
