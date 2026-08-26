<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ActivityService
{
    public function __construct(private readonly ActivityLogger $activities) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Activity
    {
        // A user could otherwise attach an activity to any lead/opportunity/
        // contact/organization id just by guessing it, even one belonging
        // to another team. Every related record supplied must independently
        // pass its own "view" policy for the acting user.
        $this->assertCanAttachTo($actor, Organization::class, $data['organization_id'] ?? null);
        $this->assertCanAttachTo($actor, Contact::class, $data['contact_id'] ?? null);
        $this->assertCanAttachTo($actor, Lead::class, $data['lead_id'] ?? null);
        $this->assertCanAttachTo($actor, Opportunity::class, $data['opportunity_id'] ?? null);

        $type = $data['type'] instanceof ActivityType ? $data['type'] : ActivityType::from($data['type']);

        return $this->activities->log($actor, $type, [
            'organization_id' => $data['organization_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'opportunity_id' => $data['opportunity_id'] ?? null,
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);
    }

    /**
     * @param  class-string  $modelClass
     */
    private function assertCanAttachTo(User $actor, string $modelClass, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        $record = $modelClass::find($id);

        if (! $record || Gate::forUser($actor)->denies('view', $record)) {
            throw ValidationException::withMessages([
                'lead_id' => 'You are not authorized to log an activity against one of the selected records.',
            ]);
        }
    }
}
