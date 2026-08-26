<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OpportunityStage;
use App\Enums\PeriodPreset;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every dashboard/performance screen actually renders successfully with
 * real data present (not just an empty database) — see
 * tests/Feature/Crm/ViewRenderingTest.php for why this is its own file:
 * a Blade compilation bug can hide behind a status-code-only assertion
 * if the success path is never actually GET-rendered.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_dashboard_renders_with_populated_data(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        Target::factory()->manager($manager)->create();
        Target::factory()->team($team)->create();

        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::ClosedWon)->create(['closed_at' => now()]);
        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::Proposal)->create();
        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::Negotiation)->create([
            'expected_close_date' => now()->addDays(3),
        ]);

        $this->actingAs($manager)->get('/dashboard')->assertOk();
    }

    public function test_manager_dashboard_renders_with_no_data_at_all(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/dashboard')->assertOk();
    }

    public function test_team_head_dashboard_renders_with_populated_data(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $member = User::factory()->teamMember($team)->create();

        Target::factory()->team($team)->create();
        Target::factory()->individual($member)->create();
        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::ClosedWon)->create(['closed_at' => now()]);

        $this->actingAs($head)->get('/dashboard')->assertOk();
    }

    public function test_team_head_dashboard_renders_with_no_members(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get('/dashboard')->assertOk();
    }

    public function test_team_member_dashboard_renders_with_populated_data(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        Target::factory()->individual($member)->create();
        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::ClosedWon)->create(['closed_at' => now()]);

        $this->actingAs($member)->get('/dashboard')->assertOk();
    }

    public function test_team_performance_page_renders_with_populated_data(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        Target::factory()->team($team)->create();
        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::ClosedWon)->create(['closed_at' => now()]);

        $this->actingAs($manager)->get("/teams/{$team->id}/performance")->assertOk();
    }

    public function test_dashboard_renders_for_every_period_preset_with_populated_data(): void
    {
        $manager = User::factory()->manager()->create();
        Target::factory()->manager($manager)->create();
        Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::ClosedWon)->create(['closed_at' => now()]);

        foreach (PeriodPreset::selectable() as $preset) {
            $this->actingAs($manager)->get('/dashboard?period='.$preset->value)->assertOk();
        }
    }
}
