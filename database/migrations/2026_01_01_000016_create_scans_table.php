<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id('scan_id');
            $table->foreignId('exhibit_id')->constrained('exhibits', 'exhibit_id')->cascadeOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('visitors', 'visitor_id')->nullOnDelete();
            $table->string('language_code', 10)->nullable();
            $table->timestamp('scanned_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
