<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qr_file          the SVG made for this exhibit the moment it is created,
     *                  so the label for the display case is ready to print
     *                  without a trip to the QR panel. Remade when the code
     *                  changes, since the code is what the QR encodes.
     * source_language  which language the name, description and fun facts on
     *                  the exhibit itself are written in. The AI translates
     *                  out of this into the other languages the visitor app
     *                  offers, and narrates the original in it.
     */
    public function up(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->string('qr_file')->nullable()->after('image');
            $table->string('source_language', 10)->default('en')->after('languages');
        });
    }

    public function down(): void
    {
        Schema::table('exhibits', function (Blueprint $table) {
            $table->dropColumn(['qr_file', 'source_language']);
        });
    }
};
