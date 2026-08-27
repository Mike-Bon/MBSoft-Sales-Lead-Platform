<?php

namespace App\Services;

use App\Enums\TeamStatus;
use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Owns every write to a team's identity and leadership. See
 * UserManagementService for the same rationale: one obvious place per
 * action for audit logging to hook into (Phase 11 STEP 3 closes it, for
 * createTeam/assignTeamHead — updateTeam only ever touches name/code/
 * status, which CLAUDE.md's audit list doesn't name, so it stays
 * unlogged, consistent with the original, deliberate scoping here).
 *
 * Callers are responsible for authorization (TeamPolicy, enforced via the
 * controller/Form Request) before reaching this service.
 */
class TeamManagementService
{
    /**
     * @param  array{name: string, code: ?string, status: ?TeamStatus}  $data
     */
    public function createTeam(User $actor, array $data): Team
    {
        return DB::transaction(function () use ($actor, $data) {
            $team = Team::create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'status' => $data['status'] ?? TeamStatus::Active,
            ]);

            AuditLogger::record('team.created', $actor, ['team_id' => $team->id]);

            return $team;
        });
    }

    /**
     * @param  array{name: string, code: ?string, status: ?TeamStatus}  $data
     */
    public function updateTeam(Team $team, array $data): Team
    {
        $team->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? $team->status,
        ]);

        return $team;
    }

    /**
     * Make $newHead the Team Head of $team.
     *
     * If the team already has a different head, that person is not
     * removed from the organisation — they are kept on as a regular Team
     * Member of the same team, so leadership can change without silently
     * orphaning the outgoing head. The Manager can subsequently reassign
     * them elsewhere via the normal user-management screen.
     */
    public function assignTeamHead(User $actor, Team $team, User $newHead): Team
    {
        return DB::transaction(function () use ($actor, $team, $newHead) {
            $previousHeadId = $team->team_head_id;

            if ($previousHeadId && $previousHeadId !== $newHead->id) {
                $previousHead = User::find($previousHeadId);

                if ($previousHead) {
                    $previousHead->role = UserRole::TeamMember;
                    $previousHead->save();
                }
            }

            $newHead->role = UserRole::TeamHead;
            $newHead->team_id = $team->id;
            $newHead->save();

            $team->team_head_id = $newHead->id;
            $team->save();

            AuditLogger::record('team.head_assigned', $actor, [
                'team_id' => $team->id,
                'previous_head_id' => $previousHeadId,
                'new_head_id' => $newHead->id,
            ]);

            return $team->fresh();
        });
    }
}
