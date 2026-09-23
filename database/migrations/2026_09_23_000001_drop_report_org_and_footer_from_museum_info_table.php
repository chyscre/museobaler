<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the typed organisation and footer lines.
 *
 * They were added a day earlier, before the uploaded letterhead existed.
 * Once an office uploads its real banner, both are already printed on it -
 * so the report was carrying the office name twice and a footer nobody had
 * asked for. The letterhead is the whole of the branding now.
 *
 * Page numbers stay in the PDF and Word footers: those are navigation, not
 * branding, and a twenty-page trail needs them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            foreach (['report_org', 'report_footer'] as $column) {
                if (Schema::hasColumn('museum_info', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->string('report_org')->nullable()->after('report_logo');
            $table->string('report_footer')->nullable()->after('report_org');
        });
    }
};
