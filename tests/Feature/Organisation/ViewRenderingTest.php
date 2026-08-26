<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a Blade compilation bug (@selected() breaking
 * inside a <flux:select.option> component tag) found and fixed during
 * Phase 3: these Phase 2 views used the same pattern and were never
 * actually GET-rendered by any Phase 2 test, so the bug shipped
 * undetected. See tests/Feature/Crm/ViewRenderingTest.php for the CRM
 * side of the same fix.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_create_and_edit_views_render(): void
    {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->create();

        $this->actingAs($manager)->get('/users/create')->assertOk();
        $this->actingAs($manager)->get("/users/{$target->id}/edit")->assertOk();
    }

    public function test_teams_edit_view_renders(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $this->actingAs($manager)->get("/teams/{$team->id}/edit")->assertOk();
    }
}
