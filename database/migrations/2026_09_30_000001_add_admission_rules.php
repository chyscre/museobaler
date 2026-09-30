<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who enters free, and who pays less, becomes a museum setting.
     *
     * Until now the only rule was written into the code: Baler residents
     * enter free, everyone else pays the flat fee. The museum expects that to
     * change - free for every Aurora resident, free or discounted for senior
     * citizens, PWDs and young children - and each change would have meant a
     * developer. Now an administrator sets it on the Museum Info page.
     *
     *   museum_info.resident_scope   which residents enter free: 'baler'
     *                                (the thirteen barangays) or 'aurora'
     *                                (the province's eight towns)
     *   admission_discounts          the other categories: what the desk
     *                                checks, how much comes off, and an
     *                                optional age range
     *   visitors.discount_*          the category a visitor claimed, with its
     *                                name and percentage copied at the time,
     *                                so renaming or removing a category later
     *                                does not rewrite what past visitors paid
     *   visit_groups.discounts       the same for a party: how many of it
     *                                claimed each category, as a snapshot
     */
    public function up(): void
    {
        Schema::table('museum_info', function (Blueprint $table) {
            $table->string('resident_scope', 20)->default('baler')->after('admission_fee');
        });

        Schema::create('admission_discounts', function (Blueprint $table) {
            $table->id('discount_id');
            $table->string('name', 80);
            $table->string('proof', 150)->nullable();
            $table->unsignedTinyInteger('percent_off');
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('admission_fee')
                ->constrained('admission_discounts', 'discount_id')->nullOnDelete();
            $table->string('discount_name', 80)->nullable()->after('discount_id');
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('discount_name');
        });

        Schema::table('visit_groups', function (Blueprint $table) {
            $table->json('discounts')->nullable()->after('local_count');
        });
    }

    public function down(): void
    {
        Schema::table('visit_groups', function (Blueprint $table) {
            $table->dropColumn('discounts');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_id');
            $table->dropColumn(['discount_name', 'discount_percent']);
        });

        Schema::dropIfExists('admission_discounts');

        Schema::table('museum_info', function (Blueprint $table) {
            $table->dropColumn('resident_scope');
        });
    }
};
