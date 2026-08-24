<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` itself has no view of its own - it always redirects a guest to /login
     * (and an authenticated user to /dashboard), so 302 is the correct response
     * here, not 200.
     */
    public function test_the_application_redirects_a_guest_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
