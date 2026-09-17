<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The nightly dump's refusal path.
 *
 * The dump itself shells out to mysqldump against a real MySQL server, which
 * a test suite running on in-memory sqlite has no way to exercise - so what
 * is pinned here is the case that actually bites: the command being pointed
 * at a connection it cannot dump.
 *
 * That is not hypothetical. .env.example shipped DB_CONNECTION=sqlite for
 * most of this project's life, and a backup that exits quietly against the
 * wrong connection is worse than no backup, because the scheduler keeps
 * reporting success while nothing is being written.
 */
class DatabaseBackupTest extends TestCase
{
    public function test_it_fails_loudly_when_the_connection_is_not_mysql(): void
    {
        // The suite runs on sqlite, which is the wrong connection by design.
        $this->artisan('db:backup')
            ->assertFailed()
            ->expectsOutputToContain('not mysql');
    }

    public function test_the_failure_says_where_to_look(): void
    {
        $this->artisan('db:backup')
            ->assertFailed()
            ->expectsOutputToContain('DB_CONNECTION');
    }
}
