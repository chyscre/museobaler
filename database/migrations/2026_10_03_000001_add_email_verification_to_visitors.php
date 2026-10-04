<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof that a visitor owns the inbox they signed up with.
     *
     * email_verified_at is stamped by the 6-digit code from sign-up, by a
     * completed password reset (the same proof), or by Google, which has
     * already checked the address. No session token is issued before it.
     *
     * google_id is Google's stable account number ("sub"), so a visitor who
     * later changes the address on their Google account still lands on the
     * same museum record.
     *
     * visitor_email_verifications holds the sign-up code, one row per
     * visitor at most, HMAC'd the same way as visitor_password_resets.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('google_id', 64)->nullable()->unique()->after('auth_provider');
        });

        // Accounts made before this existed already sign in with a password
        // and have been using the app. Locking them out behind a code they
        // never asked for would strand visitors at the door, so they are
        // counted as verified. Desk-made records have no password and cannot
        // sign in until a password reset, which verifies them then.
        DB::table('visitors')
            ->whereNotNull('password')
            ->whereNotNull('email')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        Schema::create('visitor_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->unique()
                ->constrained('visitors', 'visitor_id')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            // When the last email went out, for the resend cooldown.
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_email_verifications');

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['email_verified_at', 'google_id']);
        });
    }
};
