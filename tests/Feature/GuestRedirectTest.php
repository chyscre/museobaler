<?php

namespace Tests\Feature;

use Tests\TestCase;

class GuestRedirectTest extends TestCase
{
    /**
     * The dashboard ('/') is behind auth — guests must be redirected to login,
     * not served the page. This replaced the stock Laravel scaffold test,
     * which asserted a bare 200 that never matched this app's behavior.
     */
    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
