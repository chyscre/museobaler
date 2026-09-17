<?php

namespace App\Console\Commands;

use App\Models\Staff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empties the operational records so the panel shows only what really happened.
 *
 * DemoDataSeeder fills two weeks with plausible traffic - parties from Quezon
 * City, a guide who is late on Tuesdays, ninety-nine pieces of feedback - so
 * that the attendance board and the reports can be judged before the museum
 * has used them. That is the right thing while building the screens and the
 * wrong thing the moment anybody looks at a number and believes it.
 *
 * This puts the install back to nothing-has-happened-yet. What it does NOT
 * touch is the museum's real content: the exhibits, their translations and
 * images, the categories, the map, the museum info, and the visitor-app
 * notices. Those are about Baler, not about invented visitors, and they are
 * what the panel is for.
 */
class ClearRecords extends Command
{
    protected $signature = 'museum:clear-records
        {--logs : Also wipe the audit log}
        {--demo-staff : Also remove the seeded demo staff (Rosa, Jun, Ana, Marites)}
        {--all : Everything above}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete invented visitor, feedback and attendance records, keeping the museum content';

    /**
     * Deletion order, not alphabetical order.
     *
     * The rows that point at other rows go first. Most of these columns are
     * nullOnDelete and would survive the wrong order, but attendance and
     * corrections cascade from staff, and relying on a cascade to tidy up is
     * how you end up with a half-emptied table nobody notices.
     */
    private const RECORD_TABLES = [
        'feedback'               => 'visitor feedback',
        'scans'                  => 'exhibit QR scans',
        'attendances'            => 'visitor check-ins',
        'tours'                  => 'guided tours',
        'visitors'               => 'visitors',
        'visit_groups'           => 'visitor groups',
        'staff_attendances'      => 'staff check-in/out rows',
        'attendance_corrections' => 'attendance corrections',
        'staff_attendance_days'  => 'daily attendance secrets',
        'bookmarks'              => 'visitor bookmarks',
    ];

    /** The four people DemoDataSeeder invents. */
    private const DEMO_EMAILS = [
        'rosa@museobaler.com',
        'jun@museobaler.com',
        'ana@museobaler.com',
        'marites@museobaler.com',
    ];

    public function handle(): int
    {
        $withLogs  = $this->option('logs') || $this->option('all');
        $withStaff = $this->option('demo-staff') || $this->option('all');

        $plan = $this->plan($withLogs, $withStaff);

        $this->newLine();
        $this->line('  <options=bold>Will delete</>');
        foreach ($plan as $label => $count) {
            $this->line(sprintf('    %-32s %s', $label, $count === 0 ? '<fg=gray>none</>' : $count));
        }

        $this->newLine();
        $this->line('  <options=bold>Will keep</>');
        foreach ($this->kept() as $label => $count) {
            $this->line(sprintf('    %-32s %s', $label, $count));
        }
        $this->newLine();

        if (array_sum($plan) === 0) {
            $this->info('Nothing to delete — the records are already empty.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Delete these records? This cannot be undone.')) {
            $this->line('Nothing was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($withLogs, $withStaff) {
            foreach (array_keys(self::RECORD_TABLES) as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            if ($withLogs) {
                DB::table('logs')->delete();
            }

            // Staff last: attendance and schedules cascade off it, and those
            // are already gone by now, so this removes an account with no
            // history rather than silently taking history with it.
            //
            // The panel has no delete-staff button on purpose - removing a
            // real person would orphan the audit trail that names them. These
            // four are different: they were invented by a seeder and never
            // did anything.
            if ($withStaff) {
                Staff::whereIn('email', self::DEMO_EMAILS)->delete();
            }
        });

        $this->newLine();
        $this->info('Records cleared.');
        $this->line('  The panel now shows only what actually happens from here on.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function plan(bool $withLogs, bool $withStaff): array
    {
        $plan = [];

        foreach (self::RECORD_TABLES as $table => $label) {
            $plan[$label] = DB::getSchemaBuilder()->hasTable($table)
                ? DB::table($table)->count()
                : 0;
        }

        if ($withLogs) {
            $plan['audit log entries'] = DB::table('logs')->count();
        }

        if ($withStaff) {
            $plan['demo staff accounts'] = Staff::whereIn('email', self::DEMO_EMAILS)->count();
        }

        return $plan;
    }

    /** @return array<string, int> */
    private function kept(): array
    {
        $kept = [
            'exhibits'              => DB::table('exhibits')->count(),
            'exhibit translations'  => DB::table('exhibit_translations')->count(),
            'exhibit images'        => DB::table('exhibit_images')->count(),
            'categories'            => DB::table('categories')->count(),
            'museum info'           => DB::table('museum_info')->count(),
            'visitor-app notices'   => DB::table('notifications')->count(),
            'staff accounts'        => Staff::whereNotIn('email', self::DEMO_EMAILS)->count(),
        ];

        if (!$this->option('logs') && !$this->option('all')) {
            $kept['audit log entries'] = DB::table('logs')->count();
        }

        if (!$this->option('demo-staff') && !$this->option('all')) {
            $kept['demo staff accounts'] = Staff::whereIn('email', self::DEMO_EMAILS)->count();
        }

        return $kept;
    }
}
