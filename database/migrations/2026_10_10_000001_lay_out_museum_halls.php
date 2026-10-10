<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The halls as the museum's own framed floor plan divides the building:
     * on the 1st floor the left wing is the tangible collection with the
     * church pieces along its west wall, the right wing is the people of
     * Baler with culture and traditions below them and down the side
     * corridor; the whole 2nd floor is the art gallery. Each hall has the
     * category of the same theme, so an exhibit's category says what its
     * hall says. Listed in walking order from the entrance.
     *
     * name => [floor, icon, description, category]
     */
    private const HALLS = [
        'Tangible Collection'    => ['Ground Floor', 'inventory_2', 'Objects from Baler\'s past that you can see up close: tools, household items and other artifacts.', 'Artifacts'],
        'Church Heritage'        => ['Ground Floor', 'church',      'Religious artifacts and the story of the Baler church, where the Siege of Baler was fought.', 'Religion'],
        'People of Baler'        => ['Ground Floor', 'groups',      'The soldiers, leaders and townsfolk behind Baler\'s history.', 'People'],
        'Culture and Traditions' => ['Ground Floor', 'celebration', 'The customs, crafts and celebrations of Baler and Aurora.', 'Culture'],
        'Art Gallery'            => ['2nd Floor',    'palette',     'The entire second floor: paintings and other works by artists.', 'Art'],
    ];

    /**
     * Where each 1st-floor hall lies on the map, as [x1, y1, x2, y2] in
     * percent of the floor plan - the same areas the Museum Map draws.
     * Checked in this order; the first area a pin falls in is its hall.
     */
    private const AREAS = [
        'Culture and Traditions' => [[65.24, 46.0, 98.62, 67.0], [91.57, 38.0, 98.62, 46.0]],
        'People of Baler'        => [[65.24, 5.8, 98.62, 46.0]],
        'Church Heritage'        => [[0.84, 13.0, 6.02, 94.1]],
        'Tangible Collection'    => [[6.02, 4.0, 34.04, 37.0], [6.02, 46.0, 37.35, 94.0], [61.57, 5.8, 64.88, 37.8]],
    ];

    /** Seeded categories no hall uses; dropped once no exhibit is on them. */
    private const RETIRED = ['History', 'Nature', 'Science'];

    /**
     * Gives each exhibit without a hall the hall its map pin stands in -
     * the pin is where staff put the piece, so it is the source of truth,
     * and neither pins nor storyline numbers are touched. Then every
     * exhibit in these halls takes the hall's category. A hall or category
     * that already exists under the same name is reused; a database with
     * no exhibits (a fresh install, the test database) is left alone.
     */
    public function up(): void
    {
        if (! DB::table('exhibits')->exists()) {
            return;
        }

        $halls = [];
        $sort = 0;
        foreach (self::HALLS as $name => [$floor, $icon, $description, $category]) {
            $categoryId = DB::table('categories')->where('name', $category)->value('category_id')
                ?? DB::table('categories')->insertGetId(['name' => $category, 'created_at' => now(), 'updated_at' => now()]);

            $hallId = DB::table('museum_halls')->where('name', $name)->value('hall_id')
                ?? DB::table('museum_halls')->insertGetId([
                    'name'        => $name,
                    'floor'       => $floor,
                    'icon'        => $icon,
                    'description' => $description,
                    'sort_order'  => ++$sort,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

            $halls[$name] = [$hallId, $categoryId];
        }

        $unplaced = DB::table('exhibits')
            ->whereNull('hall_id')
            ->whereNotNull('map_x')
            ->whereNotNull('map_y')
            ->get(['exhibit_id', 'map_x', 'map_y']);

        foreach ($unplaced as $ex) {
            $hall = $this->hallAt((float) $ex->map_x, (float) $ex->map_y);
            if ($hall) {
                DB::table('exhibits')->where('exhibit_id', $ex->exhibit_id)
                    ->update(['hall_id' => $halls[$hall][0], 'updated_at' => now()]);
            }
        }

        foreach ($halls as [$hallId, $categoryId]) {
            DB::table('exhibits')->where('hall_id', $hallId)->update(['category_id' => $categoryId]);
        }

        DB::table('categories')
            ->whereIn('name', self::RETIRED)
            ->whereNotExists(fn ($q) => $q->from('exhibits')->whereColumn('exhibits.category_id', 'categories.category_id'))
            ->delete();
    }

    private function hallAt(float $x, float $y): ?string
    {
        foreach (self::AREAS as $hall => $rects) {
            foreach ($rects as [$x1, $y1, $x2, $y2]) {
                if ($x >= $x1 && $x <= $x2 && $y >= $y1 && $y <= $y2) {
                    return $hall;
                }
            }
        }

        return null;
    }

    /**
     * Removes the halls; their exhibits fall back to no hall (the foreign
     * key nulls them). Categories stay as they are.
     */
    public function down(): void
    {
        DB::table('museum_halls')->whereIn('name', array_keys(self::HALLS))->delete();
    }
};
