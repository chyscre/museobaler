<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two 2026-10-10 data migrations, run against the museum's real pins:
 * duplicate categories fold together, and each exhibit lands in the hall
 * its map pin stands in, with that hall's category.
 */
class HallLayoutMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require database_path('migrations/' . $file);
    }

    public function test_duplicate_categories_fold_into_the_oldest_and_cannot_come_back(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(['name']));
        $first  = DB::table('categories')->insertGetId(['name' => 'History']);
        $second = DB::table('categories')->insertGetId(['name' => 'History']);
        DB::table('exhibits')->insert(['exhibit_code' => 'EXH-001', 'name' => 'A', 'category_id' => $second]);

        $this->migration('2026_10_10_000000_dedupe_categories.php')->up();

        $this->assertSame([$first], DB::table('categories')->where('name', 'History')->pluck('category_id')->all());
        $this->assertSame($first, (int) DB::table('exhibits')->value('category_id'));

        DB::table('categories')->insertOrIgnore(['name' => 'History']);
        $this->assertSame(1, DB::table('categories')->where('name', 'History')->count());
    }

    public function test_exhibits_take_the_hall_their_pin_stands_in_and_its_category(): void
    {
        $history = DB::table('categories')->insertGetId(['name' => 'History']);
        $pins = [
            'EXH-001' => [92.15, 9.07],  'EXH-002' => [87.23, 63.72], 'EXH-003' => [96.50, 8.91],
            'EXH-004' => [80.51, 58.07], 'EXH-005' => [80.89, 38.28], 'EXH-006' => [84.20, 38.60],
            'EXH-007' => [93.85, 36.71], 'EXH-008' => [78.52, 15.00], 'EXH-009' => [2.55, 15.82],
            'EXH-010' => [45.00, 60.00], // the lobby: no hall
        ];
        $order = 0;
        foreach ($pins as $code => [$x, $y]) {
            DB::table('exhibits')->insert([
                'exhibit_code' => $code, 'name' => $code, 'category_id' => $history,
                'map_x' => $x, 'map_y' => $y, 'storyline_order' => ++$order,
            ]);
        }

        $this->migration('2026_10_10_000001_lay_out_museum_halls.php')->up();

        $rows = DB::table('exhibits')
            ->leftJoin('museum_halls', 'museum_halls.hall_id', '=', 'exhibits.hall_id')
            ->leftJoin('categories', 'categories.category_id', '=', 'exhibits.category_id')
            ->orderBy('exhibit_code')
            ->get(['exhibit_code', 'museum_halls.name as hall', 'categories.name as category', 'map_x', 'storyline_order'])
            ->keyBy('exhibit_code');

        $expect = [
            'EXH-001' => ['People of Baler', 'People'],
            'EXH-002' => ['Culture and Traditions', 'Culture'],
            'EXH-003' => ['People of Baler', 'People'],
            'EXH-004' => ['Culture and Traditions', 'Culture'],
            'EXH-005' => ['People of Baler', 'People'],
            'EXH-006' => ['People of Baler', 'People'],
            'EXH-007' => ['People of Baler', 'People'],
            'EXH-008' => ['People of Baler', 'People'],
            'EXH-009' => ['Church Heritage', 'Religion'],
            'EXH-010' => [null, 'History'],
        ];
        foreach ($expect as $code => [$hall, $category]) {
            $this->assertSame($hall, $rows[$code]->hall, $code);
            $this->assertSame($category, $rows[$code]->category, $code);
        }

        // Pins and storyline stay where staff put them.
        $this->assertEquals(92.15, $rows['EXH-001']->map_x);
        $this->assertSame(9, (int) $rows['EXH-009']->storyline_order);

        // History survives while an exhibit still uses it; unused seeds go.
        $this->assertTrue(DB::table('categories')->where('name', 'History')->exists());
        $this->assertSame(
            ['Art', 'Artifacts', 'Culture', 'History', 'People', 'Religion'],
            DB::table('categories')->orderBy('name')->pluck('name')->all()
        );
        $this->assertSame(5, DB::table('museum_halls')->count());
    }
}
