<?php

namespace App\Console\Commands;

use App\Support\BackupCipher;
use Illuminate\Console\Command;
use Throwable;

/**
 * The other half of an encrypted backup: turning a .sql.gz.enc back into a
 * .sql.gz that `gunzip` and `mysql` can read.
 *
 *     php artisan db:backup:decrypt storage/app/backups/museobaler-2026-09-19_023000.sql.gz.enc
 *
 * Writes beside the source with the .enc removed, unless --to says where.
 * Uses BACKUP_ENCRYPTION_KEY from .env; --key overrides it for restoring
 * on a fresh machine before .env has been rebuilt.
 */
class BackupDecrypt extends Command
{
    protected $signature = 'db:backup:decrypt
                            {file : The .sql.gz.enc to decrypt}
                            {--to= : Where to write the decrypted file}
                            {--key= : Base64 key, overriding BACKUP_ENCRYPTION_KEY}';

    protected $description = 'Decrypt an encrypted database dump';

    public function handle(): int
    {
        $source = $this->argument('file');

        if (!is_file($source)) {
            $this->error("{$source} does not exist.");

            return self::FAILURE;
        }

        if ($this->option('key')) {
            config(['backup.encryption_key' => $this->option('key')]);
        }

        try {
            $key = BackupCipher::key();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($key === null) {
            $this->error('No key. Set BACKUP_ENCRYPTION_KEY in .env or pass --key.');

            return self::FAILURE;
        }

        $target = $this->option('to') ?: preg_replace('/\.enc$/', '', $source);

        if ($target === $source) {
            $target .= '.decrypted';
        }

        try {
            BackupCipher::decrypt($source, $target, $key);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$target}");
        $this->comment('Restore with: gunzip -c ' . basename($target) . ' | mysql -u <user> -p ' . config('database.connections.mysql.database'));

        return self::SUCCESS;
    }
}
