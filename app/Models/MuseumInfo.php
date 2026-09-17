<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MuseumInfo extends Model
{
    protected $table = 'museum_info';
    protected $primaryKey = 'info_id';

    /**
     * What the museum charges when nobody has set anything yet: the fee it
     * has always charged. Baler locals enter free regardless; this is the
     * flat per-head amount for everyone else.
     */
    public const DEFAULT_ADMISSION_FEE = 50.00;

    protected $fillable = [
        'name', 'tagline', 'story', 'story2',
        'address', 'hours', 'closed_on', 'phone', 'email',
        'admission', 'admission_fee',
        'latitude', 'longitude', 'geofence_radius_m',
    ];

    protected function casts(): array
    {
        return [
            'latitude'      => 'decimal:7',
            'longitude'     => 'decimal:7',
            'admission_fee' => 'decimal:2',
        ];
    }

    /**
     * The admission fee in pesos, as set on the Museum Info page.
     *
     * The single source for every screen that prints or charges it: the
     * desk register, the poster, the visitor model, the reports. The visitor
     * API reads the same row with its own connection - see
     * public/api/_museum.php - and must stay in step with this.
     *
     * Resolved once per request (the desk asks on every arrival) and
     * forgotten whenever the row is saved.
     */
    public static function admissionFee(): float
    {
        return once(function (): float {
            if (!Schema::hasColumn('museum_info', 'admission_fee')) {
                return self::DEFAULT_ADMISSION_FEE;
            }

            $stored = self::query()->value('admission_fee');

            return $stored === null ? self::DEFAULT_ADMISSION_FEE : (float) $stored;
        });
    }

    public static function forgetAdmissionFee(): void
    {
        \Illuminate\Support\Once::flush();
    }

    /**
     * The one sentence every screen shows for admission, generated from the
     * fee so it can never disagree with what the desk actually collects.
     */
    public static function admissionSentence(float $fee): string
    {
        if ($fee <= 0) {
            return 'Free for all visitors';
        }

        return 'Baler residents enter free with a valid ID · Visitors ₱' . number_format($fee, 2);
    }

    public function getAdmissionSentenceAttribute(): string
    {
        return self::admissionSentence((float) ($this->admission_fee ?? self::DEFAULT_ADMISSION_FEE));
    }

    protected static function booted(): void
    {
        // The text column is derived, never typed: keep it in step with the
        // number so anything still reading `admission` gets the right line.
        static::saving(function (self $info) {
            if ($info->admission_fee !== null) {
                $info->admission = self::admissionSentence((float) $info->admission_fee);
            }
        });

        static::saved(fn () => self::forgetAdmissionFee());
    }
}
