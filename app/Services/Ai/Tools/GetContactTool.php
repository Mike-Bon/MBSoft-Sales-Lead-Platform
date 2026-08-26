<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Contact;
use App\Models\User;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class GetContactTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_contact',
            description: 'Retrieve full detail for one contact by id, if the authenticated user is authorized to view it. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer', 'description' => 'The contact\'s id.'],
                ],
                'required' => ['contact_id'],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $contact = Contact::with(['organization', 'owner', 'team'])->find($arguments['contact_id'] ?? null);

        if (! $contact || Gate::forUser($actor)->denies('view', $contact)) {
            throw new AuthorizationException('You are not authorized to view this contact.');
        }

        return [
            'id' => $contact->id,
            'name' => $contact->fullName(),
            'job_title' => $contact->job_title,
            'organization' => $contact->organization?->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'mobile' => $contact->mobile,
            'status' => $contact->status->label(),
            'owner' => $contact->owner?->name,
            'team' => $contact->team?->name,
        ];
    }
}
