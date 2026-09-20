<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the panel and the visitor API actually filter on.
 *
 * With a few hundred rows nothing here is measurable. The logbook, the
 * feedback report and the audit export all take a date range, and a year
 * of visitors is tens of thousands of rows scanned end to end on every
 * report without these. The visitor sign-in looks a visitor up by email on
 * every attempt, which without an index is a full read of the table per
 * password guess - the one query an attacker can make the server run as
 * often as they like.
 *
 * Every index here matches a WHERE or ORDER BY in a controller or in
 * public/api; none is speculative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->index('email');          // visitor sign-in, duplicate check on register
            $table->index('visitor_type');   // records page counts, visitor report
            $table->index('payment_status'); // unpaid count, admission report
            $table->index('last_visit');     // returning-visitor logic
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->index('scanned_at');            // exhibit report date range
            $table->index(['exhibit_id', 'scanned_at']);
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->index('submitted_at'); // feedback report date range
            $table->index('created_at');   // notification poll, feedback list
            $table->index('rating');       // rating distribution
        });

        Schema::table('logs', function (Blueprint $table) {
            $table->index('created_at');           // audit export date range, logs page
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['is_active', 'created_at']);
        });

        Schema::table('exhibits', function (Blueprint $table) {
            $table->index(['status', 'storyline_order']); // every visitor-facing list
        });

        Schema::table('attendances', function (Blueprint $table) {
            // The unique (visitor_id, visit_date) leads on visitor_id, so a
            // lookup by date alone cannot use it.
            $table->index('visit_date');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropIndex(['visitor_type']);
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['last_visit']);
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->dropIndex(['scanned_at']);
            $table->dropIndex(['exhibit_id', 'scanned_at']);
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['rating']);
        });

        Schema::table('logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'created_at']);
        });

        Schema::table('exhibits', function (Blueprint $table) {
            $table->dropIndex(['status', 'storyline_order']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['visit_date']);
        });
    }
};
