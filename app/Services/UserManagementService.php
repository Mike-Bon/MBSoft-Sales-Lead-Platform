<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Owns every write to a user's organisational identity (role, team). Every
 * mutation here is deliberately funnelled through one of these two methods
 * so that audit logging ("who changed a role/team, and when") has a
 * single, obvious place to hook in per action, rather than being scattered
 * across controllers. Phase 11 STEP 3 closes that hook: every mutation is
 * recorded via AuditLogger before returning.
 *
 * Callers are responsible for authorization (UserPolicy, enforced via the
 * controller/Form Request) before reaching this service.
 */
class UserManagementService
{
    /**
     * @param  array{name: string, email: string, password: string, role: UserRole, team_id: ?int}  $data
     */
    public function createUser(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Set explicitly rather than via mass assignment: role/team_id
            // are intentionally excluded from User::$fillable.
            $user->role = $data['role'];
            $user->team_id = $data['role'] === UserRole::Manager ? null : $data['team_id'];
            $user->email_verified_at = now();
            $user->save();

            AuditLogger::record('user.created', $actor, [
                'target_user_id' => $user->id,
                'role' => $user->role->value,
                'team_id' => $user->team_id,
            ]);

            return $user;
        });
    }

    /**
     * @param  array{role: UserRole, team_id: ?int}  $data
     */
    public function updateUserRoleAndTeam(User $actor, User $target, array $data): User
    {
        $newRole = $data['role'];
        $newTeamId = $newRole === UserRole::Manager ? null : $data['team_id'];

        if ($target->isManager() && $newRole !== UserRole::Manager && User::where('role', UserRole::Manager)->count() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'The organisation must always have at least one Manager.',
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $newRole, $newTeamId) {
            $previousRole = $target->role;
            $previousTeamId = $target->team_id;

            // If this user currently heads a team and is being moved away
            // from it (different role, or a different team), that team
            // becomes headless until the Manager assigns a new head.
            $headedTeam = $target->headedTeam;

            if ($headedTeam && ($newRole !== UserRole::TeamHead || $headedTeam->id !== $newTeamId)) {
                $headedTeam->team_head_id = null;
                $headedTeam->save();
            }

            $target->role = $newRole;
            $target->team_id = $newTeamId;
            $target->save();

            AuditLogger::record('user.role_or_team_changed', $actor, [
                'target_user_id' => $target->id,
                'previous_role' => $previousRole->value,
                'new_role' => $newRole->value,
                'previous_team_id' => $previousTeamId,
                'new_team_id' => $newTeamId,
            ]);

            return $target->fresh();
        });
    }
}
