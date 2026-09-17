<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visitor accounts are now password protected, and the visitor app holds a
     * bearer token instead of trusting a visitor_id posted by the client.
     *
     * `password` and `api_token` are both stored hashed — the raw token is
     * shown to the client once, at sign-in, and never persisted server-side.
     * Legacy rows registered before this keep a NULL password; they cannot
     * sign in until they register again with one.
     */
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->string('api_token', 64)->nullable()->after('password');
            $table->timestamp('token_expires_at')->nullable()->after('api_token');
            $table->index('api_token');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['api_token']);
            $table->dropColumn(['password', 'api_token', 'token_expires_at']);
        });
    }
};
