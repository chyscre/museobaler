<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mixed party, and a fee that turns out to be wrong after it was paid.
     *
     * The commonest group at this museum is out-of-town relatives visiting
     * with a local, and locals enter free. The desk used to record that as a
     * "paying heads" number - a question nobody asks a party at a counter -
     * so it defaulted to everyone paying and the local was charged. Once the
     * group was marked Paid there was no way to put that right: no edit, no
     * refund, and the ₱50 the desk handed back across the counter never
     * reached the logbook, which went on reporting it as revenue.
     *
     *   local_count      how many of the party are from Baler. The desk is
     *                    asked THIS question - "anyone from Baler?" - and the
     *                    paying count is worked out from it.
     *   refunded_*       money handed back after a correction to a group
     *                    that had already paid. total_fee stays what is owed;
     *                    refunded_amount is what went back, so the logbook
     *                    can show collected, refunded and net and agree with
     *                    the cash drawer.
     */
    public function up(): void
    {
        Schema::table('visit_groups', function (Blueprint $table) {
            $table->unsignedInteger('local_count')->default(0)->after('headcount');
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('paid_at');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refunded_at');
        });

        // What existing rows already knew, said the other way round. Done in
        // PHP rather than one UPDATE: GREATEST() is MySQL, the test suite is
        // SQLite, and the table is small.
        DB::table('visit_groups')->select('group_id', 'visitor_type', 'headcount', 'paying_count')
            ->orderBy('group_id')
            ->each(function ($g) {
                DB::table('visit_groups')->where('group_id', $g->group_id)->update([
                    'local_count' => $g->visitor_type === 'Local'
                        ? (int) $g->headcount
                        : max(0, (int) $g->headcount - (int) $g->paying_count),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('visit_groups', function (Blueprint $table) {
            $table->dropColumn(['local_count', 'refunded_amount', 'refunded_at', 'refunded_by']);
        });
    }
};
