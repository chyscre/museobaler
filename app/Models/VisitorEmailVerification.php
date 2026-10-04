<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The six-digit code emailed when a visitor signs up with a password.
 *
 * Same shape as VisitorPasswordReset: the code is HMAC'd with the app key
 * rather than stored as sent, a new code voids the last, and five wrong
 * guesses void the code. A sign-up code is spent the moment it is typed
 * correctly, so there is no second token stage.
 */
class VisitorEmailVerification extends Model
{
    /** How long an emailed code works. */
    public const TTL_MINUTES = 15;

    /** Wrong codes before this one is void and a new one must be sent. */
    public const MAX_ATTEMPTS = 5;

    /** How long the app waits before it offers "Resend Code". */
    public const RESEND_SECONDS = 60;

    protected $fillable = ['visitor_id', 'code_hash', 'attempts', 'expires_at', 'sent_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    /**
     * Start a code for this visitor, replacing any earlier one, and return
     * it to email. Only the newest email in the inbox works.
     */
    public static function issueFor(Visitor $visitor): string
    {
        // random_int() is a cryptographically secure source; the leading
        // zeros are part of the code, so "004213" stays six digits.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        self::updateOrCreate(['visitor_id' => $visitor->visitor_id], [
            'code_hash'  => self::digest($code),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'sent_at'    => now(),
        ]);

        return $code;
    }

    /** Seconds until another code may be sent; 0 when one may go now. */
    public static function cooldownFor(Visitor $visitor): int
    {
        $row = self::where('visitor_id', $visitor->visitor_id)->first();

        if ($row === null) return 0;

        $ready = $row->sent_at->copy()->addSeconds(self::RESEND_SECONDS);

        return $ready->isFuture() ? (int) ceil(now()->diffInSeconds($ready, true)) : 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check a typed code. Right: the row is deleted and true returned.
     * Wrong: the guess is counted, and the row is deleted on the last one
     * allowed, so the visitor has to ask for a new code.
     */
    public function redeem(string $code): bool
    {
        if (!hash_equals($this->code_hash, self::digest($code))) {
            $this->increment('attempts');
            if ($this->attempts >= self::MAX_ATTEMPTS) {
                $this->delete();
            }
            return false;
        }

        $this->delete();

        return true;
    }

    private static function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id', 'visitor_id');
    }
}
