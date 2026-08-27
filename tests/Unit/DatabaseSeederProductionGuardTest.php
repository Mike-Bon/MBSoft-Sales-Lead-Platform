<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11 STEP 5: demo/seed data must never accidentally land in
 * production (CLAUDE.md). This asserts the fail-closed guard directly,
 * rather than relying on nobody ever running `db:seed` in production.
 */
class DatabaseSeederProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_is_skipped_in_a_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new DatabaseSeeder)->setContainer($this->app)->run();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_seeding_proceeds_outside_production(): void
    {
        // The default 'testing' environment behaves like any
        // non-production environment for this guard.
        (new DatabaseSeeder)->setContainer($this->app)->run();

        $this->assertGreaterThan(0, User::count());
    }
}
