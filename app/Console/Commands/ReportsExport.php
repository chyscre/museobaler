<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Support\Reports\CsvExporter;
use App\Support\Reports\ReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The nightly CSV batch - the machine half of the export strategy.
 *
 * Writes every report for the period to storage/app/exports/<date>/ as plain
 * CSV, so anything that wants this system's numbers reads a file instead of
 * a screen. Nothing downstream should have to log in, click a report and
 * pick a format to find out how many people came last Tuesday.
 *
 * CSV only, on purpose. The other three formats exist because a person is
 * going to read them; a scheduled job has no use for a letterhead, and an
 * XLSX written nightly is a file somebody has to open to find out it is the
 * same numbers as the CSV beside it.
 *
 * A failure on one report does not stop the rest. A quiet run that skipped
 * four of six reports because the second one threw is the kind of thing that
 * is noticed a month later, when the numbers are needed.
 */
class ReportsExport extends Command
{
    protected $signature = 'reports:export
        {--date= : The day the logbook covers (default: yesterday)}
        {--from= : Start of the range for the period reports (default: first of this month)}
        {--to= : End of the range for the period reports (default: yesterday)}
        {--path= : Write here instead of storage/app/exports/<date>}
        {--keep=90 : Delete export folders older than this many days (0 keeps everything)}';

    protected $description = 'Write every report to CSV for the analytics pipeline';

    public function handle(ReportBuilder $builder, CsvExporter $csv): int
    {
        // Yesterday, not today: run at 02:45 and "today" is a day that has
        // barely started, so the logbook would be empty and the range would
        // stop short of the day that just finished.
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : today()->subDay();
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : today()->startOfMonth();
        $to   = $this->option('to') ? Carbon::parse($this->option('to')) : today()->subDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $dir = $this->option('path') ?: storage_path('app/exports/' . $date->toDateString());

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error('Could not create ' . $dir);

            return self::FAILURE;
        }

        $this->info('Writing to ' . $dir);

        $jobs = [
            'logbook'  => fn () => $builder->logbook($date),
            'visitors' => fn () => $builder->visitors($from, $to),
            'exhibits' => fn () => $builder->exhibits($from, $to),
            'feedback' => fn () => $builder->feedback($from, $to),
            'audit'    => fn () => $builder->audit($from, $to),
        ];

        // One DTR per person who has hours, not one file called "dtr": a
        // combined sheet would need a name column the printed DTR does not
        // have, and payroll wants them one at a time anyway.
        foreach (Staff::where('role', Staff::ROLE_ADMIN)->orderBy('name')->get() as $staff) {
            $jobs['dtr-' . str($staff->name)->slug()] = fn () => $builder->dtr($staff, $from->copy()->startOfMonth());
        }

        $failed = 0;

        foreach ($jobs as $name => $build) {
            try {
                $data = $build();
                $path = $dir . DIRECTORY_SEPARATOR . $name . '.csv';
                $csv->toFile($data, $path);

                $this->line(sprintf(
                    '  %-28s %5d rows  %s',
                    $name . '.csv',
                    count($data->primarySection()->rows),
                    $this->size($path)
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error('  ' . $name . ' failed: ' . $e->getMessage());
                report($e);
            }
        }

        $this->prune();

        if ($failed > 0) {
            $this->error($failed . ' of ' . count($jobs) . ' reports failed.');

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Drop old export folders.
     *
     * These are a derived copy of data the database still holds, so keeping
     * them forever only fills a disk that the nightly db:backup also wants.
     */
    private function prune(): void
    {
        $days = (int) $this->option('keep');
        $root = storage_path('app/exports');

        if ($days <= 0 || $this->option('path') || !is_dir($root)) {
            return;
        }

        $cutoff = today()->subDays($days);

        foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            $name = basename($folder);

            // Only folders this command names, and only ones that parse as a
            // date: anything else in there was put there by someone else.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                continue;
            }

            if (Carbon::parse($name)->lessThan($cutoff)) {
                array_map('unlink', glob($folder . DIRECTORY_SEPARATOR . '*.csv') ?: []);
                @rmdir($folder);
                $this->line('  pruned ' . $name);
            }
        }
    }

    private function size(string $path): string
    {
        $bytes = filesize($path) ?: 0;

        return $bytes > 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024, 1) . ' KB';
    }
}
