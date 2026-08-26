<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizationService
{
    public function __construct(private readonly CrmAssignmentService $assignments) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Organization
    {
        $assignment = $this->assignments->resolve($actor, $data['owner_id'] ?? null, $data['team_id'] ?? null);

        return DB::transaction(function () use ($data, $assignment) {
            $organization = new Organization($data);
            $organization->owner_id = $assignment['owner_id'];
            $organization->team_id = $assignment['team_id'];
            $organization->save();

            // Audit-log hook point: organization created.

            return $organization;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Organization $organization, array $data): Organization
    {
        $assignment = $this->assignments->resolve(
            $actor,
            array_key_exists('owner_id', $data) ? $data['owner_id'] : $organization->owner_id,
            array_key_exists('team_id', $data) ? $data['team_id'] : $organization->team_id,
        );

        return DB::transaction(function () use ($organization, $data, $assignment) {
            $organization->fill($data);
            $organization->owner_id = $assignment['owner_id'];
            $organization->team_id = $assignment['team_id'];
            $organization->save();

            // Audit-log hook point: organization updated/reassigned.

            return $organization;
        });
    }

    public function archive(Organization $organization): void
    {
        $organization->delete();
    }
}
