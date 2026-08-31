<?php

namespace Tests\Feature\CostToServe;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 12 STEP 21 / Phase 12A: the dedicated Cost-to-Serve page —
 * Manager-only (Team Head access removed in Phase 12A), only reachable
 * with the global feature switch on, and every figure shown must trace
 * back to AccountEconomicsService (never a page-local recalculation).
 * The full access matrix and every bypass vector live in
 * CostToServeAccessPolicyTest.
 */
class CostToServeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/cost-to-serve')->assertRedirect('/login');
    }

    public function test_a_manager_can_view_the_page(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/cost-to-serve')
            ->assertOk()
            ->assertSee('Cost-to-Serve')
            ->assertSee('no cost data', false);
    }

    public function test_a_team_head_is_forbidden_even_with_the_feature_on(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)->get('/cost-to-serve')->assertForbidden();
    }

    public function test_a_team_member_is_forbidden(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/cost-to-serve')->assertForbidden();
    }

    public function test_a_manager_sees_the_disabled_notice_instead_of_data_when_the_feature_is_off(): void
    {
        Setting::setValue('cost_to_serve.enabled', 'false');
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create(['name' => 'Acme Logistics']);
        Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 424242,
            'currency' => 'PHP',
            'closed_at' => Carbon::now(),
        ]);

        $this->actingAs($manager)->get('/cost-to-serve')
            ->assertOk()
            ->assertSee('currently disabled', false)
            ->assertDontSee('424,242')
            ->assertDontSee('Acme Logistics');
    }

    public function test_the_page_shows_the_actual_calculated_revenue(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create(['name' => 'Acme Logistics']);
        Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 12345,
            'currency' => 'PHP',
            'closed_at' => Carbon::now(),
        ]);

        $this->actingAs($manager)->get('/cost-to-serve')
            ->assertOk()
            ->assertSee('Acme Logistics')
            ->assertSee('12,345')
            // Money is presented in the application default currency (PHP,
            // Philippine Peso) with the peso sign — never converted.
            ->assertSee('₱12,345');
    }

    public function test_a_manager_sees_every_teams_accounts(): void
    {
        $manager = User::factory()->manager()->create();
        $orgA = Organization::factory()->create(['name' => 'Alpha Account']);
        $orgB = Organization::factory()->create(['name' => 'Bravo Account']);

        Opportunity::factory()->create(['organization_id' => $orgA->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 500, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);
        Opportunity::factory()->create(['organization_id' => $orgB->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 500, 'currency' => 'PHP', 'closed_at' => Carbon::now()]);

        $this->actingAs($manager)->get('/cost-to-serve')
            ->assertOk()
            ->assertSee('Alpha Account')
            ->assertSee('Bravo Account');
    }
}
