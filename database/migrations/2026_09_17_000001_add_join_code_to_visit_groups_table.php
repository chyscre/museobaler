<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a member of a party join the group the desk registered.
     *
     * Until now a group was a number: the desk typed "5", collected one fee,
     * and the five people were never in the system as people. If any of them
     * then registered on their own phone from the entrance poster, they were
     * a sixth visitor who owed a second fee - and the day's headcount read
     * six. The two ways in did not know about each other.
     *
     * The desk now hands the party a short code. A member types it into the
     * app and becomes a member of that group: counted once, charged nothing
     * (the group paid), and unlocked the moment the group is. visitors.group_id
     * already existed for this and was never set by anything.
     *
     * Six characters from an alphabet without 0/O or 1/I, because it gets
     * read off a screen and typed on a phone. Only valid on the group's own
     * visit_date, and only until the headcount is reached - a leaked code is
     * worth nothing tomorrow and cannot admit more people than paid.
     */
    public function up(): void
    {
        Schema::table('visit_groups', function (Blueprint $table) {
            $table->string('join_code', 8)->nullable()->after('group_id');
            $table->index(['join_code', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::table('visit_groups', function (Blueprint $table) {
            $table->dropIndex(['join_code', 'visit_date']);
            $table->dropColumn('join_code');
        });
    }
};
