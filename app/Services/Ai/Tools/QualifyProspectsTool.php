<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectQualificationService;
use App\Support\Ai\ToolDefinition;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\QualificationCriterion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * V2.2: the second (and last) Market Intelligence tool. It evaluates the
 * candidate businesses a discovery request would surface against
 * explicit HARD / SUPPORTING criteria, using public evidence, and
 * returns a DETERMINISTIC non-numeric outcome per prospect
 * (strong / possible / weak / insufficient).
 *
 * Same isolation as discover_prospects, by construction:
 *   - NO CRM read/write, NO duplicate detection (that is V2.4),
 *   - NO Communication / send / draft path,
 *   - NO Cost-to-Serve path,
 *   - NO numeric lead score (that is V2.3),
 *   - NO raw SQL / arbitrary URL — the model passes structured criteria;
 *     ProspectQualificationService builds queries and fetches behind
 *     OutboundUrlGuard, within a bounded research budget.
 *
 * Authorization is re-derived from $actor here (Manager or Team Head
 * only), never from a model-supplied role.
 */
class QualifyProspectsTool implements AgentTool
{
    public function __construct(private readonly ProspectQualificationService $qualification) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'qualify_prospects',
            description: 'Evaluate the candidate businesses matching a discovery request against explicit hard and supporting criteria, using public web evidence. Returns, per business, a non-numeric qualification outcome (strong_match / possible_match / weak_match / insufficient_evidence) decided by the application, each criterion result with its supporting evidence and evidence strength, observed facts, inferences, and what is still unknown. NOT a lead score, NOT a CRM action — nothing is created or contacted. Provide at least a location, an industry, or one product keyword.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'Geographic area the businesses should be in, e.g. "Cebu City".'],
                    'industry' => ['type' => 'string', 'description' => 'Industry or business category, e.g. "cosmetics".'],
                    'product_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific products/categories to look for.'],
                    'online_signals' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => DiscoveryCriteria::ALLOWED_ONLINE_SIGNALS],
                        'description' => 'Which public online-selling presences the user cares about.',
                    ],
                    'exclude_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Terms to steer away from.'],
                    'hard_criteria' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => QualificationCriterion::KNOWN_KEYS],
                        'description' => 'Criteria that MUST be met for a strong match. Failing one prevents a strong match regardless of other signals. Defaults are derived from the discovery request (location and industry are hard by default).',
                    ],
                    'supporting_criteria' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => QualificationCriterion::KNOWN_KEYS],
                        'description' => 'Nice-to-have signals that add colour but never override a hard result.',
                    ],
                    'focus_domains' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional: restrict qualification to these domains from a previous discovery result (e.g. "abcbeauty.ph").',
                    ],
                    'max_results' => ['type' => 'integer', 'description' => 'How many businesses to qualify (capped by the application).'],
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
            throw new AuthorizationException('Prospect qualification is available to Managers and Team Heads only.');
        }

        $config = config('services.market_intelligence');

        $discoveryCriteria = DiscoveryCriteria::fromArray($arguments, (int) ($config['max_results'] ?? 20));

        $qualificationCriteria = QualificationCriteria::fromArray(
            $arguments,
            $discoveryCriteria,
            (int) ($config['max_qualification_prospects'] ?? 8),
        );

        return $this->qualification->qualify(
            $actor,
            $discoveryCriteria,
            $qualificationCriteria,
            $this->cleanDomains($arguments['focus_domains'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function cleanDomains(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $clean = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = mb_substr(trim($item), 0, 120);
            }
        }

        return array_values(array_slice(array_unique($clean), 0, 20));
    }
}
