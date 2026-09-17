<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibits', function (Blueprint $table) {
            $table->id('exhibit_id');
            $table->string('exhibit_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('fun_facts')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories', 'category_id')->nullOnDelete();
            $table->enum('floor', ['Ground Floor', '2nd Floor'])->default('Ground Floor');
            $table->enum('hall', ['Hall A', 'Hall B', 'Hall C', 'Hall D', 'Hall E'])->nullable();
            $table->string('authors')->nullable();
            $table->string('languages')->default('Filipino,English');
            $table->integer('storyline_order')->default(0);
            $table->string('image')->nullable();
            $table->boolean('status')->default(1);
            $table->date('date_published')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibits');
    }
};
