<?php

namespace Tests\Feature\Communications;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 6/7. The full happy-path OAuth exchange (real code -> real Google
 * token endpoint) is not exercised here — that would require either a
 * live Google project or refactoring GoogleOAuthController for the same
 * injectable-client seam GmailEmailProviderTest uses, which was judged
 * out of proportion for this controller. It is covered honestly as a
 * manual verification step instead (see docs/COMMUNICATIONS.md). What's
 * covered here is everything that doesn't require talking to Google:
 * the redirect, the `state` CSRF-style protection, and disconnect.
 */
class GoogleOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_sends_the_user_to_googles_consent_screen(): void
    {
        $user = User::factory()->create();
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.redirect' => 'https://example.test/communications/email-account/callback',
        ]);

        $response = $this->actingAs($user)->get('/communications/email-account/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_a_callback_with_a_mismatched_state_is_rejected_without_storing_anything(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/communications/email-account/callback?code=abc&state=999999')
            ->assertRedirect(route('communications.email-account.edit'));

        $this->assertDatabaseCount('email_accounts', 0);
    }

    public function test_disconnecting_removes_the_stored_account_and_its_tokens(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->delete('/communications/email-account')
            ->assertRedirect(route('communications.email-account.edit'));

        $this->assertDatabaseCount('email_accounts', 0);
    }

    public function test_the_edit_page_requires_authentication(): void
    {
        $this->get('/communications/email-account')->assertRedirect('/login');
    }
}
