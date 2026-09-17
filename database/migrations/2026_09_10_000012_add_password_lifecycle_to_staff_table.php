<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes a staff password something the staff member owns.
     *
     * Until now the Tourism office typed a password into the Add Staff form
     * and told the person what it was. That password then stayed put forever,
     * because there was no screen anywhere for changing your own. Tourism
     * therefore knew every working credential in the building — which quietly
     * voided the audit log: any row attributed to an Administrator could just
     * as easily have been Tourism signed in as them.
     *
     * Two columns fix the lifecycle:
     *
     *   must_change_password  set when Tourism issues or resets a password.
     *                         The account can sign in and do nothing else
     *                         until it picks its own.
     *   password_changed_at   when the account last chose one. Null means the
     *                         handed-over password is still in place.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        // Every account that exists right now was created the old way, so
        // every one of them is holding a password Tourism picked and still
        // knows. They all rotate at next sign-in.
        DB::table('staff')->update(['must_change_password' => true]);
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
