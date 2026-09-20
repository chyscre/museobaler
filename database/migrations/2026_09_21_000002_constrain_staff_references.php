<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every "which staff member did this" column now says so to the database.
     *
     * Nine columns held a staff_id with nothing enforcing it, and one held a
     * staff *name*: visitors.verified_by was whatever auth()->user()->name
     * was at the time, so a renamed account silently stopped matching its
     * own verifications. All of them become real foreign keys that null out
     * if the staff row ever goes, which is what the app already assumes
     * (every reader is null-safe, and staff are deactivated, not deleted).
     *
     * logs.user_id stays deliberately unconstrained: an audit row must
     * survive the account it describes, and it carries its own copy of the
     * name and role for exactly that reason.
     */
    private const NULLABLE = [
        'visitors'               => ['registered_by'],
        'visit_groups'           => ['registered_by', 'refunded_by'],
        'attendance_corrections' => ['reviewed_by'],
        'tours'                  => ['created_by'],
        'staff_attendances'      => ['recorded_by'],
        'staff_attendance_days'  => ['opened_by'],
        'feedback'               => ['staff_id'],
    ];

    public function up(): void
    {
        // verified_by: name -> id. A name that matches exactly one account
        // is resolved; anything else (ambiguous, renamed, gone) becomes
        // null. The "ID Verified" log rows still say who did it.
        Schema::table('visitors', function (Blueprint $table) {
            $table->unsignedBigInteger('verified_by_id')->nullable()->after('verified_by');
        });

        $names = DB::table('visitors')->whereNotNull('verified_by')->distinct()->pluck('verified_by');
        foreach ($names as $name) {
            $ids = DB::table('staff')->where('name', $name)->pluck('staff_id');
            if ($ids->count() === 1) {
                DB::table('visitors')->where('verified_by', $name)->update(['verified_by_id' => $ids->first()]);
            }
        }

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('verified_by');
        });
        Schema::table('visitors', function (Blueprint $table) {
            $table->renameColumn('verified_by_id', 'verified_by');
        });
        Schema::table('visitors', function (Blueprint $table) {
            $table->foreign('verified_by')->references('staff_id')->on('staff')->nullOnDelete();
        });

        // requested_by was NOT NULL; it has to allow null for the same
        // set-null rule as its siblings.
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->foreign('requested_by')->references('staff_id')->on('staff')->nullOnDelete();
        });

        foreach (self::NULLABLE as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->foreign($column)->references('staff_id')->on('staff')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::NULLABLE as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropForeign([$column]);
                }
            });
        }

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->string('verified_by_name')->nullable()->after('verified_by');
        });
        foreach (DB::table('staff')->get() as $staff) {
            DB::table('visitors')->where('verified_by', $staff->staff_id)->update(['verified_by_name' => $staff->name]);
        }
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('verified_by');
        });
        Schema::table('visitors', function (Blueprint $table) {
            $table->renameColumn('verified_by_name', 'verified_by');
        });
    }
};
