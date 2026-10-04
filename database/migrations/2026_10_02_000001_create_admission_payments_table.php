<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ledger of every peso that crosses the admission counter.
     *
     * Until now the only record of a payment was the payer's own row:
     * payment_status and paid_at on a visitor or a group. That row describes
     * the CURRENT visit, so it is overwritten. A paying visitor who comes back
     * on a later day is reset to Unpaid (Visitor::touchReturning), and the
     * earlier payment disappears from every total. A group that was corrected
     * upward and paid the difference shows only its latest state. Earnings
     * read from those columns undercount by exactly the people who came back.
     *
     * One row per event, never updated:
     *
     *   kind = payment   money in: Mark Paid on a visitor, or a group paying
     *                    (a second payment after an upward correction is its
     *                    own row, for the difference only)
     *   kind = refund    money out: a paid group corrected downward
     *
     * The amount is always positive; kind says which way it went. The payer's
     * name and visitor type are copied in, so the ledger still reads the same
     * after a visitor row is deleted or edited.
     *
     * Rows that existed before this migration are rebuilt from what the
     * visitor and group rows still say, and marked `backfilled`. A returning
     * visitor's EARLIER payments were overwritten before this table existed,
     * so they cannot be recovered. The backfill gives a floor, not the full
     * history.
     */
    public function up(): void
    {
        Schema::create('admission_payments', function (Blueprint $table) {
            $table->id('payment_id');
            $table->enum('kind', ['payment', 'refund'])->default('payment');
            $table->enum('payer', ['individual', 'group']);
            $table->foreignId('visitor_id')->nullable()
                ->constrained('visitors', 'visitor_id')->nullOnDelete();
            $table->foreignId('group_id')->nullable()
                ->constrained('visit_groups', 'group_id')->nullOnDelete();
            $table->string('payer_name')->nullable();
            $table->string('visitor_type', 20)->nullable();
            $table->unsignedInteger('headcount')->default(1);
            $table->decimal('amount', 10, 2);
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()
                ->constrained('staff', 'staff_id')->nullOnDelete();
            $table->boolean('backfilled')->default(false);
            $table->timestamps();

            $table->index('recorded_at');
            $table->index(['payer', 'recorded_at']);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now  = now();
        $rows = [];

        $visitors = DB::table('visitors')
            ->where('payment_status', 'Paid')
            ->whereNotNull('paid_at')
            ->where('admission_fee', '>', 0)
            ->orderBy('paid_at')
            ->get(['visitor_id', 'first_name', 'last_name', 'visitor_type', 'admission_fee', 'paid_at']);

        foreach ($visitors as $v) {
            $rows[] = [
                'kind'         => 'payment',
                'payer'        => 'individual',
                'visitor_id'   => $v->visitor_id,
                'group_id'     => null,
                'payer_name'   => trim($v->first_name . ' ' . $v->last_name),
                'visitor_type' => $v->visitor_type,
                'headcount'    => 1,
                'amount'       => $v->admission_fee,
                'recorded_at'  => $v->paid_at,
                'recorded_by'  => null,
                'backfilled'   => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        // A paid group took in what it owes now plus whatever it was handed
        // back (VisitGroup::grossCollected). The hand-back is its own row.
        $groups = DB::table('visit_groups')
            ->where('payment_status', 'Paid')
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->get(['group_id', 'group_name', 'contact_name', 'visitor_type', 'headcount',
                   'total_fee', 'refunded_amount', 'refunded_at', 'refunded_by', 'paid_at']);

        foreach ($groups as $g) {
            $refunded = (float) ($g->refunded_amount ?? 0);
            $gross    = (float) $g->total_fee + $refunded;

            if ($gross <= 0) {
                continue;
            }

            $base = [
                'payer'        => 'group',
                'visitor_id'   => null,
                'group_id'     => $g->group_id,
                'payer_name'   => $g->group_name ?: $g->contact_name,
                'visitor_type' => $g->visitor_type,
                'headcount'    => (int) $g->headcount,
                'backfilled'   => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];

            $rows[] = $base + [
                'kind'        => 'payment',
                'amount'      => $gross,
                'recorded_at' => $g->paid_at,
                'recorded_by' => null,
            ];

            if ($refunded > 0) {
                $rows[] = $base + [
                    'kind'        => 'refund',
                    'amount'      => $refunded,
                    'recorded_at' => $g->refunded_at ?? $g->paid_at,
                    'recorded_by' => $g->refunded_by,
                ];
            }
        }

        // Ordered by when the money moved, so transaction numbers on
        // backfilled rows still run in time order.
        usort($rows, fn ($a, $b) => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('admission_payments')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_payments');
    }
};
