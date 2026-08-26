<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt as LivewireVolt;
use Tests\TestCase;

class RoleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_authenticate(): void
    {
        $manager = User::factory()->manager()->create();

        LivewireVolt::test('auth.login')
            ->set('email', $manager->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($manager);
    }

    public function test_team_head_can_authenticate(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        LivewireVolt::test('auth.login')
            ->set('email', $head->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($head);
    }

    public function test_team_member_can_authenticate(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        LivewireVolt::test('auth.login')
            ->set('email', $member->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($member);
    }
}
