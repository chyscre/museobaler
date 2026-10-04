<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per visitor per day they came: the visitor's history.
     *
     * A visitors row is a person AND their current visit. Coming back
     * overwrites the visit half - last_visit, the fee, payment and ID check,
     * the group (Visitor::touchReturning, joinGroup) - so until now a
     * returning visitor's earlier visits survived only as a check-in date
     * at best, and how each one was paid for was gone.
     *
     * A row here is a copy of the visit half, taken for the day it happened.
     * It is written whenever the visitor row changes on that day (see
     * App\Models\Visit::record) and never after, so a later visit adds a
     * row and cannot rewrite an earlier one. The visitor row keeps working
     * exactly as before; nothing reads clearance from here.
     *
     * Rows for visits before this table existed are rebuilt from what is
     * left and marked `backfilled`. The last visit is copied whole, since
     * the visitor row still describes it. Earlier days (the sign-up day, a
     * check-in day) only say that they came: what they paid that day was
     * overwritten before it could be kept.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id('visit_id');
            $table->foreignId('visitor_id')->nullable()
                ->constrained('visitors', 'visitor_id')->nullOnDelete();
            $table->date('visit_date');
            $table->timestamp('arrived_at')->nullable();
            $table->foreignId('group_id')->nullable()
                ->constrained('visit_groups', 'group_id')->nullOnDelete();
            $table->string('visitor_type', 20)->nullable();
            $table->string('visit_type', 20)->nullable();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('discount_name', 80)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->decimal('admission_fee', 10, 2)->nullable();
            $table->string('payment_status', 20)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->boolean('id_verified')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('source', 20)->nullable();
            $table->boolean('backfilled')->default(false);
            $table->timestamps();

            $table->unique(['visitor_id', 'visit_date']);
            $table->index('visit_date');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now  = now();
        $days = [];   // visitor_id => [date => row]

        DB::table('visitors')->orderBy('visitor_id')->chunk(500, function ($visitors) use (&$days, $now) {
            foreach ($visitors as $v) {
                $base = [
                    'visitor_id'   => $v->visitor_id,
                    'visitor_type' => $v->visitor_type,
                    'source'       => $v->source,
                    'backfilled'   => true,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];

                if ($v->created_at) {
                    $d = substr((string) $v->created_at, 0, 10);
                    $days[$v->visitor_id][$d] = $base + ['visit_date' => $d, 'arrived_at' => $v->created_at];
                }

                // The visit the row still describes, copied whole. Written
                // last so it wins over the sign-up day when they coincide.
                if ($v->last_visit) {
                    $d = substr((string) $v->last_visit, 0, 10);
                    $days[$v->visitor_id][$d] = $base + [
                        'visit_date'       => $d,
                        'arrived_at'       => $days[$v->visitor_id][$d]['arrived_at'] ?? $v->last_visit,
                        'group_id'         => $v->group_id,
                        'visit_type'       => $v->visit_type,
                        'discount_id'      => $v->discount_id,
                        'discount_name'    => $v->discount_name,
                        'discount_percent' => $v->discount_percent,
                        'admission_fee'    => $v->admission_fee,
                        'payment_status'   => $v->payment_status,
                        'paid_at'          => $v->paid_at,
                        'id_verified'      => $v->id_verified,
                        'verified_at'      => $v->verified_at,
                    ];
                }
            }
        });

        // A geofence check-in is a day they were here, even with no other trace.
        DB::table('attendances')->whereNotNull('visitor_id')
            ->orderBy('attendance_id')
            ->get(['visitor_id', 'visit_date', 'created_at'])
            ->each(function ($a) use (&$days, $now) {
                $d = substr((string) $a->visit_date, 0, 10);
                if (isset($days[$a->visitor_id][$d])) {
                    return;
                }
                $days[$a->visitor_id][$d] = [
                    'visitor_id' => $a->visitor_id,
                    'visit_date' => $d,
                    'arrived_at' => $a->created_at,
                    'backfilled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

        // insert() needs every row to carry the same keys.
        $columns = array_fill_keys([
            'visitor_id', 'visit_date', 'arrived_at', 'group_id', 'visitor_type', 'visit_type',
            'discount_id', 'discount_name', 'discount_percent', 'admission_fee', 'payment_status',
            'paid_at', 'id_verified', 'verified_at', 'source', 'backfilled', 'created_at', 'updated_at',
        ], null);

        $rows = [];
        foreach ($days as $perDay) {
            foreach ($perDay as $row) {
                $rows[] = array_merge($columns, $row);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('visits')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
