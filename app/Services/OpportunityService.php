<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors LeadService: every stage change is logged to the opportunity's
 * activity timeline. CLOSED_WON/CLOSED_LOST are terminal in the sense
 * that nothing here transitions out of them automatically — moving back
 * to an open stage only ever happens because a user explicitly chose
 * that stage in the edit form and it gets logged like any other change.
 */
class OpportunityService
{
    public function __construct(
        private readonly CrmAssignmentService $assignments,
        private readonly ActivityLogger $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Opportunity
    {
        $assignment = $this->assignments->resolveRequiringOwner($actor, $data['owner_id'] ?? null, $data['team_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $assignment) {
            $opportunity = new Opportunity($data);
            $opportunity->owner_id = $assignment['owner_id'];
            $opportunity->team_id = $assignment['team_id'];
            $opportunity->save();

            $this->activities->log($actor, ActivityType::Note, [
                'opportunity_id' => $opportunity->id,
                'organization_id' => $opportunity->organization_id,
                'contact_id' => $opportunity->contact_id,
                'lead_id' => $opportunity->lead_id,
                'subject' => 'Opportunity created',
                'description' => "Created at stage \"{$opportunity->stage->label()}\".",
            ]);

            return $opportunity;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Opportunity $opportunity, array $data): Opportunity
    {
        $assignment = $this->assignments->resolveRequiringOwner(
            $actor,
            array_key_exists('owner_id', $data) ? $data['owner_id'] : $opportunity->owner_id,
            array_key_exists('team_id', $data) ? $data['team_id'] : $opportunity->team_id,
        );

        $previousStage = $opportunity->stage;
        $previousOwnerId = $opportunity->owner_id;

        return DB::transaction(function () use ($actor, $opportunity, $data, $assignment, $previousStage, $previousOwnerId) {
            $opportunity->fill($data);
            $opportunity->owner_id = $assignment['owner_id'];
            $opportunity->team_id = $assignment['team_id'];
            $opportunity->save();

            if ($previousStage !== $opportunity->stage) {
                $this->activities->log($actor, ActivityType::Note, [
                    'opportunity_id' => $opportunity->id,
                    'organization_id' => $opportunity->organization_id,
                    'contact_id' => $opportunity->contact_id,
                    'lead_id' => $opportunity->lead_id,
                    'subject' => 'Stage changed',
                    'description' => "Stage changed from \"{$previousStage->label()}\" to \"{$opportunity->stage->label()}\".",
                ]);
            }

            if ($previousOwnerId !== $opportunity->owner_id) {
                $newOwnerName = User::find($opportunity->owner_id)?->name ?? 'Unassigned';
                $previousOwnerName = User::find($previousOwnerId)?->name ?? 'Unassigned';

                $this->activities->log($actor, ActivityType::Note, [
                    'opportunity_id' => $opportunity->id,
                    'organization_id' => $opportunity->organization_id,
                    'contact_id' => $opportunity->contact_id,
                    'lead_id' => $opportunity->lead_id,
                    'subject' => 'Reassigned',
                    'description' => "Reassigned from {$previousOwnerName} to {$newOwnerName}.",
                ]);
            }

            return $opportunity;
        });
    }

    public function archive(Opportunity $opportunity): void
    {
        $opportunity->delete();
    }
}
