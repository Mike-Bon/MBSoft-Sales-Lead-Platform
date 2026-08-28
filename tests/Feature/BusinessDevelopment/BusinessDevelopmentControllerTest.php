<?php

namespace Tests\Feature\BusinessDevelopment;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 13: the dedicated Business Development page — Manager and Team
 * Head only, every figure traced back to LeadIntelligenceService (never
 * a page-local recalculation), and a Team Head sees only their own
 * team's records.
 */
class BusinessDevelopmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/business-development')->assertRedirect('/login');
    }

    public function test_a_manager_can_view_the_page(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/business-development')
            ->assertOk()
            ->assertSee('Business Development')
            ->assertSee("Today's Priorities", false)
            ->assertSee('Follow-up Gaps')
            ->assertSee('At-Risk Opportunities');
    }

    public function test_a_team_head_can_view_the_page(): void
    {
        $head = User::factory()->teamHead()->create();

        $this->actingAs($head)->get('/business-development')->assertOk();
    }

    public function test_a_team_member_is_forbidden(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/business-development')->assertForbidden();
    }

    public function test_the_page_shows_prioritised_leads_with_their_reasons(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'status' => LeadStatus::Qualified,
            'next_follow_up_at' => Carbon::now()->subDays(5),
        ]);

        $this->actingAs($manager)->get('/business-development')
            ->assertOk()
            ->assertSee('Lead is Qualified')
            ->assertSee('Follow-up is overdue');
    }

    public function test_a_team_head_only_sees_their_own_teams_data_on_the_page(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        Lead::factory()->forTeam($ownTeam)->create(['status' => LeadStatus::Qualified, 'next_follow_up_at' => null]);
        $otherOrgName = 'Rival Team Prospect';
        Lead::factory()->forTeam($otherTeam)->create([
            'organization_id' => Organization::factory()->create(['name' => $otherOrgName])->id,
            'status' => LeadStatus::Qualified,
        ]);

        $this->actingAs($head)->get('/business-development')
            ->assertOk()
            ->assertDontSee($otherOrgName);
    }

    public function test_empty_states_render_when_there_is_nothing_to_show(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/business-development')
            ->assertOk()
            ->assertSee('No open leads in your scope right now.')
            ->assertSee('No at-risk opportunities in your scope.');
    }

    public function test_at_risk_opportunities_appear_on_the_page(): void
    {
        $manager = User::factory()->manager()->create();
        Opportunity::factory()->create([
            'name' => 'Dormant Expansion',
            'expected_close_date' => Carbon::now()->subWeek(),
        ]);

        $this->actingAs($manager)->get('/business-development')
            ->assertOk()
            ->assertSee('Dormant Expansion')
            ->assertSee('Past its expected close date', false);
    }
}
