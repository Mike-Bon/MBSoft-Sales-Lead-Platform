<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Phase 13 (Business Development): the qualification-relevant CRM fields
 * that are empty for one lead or one account — "what should I find out
 * next" (spec §15/§17). Read-only. Never fabricates a value for a
 * missing field; it only names the gap.
 */
class IdentifyMissingInformationTool implements AgentTool
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'identify_missing_information',
            description: 'List the qualification-relevant CRM fields that are empty for one authorised lead or account. Read-only. Provide exactly one of lead_id or organization_id.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'lead_id' => ['type' => 'integer'],
                    'organization_id' => ['type' => 'integer'],
                ],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $leadId = $arguments['lead_id'] ?? null;
        $organizationId = $arguments['organization_id'] ?? null;

        if (($leadId === null) === ($organizationId === null)) {
            throw ValidationException::withMessages([
                'lead_id' => 'Provide exactly one of lead_id or organization_id.',
            ]);
        }

        if ($leadId !== null) {
            $lead = Lead::find((int) $leadId);

            if (! $lead || Gate::forUser($actor)->denies('view', $lead)) {
                throw new AuthorizationException('You are not authorized to view this lead.');
            }

            return $this->intelligence->missingInformation($actor, $lead);
        }

        $organization = Organization::find((int) $organizationId);

        if (! $organization || Gate::forUser($actor)->denies('view', $organization)) {
            throw new AuthorizationException('No organisation matching that reference is available to you.');
        }

        return $this->intelligence->missingInformation($actor, $organization);
    }
}
