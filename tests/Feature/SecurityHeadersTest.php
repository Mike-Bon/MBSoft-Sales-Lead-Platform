<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11: baseline defense-in-depth response headers (see
 * App\Http\Middleware\SecurityHeaders) are present on every web
 * response, authenticated or not.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_a_public_page(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_security_headers_are_present_on_an_authenticated_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_is_not_set_over_plain_http(): void
    {
        $response = $this->get('/login');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_set_over_https(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertHeader('Strict-Transport-Security');
    }
}
