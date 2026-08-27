<?php

namespace Tests\Feature\CostToServe;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 12 STEP 21: the dedicated Cost-to-Serve page — Manager/Team-Head
 * only, and every figure shown must trace back to
 * AccountEconomicsService (never a page-local recalculation).
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

    public function test_a_team_head_can_view_the_page(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)->get('/cost-to-serve')->assertOk();
    }

    public function test_a_team_member_is_forbidden(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/cost-to-serve')->assertForbidden();
    }

    public function test_the_page_shows_the_actual_calculated_revenue(): void
    {
        $manager = User::factory()->manager()->create();
        $organization = Organization::factory()->create(['name' => 'Acme Logistics']);
        Opportunity::factory()->create([
            'organization_id' => $organization->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 12345,
            'currency' => 'USD',
            'closed_at' => Carbon::now(),
        ]);

        $this->actingAs($manager)->get('/cost-to-serve')
            ->assertOk()
            ->assertSee('Acme Logistics')
            ->assertSee('12,345');
    }

    public function test_a_team_head_only_sees_their_own_teams_accounts(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $ownOrg = Organization::factory()->create(['team_id' => $ownTeam->id, 'name' => 'Own Team Account']);
        $otherOrg = Organization::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Other Team Account']);

        Opportunity::factory()->create(['organization_id' => $ownOrg->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 500, 'currency' => 'USD', 'closed_at' => Carbon::now()]);
        Opportunity::factory()->create(['organization_id' => $otherOrg->id, 'stage' => OpportunityStage::ClosedWon, 'value' => 500, 'currency' => 'USD', 'closed_at' => Carbon::now()]);

        $response = $this->actingAs($head)->get('/cost-to-serve');

        $response->assertOk()->assertSee('Own Team Account')->assertDontSee('Other Team Account');
    }
}
