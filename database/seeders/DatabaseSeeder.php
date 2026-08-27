<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Phase 11 STEP 5: every seeder called here creates development/
     * demo data — predictable emails (manager@example.test, etc.) and a
     * single shared weak password ("password", see
     * OrganisationSeeder's own docblock). CLAUDE.md requires production
     * deployment never accidentally imports this. Fails closed: refuses
     * to run in a production environment unless explicitly overridden,
     * which should essentially never be intended.
     */
    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEED_IN_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->error('Refusing to seed demo/development data into a production environment. Set ALLOW_DEMO_SEED_IN_PRODUCTION=true if this is genuinely intended (it almost never is).');

            return;
        }

        $this->call(OrganisationSeeder::class);
        $this->call(CrmSeeder::class);
        $this->call(TargetSeeder::class);
    }
}
