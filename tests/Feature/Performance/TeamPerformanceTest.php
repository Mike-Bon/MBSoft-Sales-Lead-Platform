<?php

namespace Tests\Feature\Performance;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_performance_aggregates_correctly_across_members(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $member = User::factory()->teamMember($team)->create();
        $otherTeam = Team::factory()->create();
        $outsider = User::factory()->teamMember($otherTeam)->create();

        $target = Target::factory()->team($team)->monthly(Carbon::parse('2026-01-15'))->amount(20000)->create();

        Opportunity::factory()->ownedBy($head)->stage(OpportunityStage::ClosedWon)->create(['value' => 6000, 'closed_at' => '2026-01-05']);
        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::ClosedWon)->create(['value' => 4000, 'closed_at' => '2026-01-10']);
        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::Proposal)->create(['value' => 3000]);
        // Belongs to a different team — must not be included.
        Opportunity::factory()->ownedBy($outsider)->stage(OpportunityStage::ClosedWon)->create(['value' => 99999, 'closed_at' => '2026-01-10']);

        $snapshot = app(PerformanceService::class)->forTarget($target);

        $this->assertSame(10000.0, $snapshot->actual);
        $this->assertSame(3000.0, $snapshot->pipeline);
        $this->assertSame(50.0, $snapshot->achievementPercent);
    }

    public function test_manager_can_access_all_permitted_team_performance(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        Target::factory()->team($teamA)->create();
        Target::factory()->team($teamB)->create();

        $response = $this->actingAs($manager)->get('/performance');

        $response->assertOk();
        $response->assertViewHas('teams', fn ($teams) => count($teams) === 2);
    }
}
