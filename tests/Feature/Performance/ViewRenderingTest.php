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
}
