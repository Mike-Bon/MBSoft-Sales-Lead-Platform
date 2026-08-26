<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContactService
{
    public function __construct(private readonly CrmAssignmentService $assignments) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Contact
    {
        $assignment = $this->assignments->resolve($actor, $data['owner_id'] ?? null, $data['team_id'] ?? null);

        return DB::transaction(function () use ($data, $assignment) {
            $contact = new Contact($data);
            $contact->owner_id = $assignment['owner_id'];
            $contact->team_id = $assignment['team_id'];
            $contact->save();

            // Audit-log hook point: contact created.

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Contact $contact, array $data): Contact
    {
        $assignment = $this->assignments->resolve(
            $actor,
            array_key_exists('owner_id', $data) ? $data['owner_id'] : $contact->owner_id,
            array_key_exists('team_id', $data) ? $data['team_id'] : $contact->team_id,
        );

        return DB::transaction(function () use ($contact, $data, $assignment) {
            $contact->fill($data);
            $contact->owner_id = $assignment['owner_id'];
            $contact->team_id = $assignment['team_id'];
            $contact->save();

            // Audit-log hook point: contact updated/reassigned.

            return $contact;
        });
    }

    public function archive(Contact $contact): void
    {
        $contact->delete();
    }
}
