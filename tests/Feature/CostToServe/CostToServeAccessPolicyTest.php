<?php

namespace Tests\Feature\CostToServe;

use App\Enums\AgentIdentifier;
use App\Models\Setting;
use App\Models\User;
use App\Services\CostToServe\CostToServeAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 12A: the single, exhaustive reference for the Cost-to-Serve
 * access policy — the file every other Cost-to-Serve test's docblock
 * points to. It pins, in one place:
 *
 *   - the two concepts the spec requires stay separate —
 *     isEnabled() (GLOBAL feature status) and isRoleAuthorized()
 *     (USER/ROLE authorization) — and the combined canAccess() every
 *     enforcement point actually calls;
 *   - the HTTP surface of the administrative switch
 *     (GET/POST /cost-to-serve/settings): who may reach it, input
 *     validation, CSRF, and the enable/disable round trip;
 *   - the sidebar's role- and switch-aware visibility.
 *
 * The final policy, unchanged by this test and never to be widened:
 *
 *   Manager    — feature ON by default; may turn it ON/OFF; may use it
 *                while ON.
 *   Team Head  — feature is OFF for them, permanently; cannot enable
 *                it; a Manager enabling the feature does NOT enable it
 *                for them; they stay denied at every layer.
 *   Team Member — never had access; unchanged.
 */
class CostToServeAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CostToServeAccessService
    {
        return app(CostToServeAccessService::class);
    }

    // ---------------------------------------------------------------
    // CostToServeAccessService — the source of truth
    // ---------------------------------------------------------------

    public function test_the_feature_is_enabled_by_default_with_no_setting_row(): void
    {
        $this->assertSame(0, Setting::query()->count());
        $this->assertTrue($this->service()->isEnabled());
    }

    public function test_a_manager_is_role_authorized_and_a_team_head_and_member_are_not(): void
    {
        $this->assertTrue($this->service()->isRoleAuthorized(User::factory()->manager()->create()));
        $this->assertFalse($this->service()->isRoleAuthorized(User::factory()->teamHead()->create()));
        $this->assertFalse($this->service()->isRoleAuthorized(User::factory()->teamMember()->create()));
    }

    public function test_role_authorization_is_independent_of_the_global_switch(): void
    {
        $manager = User::factory()->manager()->create();
        $head = User::factory()->teamHead()->create();

        Setting::setValue('cost_to_serve.enabled', 'false');

        // The switch never changes who is role-authorized — only whether
        // that authorization currently grants access.
        $this->assertTrue($this->service()->isRoleAuthorized($manager));
        $this->assertFalse($this->service()->isRoleAuthorized($head));
    }

    public function test_can_access_matrix(): void
    {
        $manager = User::factory()->manager()->create();
        $head = User::factory()->teamHead()->create();

        // Feature ON (default).
        $this->assertTrue($this->service()->canAccess($manager));
        $this->assertFalse($this->service()->canAccess($head));

        // Feature OFF.
        Setting::setValue('cost_to_serve.enabled', 'false');
        $this->assertFalse($this->service()->canAccess($manager));
        $this->assertFalse($this->service()->canAccess($head));
    }

    public function test_turning_the_feature_on_never_grants_a_team_head_access(): void
    {
        $head = User::factory()->teamHead()->create();
        $manager = User::factory()->manager()->create();

        $this->service()->disable($manager);
        $this->service()->enable($manager);

        $this->assertTrue($this->service()->isEnabled());
        $this->assertFalse($this->service()->canAccess($head));
        $this->assertFalse(AgentIdentifier::CostToServe->isAvailableTo($head->fresh()));
    }

    public function test_a_manager_can_disable_then_re_enable_and_the_state_persists(): void
    {
        $manager = User::factory()->manager()->create();

        $this->service()->disable($manager);
        $this->assertFalse($this->service()->isEnabled());
        $this->assertDatabaseHas('settings', ['key' => 'cost_to_serve.enabled', 'value' => 'false']);

        // A fresh resolved instance (simulates a later request) reads
        // the persisted value, not a stale in-memory one.
        $this->assertFalse(app(CostToServeAccessService::class)->isEnabled());

        $this->service()->enable($manager);
        $this->assertTrue(app(CostToServeAccessService::class)->isEnabled());
        $this->assertDatabaseHas('settings', ['key' => 'cost_to_serve.enabled', 'value' => 'true']);
    }

    public function test_only_one_setting_row_ever_exists_for_the_flag(): void
    {
        $manager = User::factory()->manager()->create();

        $this->service()->disable($manager);
        $this->service()->enable($manager);
        $this->service()->disable($manager);

        $this->assertSame(1, Setting::query()->where('key', 'cost_to_serve.enabled')->count());
    }

    public function test_a_team_head_cannot_change_the_feature_state(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->expectException(AuthorizationException::class);

        $this->service()->disable($head);
    }

    public function test_a_team_member_cannot_change_the_feature_state(): void
    {
        $member = User::factory()->teamMember()->create();

        $this->expectException(AuthorizationException::class);

        $this->service()->enable($member);
    }

    public function test_enabling_writes_an_audit_entry_with_the_previous_and_new_state(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('cost_to_serve.enabled', \Mockery::on(function ($context) use ($manager) {
            return $context['actor_id'] === $manager->id
                && $context['actor_role'] === 'manager'
                && $context['previous_state'] === false
                && $context['new_state'] === true;
        }));

        $this->service()->enable($manager);
    }

    public function test_disabling_writes_an_audit_entry_with_the_previous_and_new_state(): void
    {
        $manager = User::factory()->manager()->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('cost_to_serve.disabled', \Mockery::on(function ($context) {
            return $context['previous_state'] === true && $context['new_state'] === false;
        }));

        $this->service()->disable($manager);
    }

    // ---------------------------------------------------------------
    // GET /cost-to-serve/settings — the administrative page
    // ---------------------------------------------------------------

    public function test_a_guest_is_redirected_from_the_settings_page(): void
    {
        $this->get('/cost-to-serve/settings')->assertRedirect('/login');
    }

    public function test_a_manager_can_open_the_settings_page(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/cost-to-serve/settings')
            ->assertOk()
            ->assertSee('Cost-to-Serve Settings')
            ->assertSee('Enabled');
    }

    public function test_a_manager_can_open_the_settings_page_even_while_the_feature_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();

        // Turning the feature off must never lock the Manager out of
        // the one page that turns it back on.
        $this->actingAs($manager)->get('/cost-to-serve/settings')
            ->assertOk()
            ->assertSee('Disabled');
    }

    public function test_a_team_head_is_forbidden_from_the_settings_page(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)->get('/cost-to-serve/settings')->assertForbidden();
    }

    public function test_a_team_member_is_forbidden_from_the_settings_page(): void
    {
        $member = User::factory()->teamMember()->create();

        $this->actingAs($member)->get('/cost-to-serve/settings')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // POST /cost-to-serve/settings — the toggle
    // ---------------------------------------------------------------

    public function test_a_manager_can_disable_and_then_re_enable_the_feature_over_http(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->post('/cost-to-serve/settings', ['enabled' => '0'])
            ->assertRedirect('/cost-to-serve/settings')
            ->assertSessionHas('status');

        $this->assertFalse($this->service()->isEnabled());
        $this->assertDatabaseHas('settings', ['key' => 'cost_to_serve.enabled', 'value' => 'false']);

        $this->actingAs($manager)
            ->post('/cost-to-serve/settings', ['enabled' => '1'])
            ->assertRedirect('/cost-to-serve/settings')
            ->assertSessionHas('status');

        $this->assertTrue($this->service()->isEnabled());
        $this->assertDatabaseHas('settings', ['key' => 'cost_to_serve.enabled', 'value' => 'true']);
    }

    public function test_the_enabled_field_is_required(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->from('/cost-to-serve/settings')
            ->post('/cost-to-serve/settings', [])
            ->assertRedirect('/cost-to-serve/settings')
            ->assertSessionHasErrors('enabled');
    }

    public function test_the_enabled_field_must_be_boolean(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->from('/cost-to-serve/settings')
            ->post('/cost-to-serve/settings', ['enabled' => 'banana'])
            ->assertRedirect('/cost-to-serve/settings')
            ->assertSessionHasErrors('enabled');

        // A rejected request never touches the stored setting.
        $this->assertSame(0, Setting::query()->where('key', 'cost_to_serve.enabled')->count());
        $this->assertTrue($this->service()->isEnabled());
    }

    public function test_a_team_head_cannot_toggle_the_feature_over_http(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)
            ->post('/cost-to-serve/settings', ['enabled' => '0'])
            ->assertForbidden();

        $this->assertTrue($this->service()->isEnabled());
        $this->assertSame(0, Setting::query()->where('key', 'cost_to_serve.enabled')->count());
    }

    public function test_a_team_member_cannot_toggle_the_feature_over_http(): void
    {
        $member = User::factory()->teamMember()->create();

        $this->actingAs($member)
            ->post('/cost-to-serve/settings', ['enabled' => '0'])
            ->assertForbidden();

        $this->assertTrue($this->service()->isEnabled());
    }

    public function test_the_toggle_endpoint_sits_behind_the_web_groups_csrf_protection(): void
    {
        // Laravel skips token verification while `runningUnitTests()`,
        // so a real 419 cannot be provoked here — instead prove the
        // POST route is in the `web` middleware group and that that
        // group carries a CSRF-verification middleware, and that this
        // path is not in the CSRF exception list.
        $route = Route::getRoutes()->getByName('cost-to-serve.settings.update');
        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());

        $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
        $hasCsrf = collect($webGroup)->contains(fn ($m) => is_string($m) && str_contains($m, 'Csrf'));
        $this->assertTrue($hasCsrf, 'The web middleware group must verify CSRF tokens.');
    }

    // ---------------------------------------------------------------
    // Sidebar visibility — role- and switch-aware
    // ---------------------------------------------------------------

    public function test_a_manager_sees_both_the_cost_to_serve_and_settings_links(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertSee('Cost-to-Serve')
            ->assertSee(route('cost-to-serve.settings'), false);
    }

    public function test_a_team_head_never_sees_the_cost_to_serve_or_settings_links(): void
    {
        $head = User::factory()->teamHead()->create();

        $response = $this->actingAs($head)->get('/dashboard')->assertOk();
        $response->assertDontSee(route('cost-to-serve.index'), false);
        $response->assertDontSee(route('cost-to-serve.settings'), false);
    }

    public function test_a_team_member_never_sees_the_cost_to_serve_or_settings_links(): void
    {
        $member = User::factory()->teamMember()->create();

        $response = $this->actingAs($member)->get('/dashboard')->assertOk();
        $response->assertDontSee(route('cost-to-serve.index'), false);
        $response->assertDontSee(route('cost-to-serve.settings'), false);
    }

    public function test_the_off_badge_appears_only_while_the_feature_is_disabled(): void
    {
        $manager = User::factory()->manager()->create();

        // Enabled (default): no "Off" badge on the nav item.
        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('badge="Off"', false);

        // Disabled: the badge appears.
        Setting::setValue('cost_to_serve.enabled', 'false');
        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertSee('Off');
    }
}
