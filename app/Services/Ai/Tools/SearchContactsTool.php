<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Contact;
use App\Models\User;
use App\Support\Ai\ToolDefinition;

/**
 * Mirrors ContactController::index's filters. Returns only the fields
 * needed to identify/reference a contact in conversation — not every
 * field on the record (STEP 11/22 data minimization: no unnecessary PII).
 */
class SearchContactsTool implements AgentTool
{
    use ScopesCrmQueries;

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_contacts',
            description: 'Search the authenticated user\'s own authorized contacts by name/email or organization. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Matches first name, last name, or email.'],
                    'organization_id' => ['type' => 'integer', 'description' => 'Filter to one organization.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (default 10, max 25).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $query = $this->scopeToUser(Contact::query()->with(['organization']), $actor);

        if ($search = $arguments['search'] ?? null) {
            $query->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($organizationId = $arguments['organization_id'] ?? null) {
            $query->where('organization_id', $organizationId);
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $contacts = $query->orderBy('last_name')->limit($limit)->get();

        return [
            'count' => $contacts->count(),
            'contacts' => $contacts->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->fullName(),
                'job_title' => $contact->job_title,
                'organization' => $contact->organization?->name,
                'has_email' => $contact->email !== null,
                'has_phone' => $contact->phone !== null || $contact->mobile !== null,
            ])->all(),
        ];
    }
}
