<?php

namespace Database\Seeders;

use App\Enums\TeamStatus;
use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development/demo seed data only. All names, emails, and passwords below
 * are clearly fictional placeholders — never real personal information,
 * and never intended for a production environment.
 *
 * Seeds the current business configuration (1 Manager, 3 demo teams each
 * with a Team Head and a couple of members) without hard-coding any
 * business logic around these specific accounts. The structure scales to
 * the eventual 10 Team Heads simply by adding more teams/users — nothing
 * here assumes an exact count.
 */
class OrganisationSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        $manager = User::firstOrCreate(
            ['email' => 'manager@example.test'],
            [
                'name' => 'Manager Demo',
                'password' => $password,
                'email_verified_at' => now(),
                'role' => UserRole::Manager,
                'team_id' => null,
            ]
        );

        collect(range(1, 3))->each(function (int $n) use ($password) {
            $team = Team::firstOrCreate(
                ['code' => sprintf('T%02d', $n)],
                [
                    'name' => sprintf('Team %02d', $n),
                    'status' => TeamStatus::Active,
                ]
            );

            $head = User::firstOrCreate(
                ['email' => sprintf('teamhead%02d@example.test', $n)],
                [
                    'name' => sprintf('Team Head %02d', $n),
                    'password' => $password,
                    'email_verified_at' => now(),
                    'role' => UserRole::TeamHead,
                    'team_id' => $team->id,
                ]
            );

            if ($team->team_head_id !== $head->id) {
                $team->team_head_id = $head->id;
                $team->save();
            }

            collect(['Alex', 'Bailey'])->each(function (string $firstName) use ($team, $n, $password) {
                $email = sprintf('%s.team%02d@example.test', strtolower($firstName), $n);

                User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => sprintf('%s Member (Team %02d)', $firstName, $n),
                        'password' => $password,
                        'email_verified_at' => now(),
                        'role' => UserRole::TeamMember,
                        'team_id' => $team->id,
                    ]
                );
            });
        });

        $this->command?->info('Organisation demo data seeded: 1 Manager, 3 Teams, 3 Team Heads, 6 Team Members.');
        $this->command?->info("Manager login: {$manager->email} / password");
    }
}
