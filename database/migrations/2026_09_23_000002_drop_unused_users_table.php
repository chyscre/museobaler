<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `users` table, which nothing has read or written since `staff`
 * replaced it.
 *
 * It came from Laravel's stock scaffold migration and was briefly the staff
 * table - its role column still carries the original three values
 * (Administrator, Curator, Guide) from before the museum had five roles, a
 * password lifecycle and a DTR. All of that lives on `staff`, which is what
 * `config/auth.php` points the `web` guard at. There is no User model.
 *
 * Two tables that look like they hold accounts is a question every reader of
 * the schema has to stop and resolve, and the answer costs nothing to remove.
 *
 * The scaffold migration also creates `password_reset_tokens` and `sessions`,
 * which are both in use, so it stays; only this one table goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('users');
    }

    /**
     * Recreated exactly as the scaffold migration had it, so rolling back
     * lands on the same schema this migration found.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['Administrator', 'Curator', 'Guide'])->default('Administrator');
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
