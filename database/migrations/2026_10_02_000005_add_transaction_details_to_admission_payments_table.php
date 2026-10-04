<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every payment and refund gets a transaction number the desk can say
     * out loud and a person can find again: MDB-20261002-0001, the museum,
     * the day, and its place in that day's sequence.
     *
     *   reference    stored, unique. It used to be TXN-000123 worked out from
     *                the key, which told nobody which day it was from and
     *                restarted nowhere. See AdmissionPayment::nextReference.
     *   visit_id     for an individual's payment, the visit it paid for, so
     *                a returning visitor's payments line up with their
     *                history (visits table)
     *   visitor_ids  who it covered: the visitor, or the members of a group
     *                who had joined from their phones when it was paid
     *   breakdown    how the amount was made up - so many at full price, so
     *                many seniors at nothing, so many locals - copied at the
     *                time, so a later change to the fee or a category does
     *                not rewrite what this receipt says
     *
     * Rows already in the ledger are numbered by when the money moved.
     */
    public function up(): void
    {
        Schema::table('admission_payments', function (Blueprint $table) {
            $table->string('reference', 24)->nullable()->unique()->after('payment_id');
            $table->foreignId('visit_id')->nullable()->after('visitor_id')
                ->constrained('visits', 'visit_id')->nullOnDelete();
            $table->json('visitor_ids')->nullable()->after('headcount');
            $table->json('breakdown')->nullable()->after('amount');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $seq = [];

        DB::table('admission_payments')
            ->orderBy('recorded_at')->orderBy('payment_id')
            ->get(['payment_id', 'payer', 'visitor_id', 'recorded_at'])
            ->each(function ($p) use (&$seq) {
                $day = substr((string) $p->recorded_at, 0, 10);
                $seq[$day] = ($seq[$day] ?? 0) + 1;

                $update = [
                    'reference' => 'MDB-' . str_replace('-', '', $day) . '-' . str_pad((string) $seq[$day], 4, '0', STR_PAD_LEFT),
                ];

                if ($p->payer === 'individual' && $p->visitor_id) {
                    $update['visitor_ids'] = json_encode([(int) $p->visitor_id]);
                    $update['visit_id']    = DB::table('visits')
                        ->where('visitor_id', $p->visitor_id)
                        ->where('visit_date', $day)
                        ->value('visit_id');
                }

                DB::table('admission_payments')->where('payment_id', $p->payment_id)->update($update);
            });
    }

    public function down(): void
    {
        Schema::table('admission_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id');
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'visitor_ids', 'breakdown']);
        });
    }
};
