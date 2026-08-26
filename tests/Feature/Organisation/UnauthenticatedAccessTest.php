<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnauthenticatedAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_profile(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_guest_cannot_access_teams_index(): void
    {
        $this->get('/teams')->assertRedirect('/login');
    }

    public function test_guest_cannot_access_users_index(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    public function test_guest_cannot_access_a_team_directly_by_url(): void
    {
        $team = Team::factory()->create();

        $this->get("/teams/{$team->id}")->assertRedirect('/login');
    }

    public function test_guest_cannot_post_to_management_routes(): void
    {
        $this->post('/teams', ['name' => 'Sneaky Team'])->assertRedirect('/login');
        $this->post('/users', ['name' => 'Sneaky User'])->assertRedirect('/login');
    }
}
