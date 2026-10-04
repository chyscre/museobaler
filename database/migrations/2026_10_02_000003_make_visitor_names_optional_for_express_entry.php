<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Express entry: a senior, a PWD or a small child who enters free is
     * counted at the desk without a name.
     *
     * Asking a queue of grandparents and toddlers for their names is the
     * slowest part of the desk and buys nothing - they owe nothing and have
     * no account to sign in to. They are still a visitors row (source
     * 'express'), so every count and breakdown by type, age, sex and origin
     * includes them; Visitor::full_name stands in for the missing name.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->enum('source', ['app', 'kiosk', 'desk', 'recovered', 'express'])->default('app')->change();
        });
    }

    public function down(): void
    {
        DB::table('visitors')->whereNull('first_name')->update(['first_name' => '']);
        DB::table('visitors')->whereNull('last_name')->update(['last_name' => '']);
        DB::table('visitors')->where('source', 'express')->update(['source' => 'desk']);

        Schema::table('visitors', function (Blueprint $table) {
            $table->enum('source', ['app', 'kiosk', 'desk', 'recovered'])->default('app')->change();
            $table->string('first_name')->nullable(false)->default('')->change();
            $table->string('last_name')->nullable(false)->default('')->change();
        });
    }
};
