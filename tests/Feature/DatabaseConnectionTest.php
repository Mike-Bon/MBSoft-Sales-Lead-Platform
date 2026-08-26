<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the application can open a live PDO connection to its configured
 * database and that migrations have run, using the test environment's
 * isolated, repeatable SQLite in-memory connection (see phpunit.xml).
 *
 * This intentionally does not connect to the real Supabase Postgres
 * database — tests must not depend on live external services. Real
 * Supabase connectivity is verified manually (see project handoff notes),
 * not through the automated suite.
 */
class DatabaseConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_can_connect_to_the_configured_database(): void
    {
        $this->assertNotNull(DB::connection()->getPdo());
    }

    public function test_migrations_have_run_and_the_users_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }
}
