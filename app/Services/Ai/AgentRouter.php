<?php

namespace App\Services\Ai;

use App\Enums\AgentIdentifier;

/**
 * STEP 16/18/58: decides WHICH agent should handle a free-text request.
 * Deliberately a plain deterministic keyword classifier, not an LLM
 * call — routing a message to the wrong-but-still-authorized agent
 * costs a slightly less on-topic answer; spending a whole extra AI
 * round-trip just to decide which agent to ask would violate STEP 58's
 * cost-control instruction for no real safety benefit, since (STEP 18)
 * "routing is not security" — authorization is enforced identically by
 * every agent's own tools regardless of which one is picked.
 *
 * Communication intent is checked first: "draft a follow-up about the
 * stalled ABC opportunity" mentions sales vocabulary but the user
 * clearly wants the agent that can actually produce a draft.
 */
final class AgentRouter
{
    private const COMMUNICATION_KEYWORDS = [
        'draft', 'email', 'e-mail', 'whatsapp', 'message', 'send', 'follow up', 'follow-up',
        'contact him', 'contact her', 'contact them', 'reach out', 'write to', 'text ',
    ];

    private const PERFORMANCE_KEYWORDS = [
        'performance', 'target', 'achievement', 'quota', 'pace', 'behind', 'on track',
        'coverage', 'run rate', 'run-rate', 'gap', 'exceed', 'kpi',
    ];

    private const SALES_KEYWORDS = [
        'pipeline', 'opportunit', 'lead', 'deal', 'prospect', 'account', 'prioriti', 'stalled', 'stale',
    ];

    /**
     * Phase 12: checked before Performance/Sales — "cost to serve" and
     * "contribution" would otherwise also match SALES_KEYWORDS'
     * "account", landing on the wrong agent.
     */
    private const COST_TO_SERVE_KEYWORDS = [
        'cost to serve', 'cost-to-serve', 'contribution', 'expensive to serve',
        'economic yield', 'profitability', 'revenue per deal', 'arpu',
    ];

    public function route(string $message): AgentIdentifier
    {
        $lower = strtolower($message);

        if ($this->matchesAny($lower, self::COMMUNICATION_KEYWORDS)) {
            return AgentIdentifier::Communication;
        }

        if ($this->matchesAny($lower, self::COST_TO_SERVE_KEYWORDS)) {
            return AgentIdentifier::CostToServe;
        }

        $matchesPerformance = $this->matchesAny($lower, self::PERFORMANCE_KEYWORDS);
        $matchesSales = $this->matchesAny($lower, self::SALES_KEYWORDS);

        if ($matchesPerformance && ! $matchesSales) {
            return AgentIdentifier::Performance;
        }

        if ($matchesSales) {
            return AgentIdentifier::Sales;
        }

        // Ambiguous/default: Sales Intelligence is the closest analog to
        // Phase 7's original general CRM assistant (STEP 45 backward
        // compatibility) — a plain "what should I look at today?"
        // lands here.
        return AgentIdentifier::Sales;
    }

    /**
     * STEP 20/37: a genuinely cross-domain request — the application-
     * controlled Management Review sequence (Performance Agent then
     * Sales Agent) is used instead of a single agent, but ONLY when the
     * message clearly needs both, never by default (STEP 58 cost
     * control).
     */
    public function isManagementReviewRequest(string $message): bool
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'management review') || str_contains($lower, 'sales review')) {
            return true;
        }

        return $this->matchesAny($lower, self::PERFORMANCE_KEYWORDS) && $this->matchesAny($lower, self::SALES_KEYWORDS);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
