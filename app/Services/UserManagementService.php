<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Owns every write to a user's organisational identity (role, team). Every
 * mutation here is deliberately funnelled through one of these two methods
 * so that future audit logging ("who changed a role/team, and when") has a
 * single, obvious place to hook in per action, rather than being scattered
 * across controllers.
 *
 * Callers are responsible for authorization (UserPolicy, enforced via the
 * controller/Form Request) before reaching this service.
 */
class UserManagementService
{
    /**
     * @param  array{name: string, email: string, password: string, role: UserRole, team_id: ?int}  $data
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
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

            // Audit-log hook point: record actor, target user id, assigned
            // role/team, and timestamp once audit logging is introduced.

            return $user;
        });
    }

    /**
     * @param  array{role: UserRole, team_id: ?int}  $data
     */
    public function updateUserRoleAndTeam(User $target, array $data): User
    {
        $newRole = $data['role'];
        $newTeamId = $newRole === UserRole::Manager ? null : $data['team_id'];

        if ($target->isManager() && $newRole !== UserRole::Manager && User::where('role', UserRole::Manager)->count() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'The organisation must always have at least one Manager.',
            ]);
        }

        return DB::transaction(function () use ($target, $newRole, $newTeamId) {
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

            // Audit-log hook point: record actor, target user id, old/new
            // role and team, and timestamp once audit logging is introduced.

            return $target->fresh();
        });
    }
}
