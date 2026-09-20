<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Support\Alerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alerts: one mail per problem, to the people configured, and none when
 * nobody is.
 */
class AlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    public function test_an_alert_is_emailed_to_the_configured_addresses(): void
    {
        config(['alerts.email' => 'tourism@baler.test, it@baler.test']);

        $this->assertTrue(Alerts::send('Backup failed', 'mysqldump was not found.'));

        Mail::assertSent(AlertMail::class, function (AlertMail $mail) {
            return $mail->hasTo('tourism@baler.test')
                && $mail->hasTo('it@baler.test')
                && $mail->alertSubject === 'Backup failed';
        });
    }

    public function test_the_same_problem_sends_one_mail_per_window(): void
    {
        config(['alerts.email' => 'tourism@baler.test']);

        $this->assertTrue(Alerts::send('Crash', 'first', 'exception:abc'));
        $this->assertFalse(Alerts::send('Crash', 'again', 'exception:abc'));
        $this->assertTrue(Alerts::send('Crash', 'elsewhere', 'exception:def'));

        Mail::assertSentCount(2);
    }

    public function test_no_recipient_means_a_log_line_and_no_mail(): void
    {
        config(['alerts.email' => null]);

        $this->assertFalse(Alerts::send('Crash', 'nobody listening'));

        Mail::assertNothingSent();
    }

    public function test_an_unhandled_exception_alerts_only_in_production(): void
    {
        config(['alerts.email' => 'tourism@baler.test']);

        Alerts::exception(new \RuntimeException('boom'));
        Mail::assertNothingSent();

        $this->app->detectEnvironment(fn () => 'production');
        Alerts::exception(new \RuntimeException('boom'));
        Mail::assertSentCount(1);
    }

    public function test_a_stale_backup_raises_an_alert_and_a_fresh_one_does_not(): void
    {
        config(['alerts.email' => 'tourism@baler.test', 'alerts.backup_max_age_hours' => 36]);

        $dir = sys_get_temp_dir() . '/museobaler-alerts-' . uniqid();
        File::makeDirectory($dir);
        config(['backup.directory' => $dir]);

        try {
            // No dump at all: the loudest case.
            $this->assertNotNull(Alerts::checkBackupFreshness());

            Cache::flush();
            touch("$dir/museobaler-old.sql.gz.enc", time() - 48 * 3600);
            $this->assertGreaterThan(36, Alerts::checkBackupFreshness());

            Cache::flush();
            touch("$dir/museobaler-new.sql.gz", time() - 3600);
            $this->assertNull(Alerts::checkBackupFreshness());
        } finally {
            File::deleteDirectory($dir);
        }

        Mail::assertSentCount(2);
    }
}
