<?php

namespace App\Console\Commands;

use App\Models\Log;
use App\Models\Staff;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;

/**
 * The way back in when nobody can get in.
 *
 * Staff passwords are issued through the Staff screen, which only the Tourism
 * office can reach - and that is the whole point, right up until the moment
 * the last Tourism account is the one locked out. There is no "forgot
 * password" email on this system (the museum has no mail setup, and a reset
 * link to an inbox nobody reads is worse than no reset at all), so the
 * recovery path has to be the console, where being able to run it at all
 * already means you have the server.
 *
 * It doubles as the way to get a working login while developing.
 */
class SetStaffPassword extends Command
{
    protected $signature = 'staff:password
        {email? : The account to set a password on}
        {--password= : Use this password instead of generating one}
        {--ready : Skip the forced change at next sign-in (local testing only)}
        {--list : Show every account and the state of its password}';

    protected $description = 'Issue a staff password from the console, for lockout recovery or local testing';

    public function handle(): int
    {
        if ($this->option('list') || !$this->argument('email')) {
            $this->listAccounts();

            return $this->argument('email') ? self::SUCCESS : self::SUCCESS;
        }

        $email = $this->argument('email');
        $staff = Staff::where('email', $email)->first();

        if (!$staff) {
            $this->error("No staff account with the email {$email}.");
            $this->newLine();
            $this->listAccounts();

            return self::FAILURE;
        }

        $password = $this->option('password') ?: PasswordPolicy::generateTemporary();

        // --ready hands over an account that is ready to use, which is the
        // wrong thing for a real person - their password would be one you
        // also know - and exactly the right thing for a dev box you are
        // clicking around on. So it says so out loud.
        $ready = (bool) $this->option('ready');

        if ($ready && app()->environment('production')) {
            $this->error('--ready is refused in production: it would leave an account whose password two people know.');

            return self::FAILURE;
        }

        $staff->update([
            'password'             => $password,
            'must_change_password' => !$ready,
            'password_changed_at'  => $ready ? now() : null,
        ]);

        Log::create([
            'user_id'    => null, // nobody was signed in; this came from the server
            'user_name'  => 'console',
            'role'       => 'console',
            'action'     => 'Staff Password Reset',
            'details'    => "Password for {$staff->name} ({$staff->email}) was set from the console"
                            . ($ready ? ', with the forced change skipped' : ''),
            'ip_address' => null,
        ]);

        $this->newLine();
        $this->info('Password set.');
        $this->newLine();
        $this->line("  Sign in at  <options=bold>" . route('login') . "</>");
        $this->line("  Email       <options=bold>{$staff->email}</>");
        $this->line("  Password    <options=bold>{$password}</>");
        $this->line("  Role        {$staff->role_label}");
        $this->newLine();

        if ($ready) {
            $this->line('  This account goes straight into the panel.');
        } else {
            $this->line('  It will be asked to set its own password at next sign-in.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function listAccounts(): void
    {
        $rows = Staff::orderBy('staff_id')->get()->map(fn (Staff $s) => [
            $s->email,
            $s->role_label,
            $s->status ? 'active' : 'deactivated',
            $s->must_change_password
                ? 'must set its own'
                : 'owns it' . ($s->password_changed_at ? ' since ' . $s->password_changed_at->format('M j, Y') : ''),
        ]);

        $this->newLine();
        $this->table(['Email', 'Role', 'Account', 'Password'], $rows);
        $this->line('  Set one with:  <options=bold>php artisan staff:password {email} --ready</>');
        $this->newLine();
    }
}
