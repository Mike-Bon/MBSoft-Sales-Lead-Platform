<?php

namespace Tests\Feature\Performance;

use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every target/performance screen actually renders successfully for a
 * Manager. See tests/Feature/Crm/ViewRenderingTest.php for why this
 * exists as its own file rather than being assumed from status-code-only
 * assertions elsewhere.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_target_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $target = Target::factory()->team($team)->create();

        $this->actingAs($manager)->get('/targets')->assertOk();
        $this->actingAs($manager)->get('/targets/create')->assertOk();
        $this->actingAs($manager)->get("/targets/{$target->id}")->assertOk();
        $this->actingAs($manager)->get("/targets/{$target->id}/edit")->assertOk();
    }

    public function test_performance_views_render(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        Target::factory()->team($team)->create();

        $this->actingAs($manager)->get('/performance')->assertOk();
        $this->actingAs($manager)->get("/performance/users/{$member->id}")->assertOk();
    }

    public function test_target_amounts_are_shown_in_pesos_by_default(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $target = Target::factory()->team($team)->amount(1000000)->create();

        // The factory default currency is the application default (PHP);
        // the list shows it with the peso sign, not a "USD" prefix.
        $this->assertSame('PHP', $target->currency);
        $this->actingAs($manager)->get('/targets')
            ->assertOk()
            ->assertSee('₱1,000,000.00')
            ->assertDontSee('USD 1,000,000');
    }
}
