<?php

namespace App\Console\Commands;

use App\Support\BackupCipher;
use Illuminate\Console\Command;

/**
 * Prints a key for BACKUP_ENCRYPTION_KEY. Never writes it anywhere itself:
 * where the key is kept is a decision, and it should be made by the person
 * pasting it into .env.
 */
class BackupKey extends Command
{
    protected $signature = 'db:backup:key';

    protected $description = 'Print a new key for BACKUP_ENCRYPTION_KEY';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->newLine();
        $this->comment('Put this in .env as BACKUP_ENCRYPTION_KEY, and keep a copy off this machine.');
        $this->comment('Dumps made before the key was set stay readable; dumps made after it need it.');

        return self::SUCCESS;
    }
}
