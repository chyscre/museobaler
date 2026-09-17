<?php

namespace Database\Seeders;

use App\Models\Feedback;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffSchedule;
use App\Models\Tour;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Fills the last two weeks with plausible traffic so the Tourism office
 * screens can actually be looked at. Empty tables make the attendance board,
 * the DTR and every report impossible to judge.
 *
 * Never wired into DatabaseSeeder - run it deliberately:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Safe to run more than once; it clears only the rows it created before.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Seeding DEMO data - not for production.');

        $staff = $this->staff();
        $this->schedules($staff);
        $this->clearPrevious();
        $this->attendance($staff);
        $this->visitors($staff);

        $this->command?->info('Demo data ready. Sign in as tourism@baler.gov.ph');
    }

    /** @return array<string, Staff> */
    private function staff(): array
    {
        // All museum staff, all Administrators. The keys below are only about
        // what each one happens to be doing in this demo data - there is no
        // separate desk or guide role, because the museum does not work that
        // way: the same handful of people cover everything.
        $people = [
            'desk'   => ['Rosa Villanueva', 'rosa@museobaler.com',  Staff::ROLE_ADMIN],
            'guide1' => ['Marites Domingo', 'marites@museobaler.com', Staff::ROLE_ADMIN],
            'guide2' => ['Jun Molina',      'jun@museobaler.com',   Staff::ROLE_ADMIN],
            'guide3' => ['Ana Reyes',       'ana@museobaler.com',   Staff::ROLE_ADMIN],
        ];

        $made = [];

        foreach ($people as $key => [$name, $email, $role]) {
            $made[$key] = Staff::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('Password1!'), 'role' => $role, 'status' => true]
            );
        }

        return $made;
    }

    private function schedules(array $staff): void
    {
        foreach ($staff as $member) {
            foreach (range(0, 6) as $weekday) {
                StaffSchedule::updateOrCreate(
                    ['staff_id' => $member->staff_id, 'weekday' => $weekday],
                    [
                        'shift_start'   => '08:00',
                        'shift_end'     => '17:00',
                        'grace_minutes' => 15,
                        'is_rest_day'   => $weekday === 0, // Sunday
                    ]
                );
            }
        }
    }

    private function clearPrevious(): void
    {
        Feedback::whereNotNull('tour_id')->delete();
        Tour::query()->delete();
        StaffAttendance::query()->delete();
        Visitor::where('source', '!=', 'app')->delete();
        VisitGroup::query()->delete();
    }

    /**
     * Two weeks of attendance with the variety the board is meant to surface:
     * mostly on time, a few late arrivals, one no-show, one hand-entered day.
     */
    private function attendance(array $staff): void
    {
        $patterns = [
            'desk'   => ['late' => [3], 'absent' => []],
            'guide1' => ['late' => [],  'absent' => []],
            'guide2' => ['late' => [1, 6, 9], 'absent' => [4]],
            'guide3' => ['late' => [7], 'absent' => [2, 8]],
        ];

        for ($back = 13; $back >= 0; $back--) {
            $date = today()->subDays($back);

            if ($date->isSunday()) {
                continue;
            }

            foreach ($staff as $key => $member) {
                $pattern = $patterns[$key];

                if (in_array($back, $pattern['absent'], true)) {
                    continue;
                }

                $in = in_array($back, $pattern['late'], true)
                    ? $date->copy()->setTime(8, rand(20, 55))
                    : $date->copy()->setTime(7, rand(40, 59));

                StaffAttendance::create([
                    'staff_id'   => $member->staff_id,
                    'work_date'  => $date,
                    'type'       => 'in',
                    'scanned_at' => $in,
                    'method'     => 'qr',
                    'distance_m' => rand(4, 60),
                    'accuracy'   => rand(5, 20),
                ]);

                // Leave today's check-outs open so the board shows people
                // still on shift rather than a day that has already ended.
                if ($back === 0) {
                    continue;
                }

                StaffAttendance::create([
                    'staff_id'   => $member->staff_id,
                    'work_date'  => $date,
                    'type'       => 'out',
                    'scanned_at' => $date->copy()->setTime(17, rand(0, 25)),
                    'method'     => 'qr',
                    'distance_m' => rand(4, 60),
                    'accuracy'   => rand(5, 20),
                ]);
            }
        }

        // One hand-entered day, so the Manual flag has something to show.
        $manualDate = today()->subDays(5);
        if (!$manualDate->isSunday()) {
            StaffAttendance::updateOrCreate(
                ['staff_id' => $staff['guide1']->staff_id, 'work_date' => $manualDate->toDateString(), 'type' => 'in'],
                [
                    'scanned_at'  => $manualDate->copy()->setTime(8, 5),
                    'method'      => 'manual',
                    'recorded_by' => Staff::where('role', Staff::ROLE_TOURISM)->value('staff_id'),
                ]
            );
        }

        $this->command?->line('  attendance: ' . StaffAttendance::count() . ' rows');
    }

    /**
     * Traffic weighted the way the museum actually sees it: parties of four
     * or five from other provinces are the norm, locals come free, foreign
     * visitors and school tours are occasional.
     */
    private function visitors(array $staff): void
    {
        $cities = ['Quezon City', 'Cabanatuan', 'San Jose', 'Manila', 'Baler', 'Tarlac', 'Angeles'];
        $first  = ['Ramon', 'Ana', 'Jose', 'Liza', 'Paolo', 'Grace', 'Nestor', 'Cristina', 'Migs', 'Bea'];
        $last   = ['Cruz', 'Santos', 'Reyes', 'Garcia', 'Bautista', 'Del Rosario', 'Aquino', 'Lim'];

        for ($back = 13; $back >= 0; $back--) {
            $date = today()->subDays($back);

            if ($date->isMonday()) {
                continue; // closed
            }

            // A few parties, which is how most people actually arrive.
            foreach (range(1, rand(2, 4)) as $ignored) {
                $type  = $this->weightedType();
                $heads = rand(3, 6);
                $pays  = $type === 'Local' ? 0 : $heads;
                $paid  = rand(1, 10) > 2;

                VisitGroup::create([
                    'contact_name'   => $first[array_rand($first)] . ' ' . $last[array_rand($last)],
                    'group_type'     => 'Group',
                    'visitor_type'   => $type,
                    'city'           => $cities[array_rand($cities)],
                    'headcount'      => $heads,
                    'paying_count'   => $pays,
                    'total_fee'      => VisitGroup::feeFor($type, $pays),
                    'payment_status' => $pays === 0 ? 'Free' : ($paid ? 'Paid' : 'Unpaid'),
                    'paid_at'        => $pays > 0 && $paid ? $date->copy()->setTime(rand(9, 15), rand(0, 59)) : null,
                    'visit_date'     => $date,
                    'registered_by'  => $staff['desk']->staff_id,
                    'created_at'     => $date->copy()->setTime(rand(9, 15), rand(0, 59)),
                    'updated_at'     => $date,
                ]);
            }

            // Plus a handful of individuals.
            foreach (range(1, rand(2, 5)) as $ignored) {
                $type = $this->weightedType();
                $fee  = Visitor::feeFor($type);
                $paid = rand(1, 10) > 2;

                Visitor::create([
                    'first_name'     => $first[array_rand($first)],
                    'last_name'      => $last[array_rand($last)],
                    'age'            => rand(16, 68),
                    'visitor_type'   => $type,
                    'visit_type'     => 'Walk-in',
                    'city'           => $cities[array_rand($cities)],
                    'country'        => $type === 'Foreign' ? 'Japan' : 'Philippines',
                    'auth_provider'  => 'manual',
                    // Two lanes only: the visitor's own phone (prompted by the
                    // entrance poster) or the desk typing for them.
                    'source'         => ['app', 'app', 'desk'][rand(0, 2)],
                    'registered_by'  => $staff['desk']->staff_id,
                    'admission_fee'  => $fee,
                    'payment_status' => $fee > 0 ? ($paid ? 'Paid' : 'Unpaid') : 'Free',
                    'paid_at'        => $fee > 0 && $paid ? $date->copy()->setTime(rand(9, 15), rand(0, 59)) : null,
                    'id_verified'    => $type === 'Local' ? (bool) rand(0, 1) : false,
                    'created_at'     => $date->copy()->setTime(rand(9, 15), rand(0, 59)),
                    'updated_at'     => $date,
                ]);
            }
        }

        $this->tours($staff);
        $this->feedback();

        $this->command?->line('  visitors: ' . Visitor::count() . ', groups: ' . VisitGroup::count());
    }

    /** Guided tours stay rare on purpose - that is the real pattern here. */
    private function tours(array $staff): void
    {
        $guides = [$staff['guide1'], $staff['guide2'], $staff['guide3']];
        $types  = ['Requested', 'Foreign', 'Educational'];

        foreach (range(1, 6) as $i) {
            $date    = today()->subDays(rand(0, 12));
            $visitor = Visitor::inRandomOrder()->whereNull('group_id')->first();

            if (!$visitor) {
                continue;
            }

            $start = $date->copy()->setTime(rand(9, 14), rand(0, 59));

            Tour::create([
                'guide_staff_id' => $guides[array_rand($guides)]->staff_id,
                'tour_type'      => $types[array_rand($types)],
                'visitor_id'     => $visitor->visitor_id,
                'headcount'      => rand(1, 25),
                'started_at'     => $start,
                'ended_at'       => $start->copy()->addMinutes(rand(35, 80)),
                'created_by'     => $staff['desk']->staff_id,
            ]);
        }

        $this->command?->line('  tours: ' . Tour::count());
    }

    private function feedback(): void
    {
        $comments = [
            'Very informative, enjoyed the Siege of Baler exhibit.',
            'Clean and well kept. Wish there were more seating.',
            'The audio guide in Filipino was a nice touch.',
            'Small but worth the visit.',
            'Hard to find parking, but the museum itself was great.',
            null, null,
        ];

        // Unattributed museum feedback - the normal case, since most visits
        // have no guide at all.
        foreach (Visitor::inRandomOrder()->limit(22)->get() as $visitor) {
            Feedback::create([
                'visitor_id'    => $visitor->visitor_id,
                'rating'        => rand(1, 10) > 2 ? rand(4, 5) : rand(2, 3),
                'comment'       => $comments[array_rand($comments)],
                'attributed_by' => 'none',
                // Visitors carried over from earlier testing may have no
                // created_at, so fall back rather than dereferencing null.
                'submitted_at'  => $visitor->created_at?->copy()->addHours(1) ?? now(),
            ]);
        }

        // The few that were guided also rate their guide.
        foreach (Tour::with('guide')->get() as $tour) {
            Feedback::create([
                'visitor_id'    => $tour->visitor_id,
                'tour_id'       => $tour->tour_id,
                'staff_id'      => $tour->guide_staff_id,
                'rating'        => rand(4, 5),
                'guide_rating'  => rand(4, 5),
                'attributed_by' => 'visitor',
                'comment'       => 'Our guide explained the history really well.',
                'submitted_at'  => $tour->ended_at,
            ]);
        }

        $this->command?->line('  feedback: ' . Feedback::count());
    }

    /** Out-of-province parties dominate; foreign visitors are uncommon. */
    private function weightedType(): string
    {
        $roll = rand(1, 100);

        return match (true) {
            $roll <= 60 => 'Tourist',
            $roll <= 92 => 'Local',
            default     => 'Foreign',
        };
    }
}
