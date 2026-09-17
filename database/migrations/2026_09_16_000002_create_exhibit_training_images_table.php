<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photos the recognition model learns from.
 *
 * Kept apart from exhibit_images on purpose: gallery pictures are the few
 * good ones visitors see, training pictures are the thirty-odd angled,
 * badly-lit, half-cropped ones the camera will actually meet in the hall.
 * A null exhibit_id is the "Background" set - walls, floors, cases - which
 * the model needs so that it has somewhere to put a frame that is not
 * pointed at anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibit_training_images', function (Blueprint $table) {
            $table->id('training_image_id');
            $table->foreignId('exhibit_id')->nullable()->constrained('exhibits', 'exhibit_id')->cascadeOnDelete();
            $table->string('filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibit_training_images');
    }
};
