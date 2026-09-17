<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Signing in as somebody starts a new session, the way it does in a
     * browser.
     *
     * The app runs AuthenticateSession, which ties a session to the password
     * that opened it - that is what makes a password change kill any session
     * still riding on the old one, including one the Tourism office opened
     * with a temporary password it had just issued.
     *
     * Tests routinely switch person mid-test to check a boundary from both
     * sides. Without this, the second actingAs() inherits the first person's
     * session, the password hash on it no longer matches, and the middleware
     * correctly throws the second person out to the login screen - which
     * looks like a failing authorisation test and is really just two people
     * sharing one browser, which is not a thing that happens.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }
}
