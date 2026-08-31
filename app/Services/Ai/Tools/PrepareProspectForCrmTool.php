<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectLeadProposalService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * V2.5: the FIFTH Market Intelligence tool. It PREPARES a prospect → CRM
 * lead proposal for a human to review and explicitly confirm. It is
 * PROPOSAL-ONLY and READ-ONLY with respect to the CRM:
 *
 *   - it never creates or updates a Lead or an Organization;
 *   - it persists ONE `prospect_lead_proposals` row (a "here is what
 *     would be created" structure) and returns a review URL;
 *   - the eligibility (eligible / review-required / blocked) is decided
 *     by application logic from the V2.4 duplicate-check result, never
 *     by this tool, the score, or the model;
 *   - the actual CRM write happens only when the human clicks
 *     "Create Lead" on the review page — a separate trusted HTTP route,
 *     with a fresh duplicate re-check, that this tool cannot invoke.
 *
 * There is deliberately no `confirm`, `confirmed`, `create`, `owner_id`,
 * or `team_id` parameter.
 */
class PrepareProspectForCrmTool implements AgentTool
{
    public function __construct(private readonly ProspectLeadProposalService $proposals) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'prepare_prospect_for_crm',
            description: 'Prepare a researched, qualified, scored, and duplicate-checked prospect as a CRM lead PROPOSAL for a human to review and explicitly confirm. Pass the prospect\'s entry from a previous check_prospect_duplicates result. This does NOT create a lead or an organization — it returns an eligibility decision and a review URL. If the duplicate check found an exact or likely match, or did not complete, the proposal is BLOCKED. If it found a possible match, the human must acknowledge it on the review page. Only an explicit human click on the review page creates the lead.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'duplicate_check' => [
                        'type' => 'object',
                        'description' => 'One prospect\'s entry from check_prospect_duplicates\' `checked_prospects` array — business, website, domain, check_status, duplicate_status, candidate_matches, carried_from_scoring.',
                    ],
                    'industry' => ['type' => 'string', 'description' => 'Industry / category to propose for the CRM organisation (human-editable on the review page).'],
                    'location' => ['type' => 'string', 'description' => 'Location to propose (human-editable).'],
                    'missing_information' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Known gaps from qualification, for the reviewer\'s context.'],
                    'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to a few public source URLs, recorded as bounded provenance on the lead.'],
                ],
                'required' => ['duplicate_check'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        if (! $actor->isManager() && ! $actor->isTeamHead()) {
            throw new AuthorizationException('Preparing a prospect for the CRM is available to Managers and Team Heads only.');
        }

        $duplicateCheck = is_array($arguments['duplicate_check'] ?? null) ? $arguments['duplicate_check'] : [];

        return $this->proposals->prepare($actor, $duplicateCheck, [
            'industry' => $arguments['industry'] ?? null,
            'location' => $arguments['location'] ?? null,
            'missing_information' => (array) ($arguments['missing_information'] ?? []),
            'sources' => (array) ($arguments['sources'] ?? []),
        ]);
    }
}
