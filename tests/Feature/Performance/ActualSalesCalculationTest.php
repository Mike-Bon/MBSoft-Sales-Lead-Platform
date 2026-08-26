<?php

namespace Tests\Feature\Performance;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActualSalesCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PerformanceService
    {
        return app(PerformanceService::class);
    }

    public function test_closed_won_opportunity_counts_as_actual(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create([
            'value' => 5000,
            'closed_at' => Carbon::parse('2026-01-15'),
        ]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $owner->id),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(5000.0, $actual);
    }

    public function test_closed_lost_opportunity_does_not_count(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedLost)->create([
            'value' => 5000,
            'closed_at' => Carbon::parse('2026-01-15'),
        ]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $owner->id),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(0.0, $actual);
    }

    public function test_open_opportunity_does_not_count_as_actual(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Negotiation)->create(['value' => 5000]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $owner->id),
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        );

        $this->assertSame(0.0, $actual);
    }

    public function test_opportunity_closed_outside_the_target_period_does_not_count(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create([
            'value' => 5000,
            'closed_at' => Carbon::parse('2026-02-01'), // one day after the January period
        ]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $owner->id),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(0.0, $actual);
    }

    public function test_actual_is_correctly_aggregated_across_multiple_opportunities(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create(['value' => 1000, 'closed_at' => Carbon::parse('2026-01-05')]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create(['value' => 2500, 'closed_at' => Carbon::parse('2026-01-20')]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedLost)->create(['value' => 9999, 'closed_at' => Carbon::parse('2026-01-10')]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Proposal)->create(['value' => 9999]);

        $actual = $this->service()->actualSales(
            Opportunity::query()->where('owner_id', $owner->id),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(3500.0, $actual);
    }

    public function test_open_opportunities_count_toward_pipeline(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Qualification)->create(['value' => 1000]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Proposal)->create(['value' => 2000]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Negotiation)->create(['value' => 3000]);

        $pipeline = $this->service()->openPipeline(Opportunity::query()->where('owner_id', $owner->id));

        $this->assertSame(6000.0, $pipeline);
    }

    public function test_closed_won_does_not_count_toward_open_pipeline(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create(['value' => 5000, 'closed_at' => now()]);

        $pipeline = $this->service()->openPipeline(Opportunity::query()->where('owner_id', $owner->id));

        $this->assertSame(0.0, $pipeline);
    }

    public function test_closed_lost_does_not_count_toward_open_pipeline(): void
    {
        $owner = User::factory()->manager()->create();
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedLost)->create(['value' => 5000, 'closed_at' => now()]);

        $pipeline = $this->service()->openPipeline(Opportunity::query()->where('owner_id', $owner->id));

        $this->assertSame(0.0, $pipeline);
    }

    public function test_pipeline_aggregates_correctly_and_excludes_other_owners(): void
    {
        $owner = User::factory()->manager()->create();
        $otherOwner = User::factory()->manager()->create();

        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Proposal)->create(['value' => 1000]);
        Opportunity::factory()->ownedBy($otherOwner)->stage(OpportunityStage::Proposal)->create(['value' => 99999]);

        $pipeline = $this->service()->openPipeline(Opportunity::query()->where('owner_id', $owner->id));

        $this->assertSame(1000.0, $pipeline);
    }
}
