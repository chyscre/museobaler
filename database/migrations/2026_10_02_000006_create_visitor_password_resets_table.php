<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A visitor's forgotten-password request, one row per visitor at most.
     *
     * Not Laravel's password_reset_tokens table: that one holds a link token
     * keyed by email, and this flow is a six-digit code typed on a phone,
     * which needs a count of wrong guesses beside it - a million codes is a
     * small enough space that the count is what keeps it from being guessed.
     *
     * Two stages share the row. The code is checked first (code_hash); a
     * right code is swapped for a long random token (token_hash) that only
     * the phone that typed the code holds, and that token is what sets the
     * new password. Neither is stored as typed. See VisitorPasswordReset.
     */
    public function up(): void
    {
        Schema::create('visitor_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->unique()
                ->constrained('visitors', 'visitor_id')->cascadeOnDelete();
            $table->string('code_hash', 64)->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_password_resets');
    }
};
