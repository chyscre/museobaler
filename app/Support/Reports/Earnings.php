<?php

namespace App\Support\Reports;

use App\Models\AdmissionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admission revenue for a date range, read from the payments ledger.
 *
 * Both the earnings page and its downloads come from this one calculation,
 * the same way ReportBuilder keeps the other downloads in step.
 *
 * It reads AdmissionPayment, not the visitor and group rows. Those describe
 * the current visit and are reset when a visitor comes back, so totals
 * built on them lose every earlier payment by a returning visitor.
 *
 * "Transactions" counts payments. Refunds are counted and totalled on their
 * own, and net is collected minus refunded: what the cash drawer should
 * hold.
 */
final class Earnings
{
    /** Ranges longer than this are broken down by month, not by day. */
    public const DAILY_LIMIT = 62;

    public static function build(Carbon $from, Carbon $to): array
    {
        $payments = AdmissionPayment::with('recordedBy')
            ->whereBetween('recorded_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->orderBy('payment_id')
            ->get();

        $byPayer = [];
        foreach ([AdmissionPayment::INDIVIDUAL => 'Individual', AdmissionPayment::GROUP => 'Group'] as $payer => $label) {
            $byPayer[$payer] = ['label' => $label] + self::tally($payments->where('payer', $payer));
        }

        $monthly = $from->diffInDays($to) + 1 > self::DAILY_LIMIT;

        return [
            'from'      => $from,
            'to'        => $to,
            'monthly'   => $monthly,
            'payments'  => $payments,
            'byPayer'   => $byPayer,
            'periods'   => self::periods($payments, $from, $to, $monthly),
            'totals'    => self::tally($payments),
            'backfilled'=> $payments->where('backfilled', true)->count(),
        ];
    }

    /** @return array{transactions:int, headcount:int, collected:float, refunds:int, refunded:float, net:float} */
    private static function tally(Collection $rows): array
    {
        $paid    = $rows->where('kind', AdmissionPayment::PAYMENT);
        $refunds = $rows->where('kind', AdmissionPayment::REFUND);

        $collected = round((float) $paid->sum(fn ($p) => (float) $p->amount), 2);
        $refunded  = round((float) $refunds->sum(fn ($p) => (float) $p->amount), 2);

        return [
            'transactions' => $paid->count(),
            'headcount'    => (int) $paid->sum('headcount'),
            'collected'    => $collected,
            'refunds'      => $refunds->count(),
            'refunded'     => $refunded,
            'net'          => round($collected - $refunded, 2),
        ];
    }

    /**
     * Every day (or month) in the range, including the empty ones, so a
     * day with no takings shows as zero rather than going missing.
     */
    private static function periods(Collection $payments, Carbon $from, Carbon $to, bool $monthly): array
    {
        $keyOf = fn (Carbon $d) => $monthly ? $d->format('Y-m') : $d->toDateString();

        $grouped = $payments->groupBy(fn ($p) => $keyOf($p->recorded_at));

        $periods = [];
        $cursor  = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $rows = $grouped->get($keyOf($cursor), collect());

            $periods[] = [
                'date'       => $cursor->copy(),
                'label'      => $monthly ? $cursor->format('F Y') : $cursor->format('D, M j'),
                'individual' => self::tally($rows->where('payer', AdmissionPayment::INDIVIDUAL)),
                'group'      => self::tally($rows->where('payer', AdmissionPayment::GROUP)),
                'all'        => self::tally($rows),
            ];

            $monthly ? $cursor->addMonthNoOverflow() : $cursor->addDay();
        }

        return $periods;
    }
}
