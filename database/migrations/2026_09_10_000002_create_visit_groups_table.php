<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A party that arrives and pays together.
     *
     * The museum's most common arrival is 4-5 people from another province,
     * not a solo visitor. Making each of them fill in a separate form is
     * slower than the paper logbook they are replacing, so a party is one
     * record with a headcount and one payment. Individual names are only
     * captured for the person who signs them in.
     */
    public function up(): void
    {
        Schema::create('visit_groups', function (Blueprint $table) {
            $table->id('group_id');
            $table->string('group_name')->nullable();       // school / agency / family name
            $table->enum('group_type', ['Family', 'Group', 'School', 'Tour'])->default('Group');
            $table->string('contact_name')->nullable();      // teacher, tour lead, whoever signs in
            $table->string('contact_phone')->nullable();
            $table->enum('visitor_type', ['Local', 'Tourist', 'Foreign'])->default('Tourist');
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable()->default('Philippines');

            $table->unsignedInteger('headcount')->default(1);
            $table->unsignedInteger('paying_count')->default(0); // locals enter free, so this can be < headcount
            $table->decimal('total_fee', 10, 2)->default(0);
            $table->enum('payment_status', ['Free', 'Unpaid', 'Paid'])->default('Unpaid');
            $table->timestamp('paid_at')->nullable();

            $table->date('visit_date');
            $table->unsignedBigInteger('registered_by')->nullable(); // staff_id of the desk
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('visit_date');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_groups');
    }
};
