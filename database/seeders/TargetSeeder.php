<?php

namespace Database\Seeders;

use App\Enums\TargetPeriodType;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Development/demo target data only. Depends on OrganisationSeeder and
 * CrmSeeder having already run. Deliberately covers the current calendar
 * month for the Manager, both demo teams, and two individuals, so
 * /performance and /targets have real, verifiable numbers to show
 * immediately after seeding — Team 02's target is intentionally sized
 * against the Won opportunity CrmSeeder already creates for it.
 */
class TargetSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::where('email', 'manager@example.test')->first();
        $team1 = Team::where('code', 'T01')->first();
        $team2 = Team::where('code', 'T02')->first();
        $head1 = User::where('email', 'teamhead01@example.test')->first();
        $member2 = User::where('email', 'alex.team02@example.test')->first();

        if (! $manager || ! $team1 || ! $team2 || ! $head1 || ! $member2) {
            $this->command?->warn('OrganisationSeeder/CrmSeeder have not run yet — skipping TargetSeeder.');

            return;
        }

        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        // owner_id/team_id are deliberately excluded from Target::$fillable
        // (see the model), so firstOrCreate()'s create-path would
        // otherwise silently drop them here too — same trap as
        // CrmSeeder, same fix.
        Model::unguarded(function () use ($manager, $team1, $team2, $head1, $member2, $start, $end) {
            $this->upsert(TargetType::Manager, $manager->id, null, $start, $end, 200000);
            $this->upsert(TargetType::Team, null, $team1->id, $start, $end, 80000);
            $this->upsert(TargetType::Team, null, $team2->id, $start, $end, 60000);
            $this->upsert(TargetType::Individual, $head1->id, $team1->id, $start, $end, 40000);
            $this->upsert(TargetType::Individual, $member2->id, $team2->id, $start, $end, 15000);
        });

        $this->command?->info('Target demo data seeded: 1 Manager, 2 Team, 2 Individual targets for the current month.');
    }

    private function upsert(TargetType $type, ?int $ownerId, ?int $teamId, Carbon $start, Carbon $end, float $amount): void
    {
        Target::firstOrCreate(
            [
                'target_type' => $type->value,
                'owner_id' => $ownerId,
                'team_id' => $teamId,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ],
            [
                'period_type' => TargetPeriodType::Monthly->value,
                'target_amount' => $amount,
                'currency' => 'USD',
                'status' => TargetStatus::Active->value,
            ],
        );
    }
}
