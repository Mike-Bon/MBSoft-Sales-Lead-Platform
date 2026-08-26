<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Public self-registration is intentionally disabled from Phase 2 onward:
 * every account is created by the Manager with an explicit role and team
 * (see UserManagementTest). These tests lock in that the public route no
 * longer exists, rather than silently dropping coverage.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_available(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(404);
    }

    public function test_register_route_does_not_exist(): void
    {
        $this->assertFalse(Route::has('register'));
    }
}
