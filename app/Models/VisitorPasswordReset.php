<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A forgotten-password request: the six-digit code emailed to a visitor,
 * and once that is typed back, the token that sets the new password.
 *
 * SECURITY: neither value is kept as sent. Both are HMAC'd with the app key,
 * so a copy of this table alone cannot be brute-forced back into a working
 * code - with a plain hash, a million candidates would take a second.
 */
class VisitorPasswordReset extends Model
{
    /** How long an emailed code works, and then the token it is traded for. */
    public const TTL_MINUTES = 15;

    /** Wrong codes before the request is void and a new code must be sent. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['visitor_id', 'code_hash', 'token_hash', 'attempts', 'expires_at'];

    protected $hidden = ['code_hash', 'token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Start a request for this visitor, replacing any earlier one, and
     * return the code to email. A new code always voids the last: only the
     * newest email in the inbox works.
     */
    public static function issueFor(Visitor $visitor): string
    {
        // random_int() is a cryptographically secure source; the leading
        // zeros are part of the code, so "004213" stays six digits.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        self::updateOrCreate(['visitor_id' => $visitor->visitor_id], [
            'code_hash'  => self::digest($code),
            'token_hash' => null,
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check a typed code. Right: the code is spent and a reset token is
     * returned in its place. Wrong: the guess is counted, and the request
     * is deleted on the last one allowed.
     */
    public function redeem(string $code): ?string
    {
        if ($this->code_hash === null || !hash_equals($this->code_hash, self::digest($code))) {
            $this->increment('attempts');
            if ($this->attempts >= self::MAX_ATTEMPTS) {
                $this->delete();
            }
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $this->forceFill([
            'code_hash'  => null,
            'token_hash' => self::digest($token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ])->save();

        return $token;
    }

    public function tokenMatches(string $token): bool
    {
        return $this->token_hash !== null
            && !$this->isExpired()
            && hash_equals($this->token_hash, self::digest($token));
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
