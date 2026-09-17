<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Tracks whether this scan came from a QR code or image search.
            // Nullable so existing rows remain valid without a value.
            $table->enum('scan_type', ['qr', 'image'])->nullable()->after('language_code');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('scan_type');
        });
    }
};
