<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectDuplicateCheckService;
use App\Support\Ai\ToolDefinition;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * V2.4: the FOURTH Market Intelligence tool, and the first with any CRM
 * reach at all. It takes the identity of one or more already-scored
 * external prospects (the `identity` block from `score_prospects`) and
 * reports, deterministically, whether each already exists in the CRM
 * records the actor is authorised to see.
 *
 * Its CRM reach is exactly one narrow READ:
 *   - a bounded, `scopeToUser`-scoped `SELECT` of identity columns from
 *     `organizations` — no notes, no communications, no economics, no
 *     opportunity values, no other team's records.
 *
 * By construction it has NO:
 *   - unrestricted CRM search (search_leads / get_lead / search_accounts),
 *   - CRM write / lead creation / assignment / status change,
 *   - Communication / send / draft path,
 *   - Cost-to-Serve path,
 *   - external web research (SearchProvider / WebEvidenceFetcher / HTTP),
 *   - SQL / arbitrary-query capability,
 *   - LLM say over which record is a duplicate — the classification is
 *     computed by ProspectDuplicateMatcher from deterministic signals.
 *
 * Authorization is re-derived from $actor (Manager or Team Head only).
 * It does NOT re-run discovery / qualification / scoring (spec §6) — it
 * consumes the identity structure directly.
 */
class CheckProspectDuplicatesTool implements AgentTool
{
    public function __construct(private readonly ProspectDuplicateCheckService $duplicates) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'check_prospect_duplicates',
            description: 'Check whether one or more already-discovered external prospects already exist in the CRM records the user is authorised to see. Pass the prospect identities (business name, website, domain, location) exactly as returned by score_prospects — this tool does NOT re-run discovery, qualification, or scoring, and never changes the prospect score. Returns, per prospect, a deterministic duplicate status (exact_duplicate / likely_duplicate / possible_duplicate / no_match) with the transparent match reasons and the matched CRM record(s). Read-only. Records outside the user\'s authorisation are never examined or revealed. Nothing is created or contacted.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'prospects' => [
                        'type' => 'array',
                        'description' => 'The prospects to check. Use the `identity` object and score fields from a previous score_prospects result.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'business' => ['type' => 'string', 'description' => 'Business / company name.'],
                                'website' => ['type' => 'string', 'description' => 'Website URL, if known.'],
                                'domain' => ['type' => 'string', 'description' => 'Registrable domain, if known.'],
                                'location' => ['type' => 'string', 'description' => 'Location, if known (supporting signal only).'],
                                'public_profiles' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Public social/business profile URLs, if any.'],
                                'total_score' => ['type' => 'integer', 'description' => 'V2.3 score — carried through unchanged, never recomputed.'],
                                'priority' => ['type' => 'string', 'description' => 'V2.3 priority band — carried through unchanged.'],
                                'qualification_outcome' => ['type' => 'string', 'description' => 'V2.2 qualification outcome — carried through unchanged.'],
                                'scoring_model' => ['type' => 'string', 'description' => 'V2.3 scoring model version — carried through unchanged.'],
                            ],
                        ],
                    ],
                ],
                'required' => ['prospects'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, array $arguments): array
    {
        if (! $actor->isManager() && ! $actor->isTeamHead()) {
            throw new AuthorizationException('CRM duplicate checking is available to Managers and Team Heads only.');
        }

        $rows = $arguments['prospects'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'prospects' => 'Provide at least one prospect identity (business name and/or website) to check.',
            ]);
        }

        $identities = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $identities[] = ProspectIdentity::fromArray($row);
            }
        }

        if ($identities === []) {
            throw ValidationException::withMessages([
                'prospects' => 'Each prospect must be an object with at least a business name or a website.',
            ]);
        }

        return $this->duplicates->check($actor, $identities, DuplicateMatchPolicy::fromConfig());
    }
}
