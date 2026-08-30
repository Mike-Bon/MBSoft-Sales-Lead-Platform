<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectDiscoveryService;
use App\Support\Ai\ToolDefinition;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * V2.1: the ONLY new tool. It researches PUBLIC web sources for
 * candidate businesses matching a validated discovery request and
 * returns structured candidates with evidence + source references.
 *
 * It has, by construction:
 *   - NO CRM read/write (no Lead/Account/Opportunity/Contact/Activity),
 *   - NO Communication/send path,
 *   - NO Cost-to-Serve path,
 *   - NO raw SQL / arbitrary HTTP — the model cannot pass a URL; it
 *     passes structured criteria and ProspectDiscoveryService builds
 *     the queries and fetches deterministically behind OutboundUrlGuard.
 *
 * Authorization is re-derived from $actor here (Manager or Team Head
 * only) in addition to the agent-eligibility gate — never trusting a
 * model-supplied role.
 */
class DiscoverProspectsTool implements AgentTool
{
    public function __construct(private readonly ProspectDiscoveryService $discovery) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'discover_prospects',
            description: 'Research PUBLIC web sources (search results and public company websites) to find candidate businesses matching discovery criteria. Returns research candidates with observed facts and source references — these are NOT CRM records and nothing is created or contacted. Read-only external research. Provide at least a location, an industry, or one product keyword.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'Geographic area, e.g. "Cebu City", "Visayas, Philippines".'],
                    'industry' => ['type' => 'string', 'description' => 'Industry or business category, e.g. "apparel", "cosmetics", "motorcycle parts".'],
                    'product_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific products/categories to look for.'],
                    'online_signals' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => DiscoveryCriteria::ALLOWED_ONLINE_SIGNALS],
                        'description' => 'Which public online-selling presences the user cares about.',
                    ],
                    'exclude_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Terms to steer away from.'],
                    'max_results' => ['type' => 'integer', 'description' => 'How many candidates to return (capped by the application).'],
                ],
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
            throw new AuthorizationException('External prospect discovery is available to Managers and Team Heads only.');
        }

        $criteria = DiscoveryCriteria::fromArray(
            $arguments,
            (int) config('services.market_intelligence.max_results', 20),
        );

        return $this->discovery->discover($actor, $criteria);
    }
}
