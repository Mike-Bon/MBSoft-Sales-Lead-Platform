<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Organization;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Phase 13 (Business Development): account-level intelligence for one
 * organisation the actor is authorised to view. The result is split
 * into KNOWN (database facts), INFERENCE (a plain rule applied to those
 * facts, labelled as such), MISSING INFORMATION, and RECOMMENDATION (a
 * suggested next step — never an action). Read-only.
 *
 * An out-of-scope organisation is reported as "not found" identically
 * to one that genuinely does not exist — an unauthorised user learns
 * nothing about which organisations exist (spec §11).
 */
class AnalyzeAccountTool implements AgentTool
{
    use ScopesCrmQueries;

    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'analyze_account',
            description: 'Summarise one authorised organisation (account): relationship type, status, open opportunities, last interaction, missing information, and a recommended next step. Read-only. Provide organization_id or organization_name.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'organization_id' => ['type' => 'integer'],
                    'organization_name' => ['type' => 'string', 'description' => 'Exact or partial organisation name; must resolve to exactly one organisation the user can see.'],
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
        $organization = $this->resolve($actor, $arguments);

        return $this->intelligence->analyzeAccount($actor, $organization);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function resolve(User $actor, array $arguments): Organization
    {
        if (isset($arguments['organization_id'])) {
            $organization = Organization::find((int) $arguments['organization_id']);

            if (! $organization || Gate::forUser($actor)->denies('view', $organization)) {
                throw new AuthorizationException('No organisation matching that reference is available to you.');
            }

            return $organization;
        }

        $name = trim((string) ($arguments['organization_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'organization_id' => 'Provide an organization_id or organization_name.',
            ]);
        }

        $matches = $this->scopeToUser(Organization::query(), $actor)
            ->where('name', 'like', "%{$name}%")
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'organization_name' => "No organisation matching \"{$name}\" is available to you.",
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'organization_name' => "More than one organisation matches \"{$name}\". Be more specific.",
            ]);
        }

        return $matches->first();
    }
}
