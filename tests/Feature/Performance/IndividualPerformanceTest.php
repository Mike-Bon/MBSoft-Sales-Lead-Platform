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

class IndividualPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_performance_calculates_correctly(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();

        $target = Target::factory()->individual($member)->monthly(Carbon::parse('2026-01-15'))->amount(5000)->create();

        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::ClosedWon)->create(['value' => 2500, 'closed_at' => '2026-01-10']);
        Opportunity::factory()->ownedBy($member)->stage(OpportunityStage::Qualification)->create(['value' => 1500]);
        // A teammate's opportunity must not be counted toward this
        // individual's performance, even though they share a team.
        Opportunity::factory()->ownedBy($teammate)->stage(OpportunityStage::ClosedWon)->create(['value' => 99999, 'closed_at' => '2026-01-10']);

        $snapshot = app(PerformanceService::class)->forTarget($target);

        $this->assertSame(2500.0, $snapshot->actual);
        $this->assertSame(1500.0, $snapshot->pipeline);
        $this->assertSame(50.0, $snapshot->achievementPercent);
    }
}
