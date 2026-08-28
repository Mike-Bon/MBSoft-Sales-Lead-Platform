<?php

namespace Tests\Feature\Organisation;

use App\Models\Setting;
use App\Models\User;
use App\Support\CompanyBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Volt as LivewireVolt;
use Tests\TestCase;

/**
 * Production-readiness branding correction: the login page and the app
 * sidebar show the configured company identity (name + logo), fall back
 * to a brand-neutral default when nothing is configured, carry no
 * Laravel starter branding, and only a Manager can change it. Existing
 * authentication is unaffected.
 */
class CompanyBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG as a data URI, for seeding a "configured" logo. */
    private const PNG_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** The distinctive path fragment from the OLD Laravel logo SVG. */
    private const LARAVEL_LOGO_FRAGMENT = 'M17.2 5.633';

    // ── Login page ────────────────────────────────────────────────

    public function test_login_page_shows_the_configured_company_name(): void
    {
        Setting::setValue(CompanyBranding::NAME_KEY, 'ABC Logistics');

        $this->get('/login')
            ->assertOk()
            ->assertSee('ABC Logistics')
            ->assertSee('<title>ABC Logistics</title>', false);
    }

    public function test_login_page_falls_back_to_the_app_name_when_no_company_name_is_configured(): void
    {
        $this->assertSame(0, Setting::query()->where('key', CompanyBranding::NAME_KEY)->count());

        $this->get('/login')
            ->assertOk()
            ->assertSee((string) config('app.name'));
    }

    public function test_login_page_renders_the_configured_logo(): void
    {
        Setting::setValue(CompanyBranding::LOGO_KEY, self::PNG_DATA_URI);

        $this->get('/login')
            ->assertOk()
            ->assertSee('src="'.self::PNG_DATA_URI.'"', false);
    }

    public function test_login_page_shows_the_default_mark_when_no_logo_is_configured(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('data:image/', false)          // no <img> data URI
            ->assertSee('viewBox="0 0 40 40"', false);      // the neutral fallback SVG
    }

    public function test_login_page_carries_no_laravel_logo(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee(self::LARAVEL_LOGO_FRAGMENT, false)
            ->assertDontSee('>Laravel<', false);
    }

    // ── Authenticated sidebar ─────────────────────────────────────

    public function test_sidebar_shows_the_configured_name_and_logo(): void
    {
        Setting::setValue(CompanyBranding::NAME_KEY, 'XYZ Corporation');
        Setting::setValue(CompanyBranding::LOGO_KEY, self::PNG_DATA_URI);

        $this->actingAs(User::factory()->manager()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('XYZ Corporation')
            ->assertSee('src="'.self::PNG_DATA_URI.'"', false);
    }

    public function test_sidebar_shows_the_default_mark_and_app_name_when_nothing_is_configured(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee((string) config('app.name'))
            ->assertDontSee('data:image/', false)
            ->assertSee('viewBox="0 0 40 40"', false);
    }

    public function test_sidebar_carries_no_laravel_logo(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(self::LARAVEL_LOGO_FRAGMENT, false);
    }

    // ── Company Settings authorization ────────────────────────────

    public function test_a_guest_is_redirected_from_company_settings(): void
    {
        $this->get('/company')->assertRedirect('/login');
    }

    public function test_a_manager_can_open_company_settings(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/company')
            ->assertOk()
            ->assertSee('Company Settings');
    }

    public function test_a_team_head_cannot_open_company_settings(): void
    {
        $this->actingAs(User::factory()->teamHead()->create())
            ->get('/company')
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_open_company_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/company')
            ->assertForbidden();
    }

    public function test_a_team_head_cannot_update_company_branding(): void
    {
        $this->actingAs(User::factory()->teamHead()->create())
            ->post('/company', ['name' => 'Hijacked Inc'])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->where('key', CompanyBranding::NAME_KEY)->count());
    }

    // ── Company Settings behaviour ────────────────────────────────

    public function test_a_manager_can_update_the_company_name(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->post('/company', ['name' => 'ABC Logistics'])
            ->assertRedirect(route('organisation.company.edit'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('settings', ['key' => CompanyBranding::NAME_KEY, 'value' => 'ABC Logistics']);
        $this->assertSame('ABC Logistics', CompanyBranding::name());
    }

    public function test_a_manager_can_upload_a_logo_and_it_is_stored_as_a_data_uri(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->post('/company', [
                'name' => 'ABC Logistics',
                'logo' => UploadedFile::fake()->image('brand.png', 240, 80),
            ])
            ->assertRedirect(route('organisation.company.edit'));

        $stored = Setting::getValue(CompanyBranding::LOGO_KEY);
        $this->assertIsString($stored);
        $this->assertStringStartsWith('data:image/', $stored);
        $this->assertTrue(CompanyBranding::hasCustomLogo());
    }

    public function test_the_logo_upload_rejects_a_non_image_file(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->from('/company')
            ->post('/company', [
                'name' => 'ABC Logistics',
                'logo' => UploadedFile::fake()->create('payload.php', 4, 'text/x-php'),
            ])
            ->assertRedirect('/company')
            ->assertSessionHasErrors('logo');

        $this->assertSame(0, Setting::query()->where('key', CompanyBranding::LOGO_KEY)->count());
    }

    public function test_the_logo_upload_rejects_an_over_large_image(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->from('/company')
            ->post('/company', [
                'name' => 'ABC Logistics',
                'logo' => UploadedFile::fake()->image('huge.png', 4000, 4000),
            ])
            ->assertRedirect('/company')
            ->assertSessionHasErrors('logo');
    }

    public function test_removing_the_logo_falls_back_to_the_default_mark(): void
    {
        Setting::setValue(CompanyBranding::LOGO_KEY, self::PNG_DATA_URI);
        $manager = User::factory()->manager()->create();
        $this->assertTrue(CompanyBranding::hasCustomLogo());

        $this->actingAs($manager)
            ->post('/company', ['name' => 'ABC Logistics', 'remove_logo' => '1'])
            ->assertRedirect(route('organisation.company.edit'));

        $this->assertFalse(CompanyBranding::hasCustomLogo());
        $this->assertNull(CompanyBranding::logo());
    }

    public function test_a_branding_change_is_written_to_the_audit_log(): void
    {
        $manager = User::factory()->manager()->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('company.branding.updated', \Mockery::on(
            fn ($context) => $context['actor_id'] === $manager->id && $context['name'] === 'ABC Logistics'
        ));

        $this->actingAs($manager)->post('/company', ['name' => 'ABC Logistics']);
    }

    // ── Fail-safe + existing auth ─────────────────────────────────

    public function test_branding_falls_back_safely_when_the_settings_table_is_missing(): void
    {
        Setting::query()->getConnection()->getSchemaBuilder()->drop('settings');

        // Must not throw — the login page still renders with the app name.
        $this->get('/login')->assertOk()->assertSee((string) config('app.name'));
        $this->assertSame((string) config('app.name'), CompanyBranding::name());
        $this->assertNull(CompanyBranding::logo());
    }

    public function test_existing_login_still_authenticates_with_branding_applied(): void
    {
        Setting::setValue(CompanyBranding::NAME_KEY, 'ABC Logistics');
        Setting::setValue(CompanyBranding::LOGO_KEY, self::PNG_DATA_URI);
        $user = User::factory()->create();

        LivewireVolt::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
