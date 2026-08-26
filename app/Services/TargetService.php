<?php

namespace App\Services;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\UserRole;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place a Target is written. Resolves owner_id/team_id from
 * target_type server-side (STEP 6/7 — never trust which relationship a
 * client claims), and proactively prevents duplicate active targets
 * before hitting the database's partial unique index (which remains the
 * final backstop — see the RLS/constraints migration).
 */
class TargetService
{
    /**
     * @param  array<string, mixed>  $data  Validated: target_type, owner_id?, team_id?, period_type, period_start, period_end, target_amount, currency, status?, notes?
     */
    public function create(array $data): Target
    {
        [$ownerId, $teamId] = $this->resolveOwnerAndTeam($data['target_type'], $data['owner_id'] ?? null, $data['team_id'] ?? null);

        $this->assertNoDuplicateActive($data['target_type'], $ownerId, $teamId, $data['period_start'], $data['period_end']);

        return DB::transaction(function () use ($data, $ownerId, $teamId) {
            $target = new Target($data);
            $target->owner_id = $ownerId;
            $target->team_id = $teamId;
            $target->save();

            // Audit-log hook point: target created (actor, type,
            // owner/team, period, amount) once audit logging is introduced.

            return $target;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Target $target, array $data): Target
    {
        [$ownerId, $teamId] = $this->resolveOwnerAndTeam($data['target_type'], $data['owner_id'] ?? null, $data['team_id'] ?? null);

        $this->assertNoDuplicateActive(
            $data['target_type'],
            $ownerId,
            $teamId,
            $data['period_start'],
            $data['period_end'],
            ignoreTargetId: $target->id,
        );

        return DB::transaction(function () use ($target, $data, $ownerId, $teamId) {
            $target->fill($data);
            $target->owner_id = $ownerId;
            $target->team_id = $teamId;
            $target->save();

            // Audit-log hook point: target updated once audit logging is
            // introduced.

            return $target;
        });
    }

    public function deactivate(Target $target): void
    {
        $target->status = TargetStatus::Inactive;
        $target->save();
    }

    /**
     * @return array{0: ?int, 1: ?int} [owner_id, team_id]
     */
    private function resolveOwnerAndTeam(TargetType $type, ?int $requestedOwnerId, ?int $requestedTeamId): array
    {
        return match ($type) {
            TargetType::Manager => $this->resolveManager($requestedOwnerId),
            TargetType::Team => $this->resolveTeam($requestedTeamId),
            TargetType::Individual => $this->resolveIndividual($requestedOwnerId),
        };
    }

    /**
     * @return array{0: int, 1: null}
     */
    private function resolveManager(?int $requestedOwnerId): array
    {
        if ($requestedOwnerId === null) {
            throw ValidationException::withMessages(['owner_id' => 'A Manager target must have an owner.']);
        }

        $owner = User::find($requestedOwnerId);

        if (! $owner || $owner->role !== UserRole::Manager) {
            throw ValidationException::withMessages(['owner_id' => 'The selected user is not a Manager.']);
        }

        return [$owner->id, null];
    }

    /**
     * @return array{0: null, 1: int}
     */
    private function resolveTeam(?int $requestedTeamId): array
    {
        if ($requestedTeamId === null) {
            throw ValidationException::withMessages(['team_id' => 'A Team target must have a team.']);
        }

        $team = Team::find($requestedTeamId);

        if (! $team) {
            throw ValidationException::withMessages(['team_id' => 'The selected team does not exist.']);
        }

        return [null, $team->id];
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    private function resolveIndividual(?int $requestedOwnerId): array
    {
        if ($requestedOwnerId === null) {
            throw ValidationException::withMessages(['owner_id' => 'An Individual target must have an owner.']);
        }

        $owner = User::find($requestedOwnerId);

        if (! $owner) {
            throw ValidationException::withMessages(['owner_id' => 'The selected user does not exist.']);
        }

        // Denormalized from the owner's current team — see the targets
        // migration's comment on why.
        return [$owner->id, $owner->team_id];
    }

    private function assertNoDuplicateActive(
        TargetType $type,
        ?int $ownerId,
        ?int $teamId,
        mixed $periodStart,
        mixed $periodEnd,
        ?int $ignoreTargetId = null,
    ): void {
        $query = Target::query()
            ->where('target_type', $type->value)
            ->where('status', TargetStatus::Active->value)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($ignoreTargetId !== null, fn ($q) => $q->whereKeyNot($ignoreTargetId));

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'period_start' => 'An active target already exists for this owner/team, type, and period.',
            ]);
        }
    }
}
