<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letterhead on every printed report.
 *
 * Reports are printed from the browser, and until now their header was
 * typed into the layout - fine while the developers were around, useless
 * once the museum wants its own seal on the page. Three settings, all
 * optional: a logo file, the organisation line under the title, and a
 * footer line. Nothing else in the row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->string('report_logo')->nullable()->after('email');
            $table->string('report_org')->nullable()->after('report_logo');
            $table->string('report_footer')->nullable()->after('report_org');
        });
    }

    public function down(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->dropColumn(['report_logo', 'report_org', 'report_footer']);
        });
    }
};
