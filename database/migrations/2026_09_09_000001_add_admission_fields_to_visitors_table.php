<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission fee tracking for the manual visitor registration flow.
     *
     * Baler locals enter free but must show a valid ID at the entrance desk,
     * so their record starts unverified and staff confirms it in Records.
     * Tourist / Foreign visitors owe a fixed fee, collected at the counter,
     * so their record starts Unpaid and staff marks it Paid once collected.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->decimal('admission_fee', 8, 2)->default(0)->after('visitor_type');
            $table->enum('payment_status', ['Free', 'Unpaid', 'Paid'])->default('Free')->after('admission_fee');
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            // Locals only — proof of residency is checked in person by staff.
            $table->boolean('id_verified')->default(false)->after('paid_at');
            $table->timestamp('verified_at')->nullable()->after('id_verified');
            $table->string('verified_by')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn([
                'admission_fee', 'payment_status', 'paid_at',
                'id_verified', 'verified_at', 'verified_by',
            ]);
        });
    }
};
