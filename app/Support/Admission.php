<?php

namespace App\Support;

use App\Models\AdmissionDiscount;
use App\Models\MuseumInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays what at the door, in one place.
 *
 * Three settings on the Museum Info page decide it:
 *
 *   the fee           the flat per-head price (MuseumInfo::admissionFee)
 *   resident scope    which locals enter free - Baler's barangays, or every
 *                     town in Aurora. A local always enters free; the scope
 *                     only changes who counts as one and what the form asks.
 *   discounts         senior citizens, PWDs, children under N... each with
 *                     a percentage off (100 = free) and the proof the desk
 *                     checks (AdmissionDiscount)
 *
 * The desk, the visitor API, the visitor app (through GET /api/v1/museum)
 * and the About screen all price from here, so the poster, the sign-up fee
 * box and the counter cannot disagree.
 *
 * Money is only ever worked out on the server. The app shows a preview from
 * the same numbers, but what it sends is a category id, never a price.
 */
class Admission
{
    public const SCOPES = ['baler' => 'Baler', 'aurora' => 'Aurora'];

    public const DEFAULT_SCOPE = 'baler';

    /** 'baler' or 'aurora'. Resolved once per request, like the fee. */
    public static function scope(): string
    {
        return once(function (): string {
            if (!Schema::hasColumn('museum_info', 'resident_scope')) {
                return self::DEFAULT_SCOPE;
            }

            $stored = MuseumInfo::query()->value('resident_scope');

            return array_key_exists((string) $stored, self::SCOPES) ? $stored : self::DEFAULT_SCOPE;
        });
    }

    /** "Baler" or "Aurora" - for "How many are from Baler?" and the like. */
    public static function residentPlace(?string $scope = null): string
    {
        return self::SCOPES[$scope ?? self::scope()] ?? self::SCOPES[self::DEFAULT_SCOPE];
    }

    public static function fee(): float
    {
        return MuseumInfo::admissionFee();
    }

    /**
     * The categories on offer today, in the order the admin set.
     *
     * @return Collection<int, AdmissionDiscount>
     */
    public static function discounts(): Collection
    {
        return once(function (): Collection {
            if (!Schema::hasTable('admission_discounts')) {
                return collect();
            }

            return AdmissionDiscount::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('discount_id')
                ->get();
        });
    }

    /** An active category by id, or null. An inactive one cannot be claimed. */
    public static function discount(int|string|null $id): ?AdmissionDiscount
    {
        if ($id === null || $id === '') {
            return null;
        }

        return self::discounts()->firstWhere('discount_id', (int) $id);
    }

    /**
     * Why a claim to a category cannot be accepted, or null when it can.
     * The desk and the visitor API both ask this, so they refuse the same
     * claims in the same words.
     */
    public static function refusal(?AdmissionDiscount $discount, int|string|null $age): ?string
    {
        if ($discount === null) {
            return 'That discount is no longer offered. Pick another, or none.';
        }

        $age = $age === null || $age === '' ? null : (int) $age;
        if (!$discount->fitsAge($age)) {
            return $age === null
                ? "{$discount->name} is for ages {$discount->age_range}. Enter the visitor's age."
                : "{$discount->name} is for ages {$discount->age_range}.";
        }

        return null;
    }

    /** The fee with a percentage off, to the centavo. */
    public static function discounted(float $fee, int $percentOff): float
    {
        $percentOff = max(0, min(100, $percentOff));

        return round($fee * (100 - $percentOff) / 100, 2);
    }

    /**
     * What one visitor owes. Locals nothing; everyone else the fee, less any
     * category they claimed.
     */
    public static function priceFor(string $visitorType, ?AdmissionDiscount $discount = null): float
    {
        if ($visitorType === 'Local') {
            return 0.00;
        }

        return self::discounted(self::fee(), $discount?->percent_off ?? 0);
    }

    /**
     * Where a local is from, made to fit the resident scope.
     *
     * Under 'baler' the town is always Baler and the barangay is what they
     * state; under 'aurora' they state the town, and a barangay is kept only
     * for Baler, the one town whose barangays the system knows. Anything
     * that does not fit is dropped rather than stored - the returned array
     * says which field was missing so a caller that requires it can refuse.
     *
     * @return array{city: ?string, barangay: ?string, province: string, country: string, missing: ?string}
     */
    public static function residence(?string $city, ?string $barangay): array
    {
        $out = ['province' => 'Aurora', 'country' => 'Philippines', 'missing' => null];

        if (self::scope() === 'aurora') {
            $town = AuroraTowns::isOne($city) ? $city : null;

            return $out + [
                'city'     => $town,
                'barangay' => $town === 'Baler' && BalerBarangays::isOne($barangay) ? $barangay : null,
                'missing'  => $town === null ? 'city' : null,
            ];
        }

        $brgy = BalerBarangays::isOne($barangay) ? $barangay : null;

        return $out + [
            'city'     => 'Baler',
            'barangay' => $brgy,
            'missing'  => $brgy === null ? 'barangay' : null,
        ];
    }

    /**
     * The categories a party claimed, from the desk's counts, as the snapshot
     * that is stored on the group. Only active categories count, and only
     * those with somebody in them.
     *
     * @param  array<int|string, int|string|null>  $counts  discount id => heads
     * @return list<array{id: int, name: string, percent_off: int, count: int}>
     */
    public static function groupDiscounts(array $counts): array
    {
        $out = [];
        foreach (self::discounts() as $d) {
            $n = (int) ($counts[$d->discount_id] ?? 0);
            if ($n > 0) {
                $out[] = [
                    'id'          => (int) $d->discount_id,
                    'name'        => $d->name,
                    'percent_off' => (int) $d->percent_off,
                    'count'       => $n,
                ];
            }
        }

        return $out;
    }

    /**
     * What a party owes: full price for everyone who is neither a local nor
     * in a category, the discounted price for each head in one, nothing for
     * locals. A Local party owes nothing at all.
     *
     * Priced from the snapshot, not the live categories, so re-pricing a
     * group later (a correction at the desk) uses the percentages it was
     * registered under.
     *
     * `lines` is the same sum itemised, for the transaction's breakdown.
     *
     * @param  list<array{name?: string, percent_off: int, count: int}>  $discounts
     * @return array{paying: int, fee: float, over: bool, lines: list<array{label: string, count: int, unit: float, amount: float}>}
     *               over = more locals and discounted heads than the party has
     */
    public static function groupPrice(string $visitorType, int $headcount, int $localCount, array $discounts, ?float $fee = null): array
    {
        $residents = self::residentPlace() . ' residents';

        if ($visitorType === 'Local') {
            return ['paying' => 0, 'fee' => 0.00, 'over' => false, 'lines' => [self::line($residents, $headcount, 0.0)]];
        }

        $fee         = $fee ?? self::fee();
        $localCount  = max(0, $localCount);
        $discounted  = array_sum(array_map(fn ($d) => max(0, (int) $d['count']), $discounts));
        $fullPayers  = $headcount - $localCount - $discounted;

        $lines  = [self::line('Full admission', max(0, $fullPayers), $fee)];
        $paying = max(0, $fullPayers);
        foreach ($discounts as $d) {
            $each    = self::discounted($fee, (int) $d['percent_off']);
            $lines[] = self::line(($d['name'] ?? 'Discount') . ' (' . ((int) $d['percent_off'] >= 100 ? 'free' : $d['percent_off'] . '% off') . ')',
                max(0, (int) $d['count']), $each);
            if ($each > 0) {
                $paying += max(0, (int) $d['count']);
            }
        }
        $lines[] = self::line($residents, $localCount, 0.0);

        $lines = array_values(array_filter($lines, fn ($l) => $l['count'] > 0));
        $total = array_sum(array_column($lines, 'amount'));

        return ['paying' => $paying, 'fee' => round($total, 2), 'over' => $fullPayers < 0, 'lines' => $lines];
    }

    /**
     * One visitor's charge as a single breakdown line, in the same shape as
     * groupPrice()'s.
     *
     * @return list<array{label: string, count: int, unit: float, amount: float}>
     */
    public static function visitorLines(string $visitorType, float $charged, ?string $discountName, ?int $percentOff): array
    {
        $label = match (true) {
            $visitorType === 'Local' => self::residentPlace() . ' resident',
            $discountName !== null   => $discountName . ' (' . ((int) $percentOff >= 100 ? 'free' : $percentOff . '% off') . ')',
            default                  => 'Full admission',
        };

        return [self::line($label, 1, $charged)];
    }

    /** @return array{label: string, count: int, unit: float, amount: float} */
    private static function line(string $label, int $count, float $unit): array
    {
        return ['label' => $label, 'count' => $count, 'unit' => round($unit, 2), 'amount' => round($unit * $count, 2)];
    }

    /**
     * The one line every screen shows for admission.
     *
     *   Baler residents enter free with a valid ID · Senior citizen
     *   (60 and over): 20% off · Visitors ₱50.00
     */
    public static function sentence(float $fee, ?string $scope = null): string
    {
        if ($fee <= 0) {
            return 'Free for all visitors';
        }

        $parts = [self::residentPlace($scope) . ' residents enter free with a valid ID'];

        foreach (self::discounts() as $d) {
            $parts[] = $d->name . ($d->age_range ? " ({$d->age_range})" : '') . ': ' . $d->benefit;
        }

        $parts[] = 'Visitors ₱' . number_format($fee, 2);

        return implode(' · ', $parts);
    }

    /** Everything the visitor app and the desk script need to price and ask. */
    public static function forClients(): array
    {
        return [
            'fee'            => self::fee(),
            'resident_scope' => self::scope(),
            'resident_place' => self::residentPlace(),
            'barangays'      => BalerBarangays::ALL,
            'towns'          => AuroraTowns::ALL,
            'discounts'      => self::discounts()->map->toPublicArray()->values()->all(),
        ];
    }
}
