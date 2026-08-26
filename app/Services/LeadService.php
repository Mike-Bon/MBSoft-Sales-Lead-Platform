<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every write to a Lead goes through here, so status changes and
 * reassignments always leave a trace on the lead's own activity timeline
 * (STEP 6: "a lead should not silently jump between statuses without the
 * application recording the change") without needing a separate audit
 * log table.
 */
class LeadService
{
    public function __construct(
        private readonly CrmAssignmentService $assignments,
        private readonly ActivityLogger $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Lead
    {
        $assignment = $this->assignments->resolveRequiringOwner($actor, $data['owner_id'] ?? null, $data['team_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $assignment) {
            $lead = new Lead($data);
            $lead->owner_id = $assignment['owner_id'];
            $lead->team_id = $assignment['team_id'];
            $lead->save();

            $this->activities->log($actor, ActivityType::Note, [
                'lead_id' => $lead->id,
                'organization_id' => $lead->organization_id,
                'contact_id' => $lead->contact_id,
                'subject' => 'Lead created',
                'description' => "Created with status \"{$lead->status->label()}\" and priority \"{$lead->priority->label()}\".",
            ]);

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Lead $lead, array $data): Lead
    {
        $assignment = $this->assignments->resolveRequiringOwner(
            $actor,
            array_key_exists('owner_id', $data) ? $data['owner_id'] : $lead->owner_id,
            array_key_exists('team_id', $data) ? $data['team_id'] : $lead->team_id,
        );

        $previousStatus = $lead->status;
        $previousOwnerId = $lead->owner_id;

        return DB::transaction(function () use ($actor, $lead, $data, $assignment, $previousStatus, $previousOwnerId) {
            $lead->fill($data);
            $lead->owner_id = $assignment['owner_id'];
            $lead->team_id = $assignment['team_id'];
            $lead->save();

            if ($previousStatus !== $lead->status) {
                $this->activities->log($actor, ActivityType::Note, [
                    'lead_id' => $lead->id,
                    'organization_id' => $lead->organization_id,
                    'contact_id' => $lead->contact_id,
                    'subject' => 'Status changed',
                    'description' => "Status changed from \"{$previousStatus->label()}\" to \"{$lead->status->label()}\".",
                ]);
            }

            if ($previousOwnerId !== $lead->owner_id) {
                $newOwnerName = User::find($lead->owner_id)?->name ?? 'Unassigned';
                $previousOwnerName = User::find($previousOwnerId)?->name ?? 'Unassigned';

                $this->activities->log($actor, ActivityType::Note, [
                    'lead_id' => $lead->id,
                    'organization_id' => $lead->organization_id,
                    'contact_id' => $lead->contact_id,
                    'subject' => 'Reassigned',
                    'description' => "Reassigned from {$previousOwnerName} to {$newOwnerName}.",
                ]);
            }

            return $lead;
        });
    }

    public function archive(Lead $lead): void
    {
        $lead->delete();
    }
}
