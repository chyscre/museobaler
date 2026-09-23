<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The uploaded letterhead.
 *
 * report_logo is a small square seal that sits beside a name the layout
 * composes. That is not what a municipal office actually has: it has a
 * letterhead banner, already laid out, already approved, and it wants that
 * exact image across the top of every report.
 *
 * So this is a second, wider image which REPLACES the composed header when
 * present. The logo columns stay: an office with no banner still gets the
 * composed version, and nothing that already works has to be re-entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->string('report_header_image')->nullable()->after('report_logo');
        });
    }

    public function down(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->dropColumn('report_header_image');
        });
    }
};
