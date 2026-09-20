<?php

namespace App\Console\Commands;

use App\Support\Alerts;
use App\Support\BackupCipher;
use App\Support\MediaArchive;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * A compressed dump of the museum database, written to storage/app/backups,
 * with the uploaded pictures and audio guides in a tarball beside it.
 *
 * Until this existed there was no backup at all: one bad migration, one
 * mistaken delete, one failed disk, and every visitor record, attendance row
 * and piece of feedback the museum had collected was gone with nothing to
 * restore from. Everything else in this system is replaceable - the code is
 * in version control, the exhibits could be retyped - but the records are the
 * only copy of what actually happened at the door.
 *
 * Run it daily. On this machine that means a Windows Task Scheduler entry
 * calling `php artisan schedule:run` every minute; on a Linux host, the same
 * line in cron. See the schedule in routes/console.php.
 *
 * A dump beside the database it came from survives a mistake, not a fire.
 * Set BACKUP_COPY_TO to another drive or a synced folder and each dump is
 * copied there too; set BACKUP_ENCRYPTION_KEY and every dump is encrypted
 * before it is written, so a copy that goes missing is not a breach.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep= : How many dumps to keep, overriding config/backup.php}';

    protected $description = 'Write a compressed dump of the database to storage/app/backups';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            return $this->abandon(
                "The database connection is '{$connection}', not mysql - nothing to dump.",
                'This command shells out to mysqldump. Check DB_CONNECTION in .env.'
            );
        }

        $binary = $this->mysqldump();

        if ($binary === null) {
            return $this->abandon(
                'mysqldump was not found.',
                'Set MYSQLDUMP_PATH in .env to its full path (Laragon keeps it in bin/mysql/<version>/bin).'
            );
        }

        $database = config("database.connections.{$connection}");
        $directory = config('backup.directory');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->abandon("Could not create {$directory}.");
        }

        $stem = $directory . '/' . $database['database'] . '-' . now()->format('Y-m-d_His');

        // SECURITY: credentials go in a file mysqldump reads and we delete,
        // never in the argument list - anyone with a process listing on this
        // machine can read the arguments of a running command.
        $credentials = $this->writeCredentialsFile($database);

        try {
            $dumped = $this->dump($binary, $credentials, $database['database'], "{$stem}.sql");
        } finally {
            @unlink($credentials);
        }

        if ($dumped !== true) {
            @unlink("{$stem}.sql");
            return $this->abandon('mysqldump failed.', $dumped);
        }

        $archive = $this->compress("{$stem}.sql");

        // Encrypted when a key is configured. The plain archive is removed
        // once the encrypted one exists, so the only readable copy of the
        // data on disk is the database itself.
        $key = BackupCipher::key();

        if ($key !== null) {
            $archive = $this->encrypt($archive, $key);
        } else {
            $this->warn('BACKUP_ENCRYPTION_KEY is not set: this dump is readable by anyone who can open the file.');
        }

        $this->info('Wrote ' . basename($archive) . ' (' . $this->humanSize(filesize($archive)) . ')');

        // The uploads, as a second file with the same stamp. Its failure is
        // reported but does not fail the run: the records are the half that
        // cannot be recreated, and they are already on disk by this point.
        $media = $this->packMedia($stem, $key);

        if ($media !== null) {
            $this->info('Wrote ' . basename($media) . ' (' . $this->humanSize(filesize($media)) . ')');
        }

        $this->prune($directory);
        $this->copyOffMachine($archive);

        if ($media !== null) {
            $this->copyOffMachine($media);
        }

        return self::SUCCESS;
    }

    /**
     * The pictures and audio guides, beside the dump.
     *
     * A separate file rather than one archive holding both: the dump's
     * restore path (decrypt, gunzip, mysql) is documented and unchanged, the
     * stale-backup watchdog keeps reading the dump as its heartbeat, and the
     * media restores with a single tar command into public/.
     */
    private function packMedia(string $stem, ?string $key): ?string
    {
        try {
            $archive = MediaArchive::pack(config('backup.media', []), public_path(), "{$stem}-media.tar");
        } catch (\Throwable $e) {
            $this->warn('Media backup failed: ' . $e->getMessage());
            Alerts::send('Nightly media backup failed', $e->getMessage(), 'backup-media');
            return null;
        }

        if ($archive === null) {
            $this->line('No uploaded media to back up.');
            return null;
        }

        return $key !== null ? $this->encrypt($archive, $key) : $archive;
    }

    /** Encrypt in place: the plain file is gone once the .enc exists. */
    private function encrypt(string $archive, string $key): string
    {
        $encrypted = BackupCipher::encrypt($archive, "{$archive}.enc", $key);
        @unlink($archive);

        return $encrypted;
    }

    /**
     * A failed backup is the one failure that must not be quiet.
     *
     * The scheduler runs this at half past two in the morning with nobody
     * watching the console. Without this, "mysqldump was not found" is a
     * line in a log file that is read the day a restore is needed.
     */
    private function abandon(string $message, string $hint = ''): int
    {
        $this->error($message);

        if ($hint !== '') {
            $this->line($hint);
        }

        Alerts::send('Nightly database backup failed', trim($message . "\n" . $hint), 'backup-failed');

        return self::FAILURE;
    }

    /**
     * The second copy.
     *
     * A dump beside the database survives a mistake, not a dead disk.
     * BACKUP_COPY_TO names somewhere else - a second drive, a mounted
     * network share, a folder a cloud client syncs - and the newest dump
     * is copied there after every run, with the same retention applied.
     *
     * Unreachable is a warning and an alert, not a failure: the local dump
     * was written, which is the more important half.
     */
    private function copyOffMachine(string $archive): void
    {
        $copyTo = (string) config('backup.copy_to');

        if ($copyTo === '') {
            return;
        }

        if (!is_dir($copyTo) && !@mkdir($copyTo, 0775, true) && !is_dir($copyTo)) {
            $this->warn("Second copy skipped: {$copyTo} is not reachable.");
            Alerts::send('Backup second copy skipped', "The backup was written locally but {$copyTo} could not be reached. Is the drive connected?", 'backup-copy');

            return;
        }

        $target = rtrim($copyTo, '/\\') . DIRECTORY_SEPARATOR . basename($archive);

        if (!@copy($archive, $target)) {
            $this->warn("Second copy failed: could not write {$target}.");
            Alerts::send('Backup second copy failed', "The backup was written locally but could not be copied to {$target}.", 'backup-copy');

            return;
        }

        $this->line('Copied to ' . $target);
        $this->prune($copyTo);
    }

    /**
     * Stream mysqldump's output to disk.
     *
     * Written through a callback rather than collected and returned, so a
     * database larger than the PHP memory limit still dumps.
     *
     * Returns true, or the error output to report.
     */
    private function dump(string $binary, string $credentials, string $database, string $target): true|string
    {
        $process = new Process([
            $binary,
            "--defaults-extra-file={$credentials}",
            // A consistent snapshot without locking the tables the front desk
            // is writing to - the museum is open while this runs.
            '--single-transaction',
            // MySQL 8 asks for the PROCESS privilege to read tablespace info,
            // which a database user scoped to this one schema will not have.
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            $database,
        ]);

        $process->setTimeout(600);

        $handle = fopen($target, 'wb');
        $errors = '';

        $process->run(function (string $type, string $buffer) use ($handle, &$errors) {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            } else {
                $errors .= $buffer;
            }
        });

        fclose($handle);

        return $process->isSuccessful() ? true : trim($errors);
    }

    /** gzip the dump in chunks, so memory use does not track the file size. */
    private function compress(string $sql): string
    {
        $archive = "{$sql}.gz";

        $in  = fopen($sql, 'rb');
        $out = gzopen($archive, 'wb9');

        while (!feof($in)) {
            gzwrite($out, fread($in, 1024 * 1024));
        }

        gzclose($out);
        fclose($in);
        unlink($sql);

        return $archive;
    }

    private function writeCredentialsFile(array $database): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbdump');

        file_put_contents($path, implode("\n", [
            '[client]',
            'user=' . $database['username'],
            'password=' . $database['password'],
            'host=' . $database['host'],
            'port=' . $database['port'],
            '',
        ]));

        @chmod($path, 0600);

        return $path;
    }

    /**
     * Find mysqldump.
     *
     * A server has it on PATH. Laragon and XAMPP do not, which is why a bare
     * "mysqldump" fails on a machine that is plainly running MySQL.
     */
    private function mysqldump(): ?string
    {
        $configured = config('backup.mysqldump');

        if (!empty($configured)) {
            return is_file($configured) ? $configured : null;
        }

        $onPath = new Process(['mysqldump', '--version']);
        $onPath->run();

        if ($onPath->isSuccessful()) {
            return 'mysqldump';
        }

        foreach (['C:/laragon/bin/mysql/*/bin/mysqldump.exe', 'C:/xampp/mysql/bin/mysqldump.exe'] as $pattern) {
            $found = glob($pattern);

            if (!empty($found)) {
                return $found[0];
            }
        }

        return null;
    }

    private function prune(string $directory): void
    {
        $keep = (int) ($this->option('keep') ?: config('backup.keep'));

        if ($keep < 1) {
            return;
        }

        // Dumps and media tarballs are counted separately, so a fortnight of
        // backups is fourteen of each, not seven nights' worth of pairs.
        // Plain and encrypted (.enc) alike.
        foreach (['*.sql.gz*', '*-media.tar.gz*'] as $pattern) {
            $files = glob("{$directory}/{$pattern}") ?: [];

            // Names are timestamped, so newest sorts last.
            rsort($files);

            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
                $this->line('Removed old backup ' . basename($old));
            }
        }
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' TB';
    }
}
