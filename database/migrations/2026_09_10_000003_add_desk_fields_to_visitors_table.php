<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties a visitor row back to how it got created.
     *
     * Until now every visitor self-registered in the PWA, which meant anyone
     * without a smartphone never made it into the database and the paper
     * logbook had to stay. These columns let the entrance desk and the
     * counter tablet write into the same table, and record which staff
     * member did it so the admission trail stays attributable.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('visitor_id')
                  ->constrained('visit_groups', 'group_id')->nullOnDelete();

            // app    = visitor's own phone (PWA)
            // kiosk  = counter tablet, visitor typed it themselves
            // desk   = staff typed it for them
            // recovered = written on a paper slip during a brownout, keyed in later
            $table->enum('source', ['app', 'kiosk', 'desk', 'recovered'])->default('app')->after('auth_provider');
            $table->unsignedBigInteger('registered_by')->nullable()->after('source');

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['group_id', 'source', 'registered_by']);
        });
    }
};
