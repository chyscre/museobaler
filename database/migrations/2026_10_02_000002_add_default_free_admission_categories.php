<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Senior citizens, PWDs and children seven and under enter free.
     *
     * The categories have been a museum setting since add_admission_rules,
     * but a fresh install started with none, so the desk had nothing to
     * offer them until somebody set the three up by hand. These are the
     * museum's rules, so they are put in for it.
     *
     * A category already set up under the same name is left exactly as the
     * administrator made it, even at a different percentage: a museum that
     * chose the statutory 20% for seniors has made a decision, and this must
     * not quietly make them free. All three stay editable on Museum Info.
     */
    private const DEFAULTS = [
        ['name' => 'Senior citizen', 'proof' => 'Senior citizen ID', 'min_age' => 60,   'max_age' => null, 'sort_order' => 1],
        ['name' => 'PWD',            'proof' => 'PWD ID',            'min_age' => null, 'max_age' => null, 'sort_order' => 2],
        ['name' => 'Child',          'proof' => 'Proof of age',      'min_age' => null, 'max_age' => 7,    'sort_order' => 3],
    ];

    public function up(): void
    {
        $existing = DB::table('admission_discounts')->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim($n)))->all();

        $now = now();
        foreach (self::DEFAULTS as $row) {
            if (in_array(mb_strtolower($row['name']), $existing, true)) {
                continue;
            }

            DB::table('admission_discounts')->insert($row + [
                'percent_off' => 100,
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Left in place: by now they may have been edited, claimed by
        // visitors and copied into group snapshots.
    }
};
